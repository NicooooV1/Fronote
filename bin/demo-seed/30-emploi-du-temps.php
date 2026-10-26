<?php
/** Démo — emploi du temps (glouton, sans conflit prof/classe/salle, mercredi après-midi libre)
 *  puis la liste de TOUS les cours tenus depuis la rentrée ($seances), base de l'appel, des notes
 *  et du cahier de textes. */
declare(strict_types=1);

$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'];
$busyProf = []; $busyClasse = []; $busySalle = [];
$edt = [];        // id => [classe, code, profId, jour, slot, salleId]
$nonPlaces = 0;
$validDebut = $rentree->format('Y-m-d'); $validFin = $finAnnee->format('Y-m-d');
$edtRows = [];
// Ordre : les classes du compte démo d'abord (emploi du temps du professeur démo bien rempli)
$ordreClasses = array_keys($classesNoms);
usort($ordreClasses, fn($a, $b) => (in_array($b, ['4A', '4B', '3A', '3B'], true) <=> in_array($a, ['4A', '4B', '3A', '3B'], true)) ?: strcmp($a, $b));
foreach ($ordreClasses as $classe) {
    $niv = $classesNoms[$classe];
    $besoins = [];
    foreach ($matDef as $code => $def) {
        $h = $def[3][$niveauIdx[$niv]];
        $bloc = $code === 'EPS' ? 2 : 1;
        for ($k = 0; $k < intdiv($h, $bloc); $k++) { $besoins[] = [$code, $bloc]; }
        if ($h % $bloc) { $besoins[] = [$code, 1]; }
    }
    usort($besoins, static fn($a, $b) => $b[1] <=> $a[1]); // blocs d'abord
    $parJour = array_fill_keys($jours, []);
    foreach ($besoins as [$code, $bloc]) {
        $profId = $profDeClasse[$classe][$code] ?? null;
        if (!$profId) { continue; }
        $placed = false;
        $ordreJours = $jours;
        usort($ordreJours, static fn($a, $b) => count($parJour[$a]) <=> count($parJour[$b]) ?: mt_rand(-1, 1));
        foreach ([false, true] as $permissif) {
            foreach ($ordreJours as $jour) {
                if (!$permissif && in_array($code, $parJour[$jour], true) && $code !== 'FRA' && $code !== 'MAT') { continue; }
                $maxSlot = $jour === 'mercredi' ? 4 : count($creneaux);
                $slots = range(0, $maxSlot - $bloc);
                usort($slots, static fn($a, $b) => (($a >= 6) <=> ($b >= 6)) ?: mt_rand(-1, 1)); // éviter la fin de journée
                foreach ($slots as $s) {
                    if ($bloc === 2 && ($s === 3 || $s === 1)) { continue; } // pas de bloc à cheval sur une pause
                    $ok = true;
                    for ($b = 0; $b < $bloc; $b++) {
                        if (isset($busyProf[$profId][$jour][$s + $b]) || isset($busyClasse[$classe][$jour][$s + $b])) { $ok = false; break; }
                    }
                    if (!$ok) { continue; }
                    $salleType = isset($salles[$code]) ? $code : 'std';
                    $salle = null;
                    foreach ($salles[$salleType] as $cand) {
                        $libre = true;
                        for ($b = 0; $b < $bloc; $b++) { if (isset($busySalle[$cand][$jour][$s + $b])) { $libre = false; } }
                        if ($libre && ($code === 'EPS' || true)) { $salle = $cand; break; }
                    }
                    if ($salle === null && $code !== 'EPS') { continue; }
                    $salle ??= $salles['EPS'][0];
                    for ($b = 0; $b < $bloc; $b++) {
                        $busyProf[$profId][$jour][$s + $b] = true; $busyClasse[$classe][$jour][$s + $b] = true;
                        if ($code !== 'EPS') { $busySalle[$salle][$jour][$s + $b] = true; }
                        [$cid, $deb, $fin] = $creneaux[$s + $b];
                        $edtRows[] = [$classe, $code, $profId, $jour, $s + $b, $salle, $cid, $deb, $fin];
                    }
                    $parJour[$jour][] = $code; $placed = true; break;
                }
                if ($placed) { break; }
            }
            if ($placed) { break; }
        }
        if (!$placed) { $nonPlaces++; }
    }
}
foreach ($edtRows as [$classe, $code, $profId, $jour, $slot, $salle, $cid, $deb, $fin]) {
    $id = $ins('emploi_du_temps', ['etablissement_id' => $ETAB, 'classe_id' => $classes[$classe], 'matiere_id' => $mat[$code], 'professeur_id' => $profId,
        'salle_id' => $salle, 'jour' => $jour, 'creneau_id' => $cid, 'heure_debut' => $deb, 'heure_fin' => $fin, 'type_cours' => 'cours',
        'recurrence' => 'hebdomadaire', 'date_debut_validite' => $validDebut, 'date_fin_validite' => $validFin, 'couleur' => $matDef[$code][2], 'actif' => 1]);
    $edt[$id] = [$classe, $code, $profId, $jour, $slot, $salle, $deb, $fin];
}
$log(count($edt) . ' cours hebdomadaires' . ($nonPlaces ? " ($nonPlaces non placés)" : ''));

// Séances tenues depuis la rentrée : [edtId, date(DateTimeImmutable), passée?]
$edtParJour = [];
foreach ($edt as $id => $e) { $edtParJour[$e[3]][] = $id; }
$seances = [];
$seancesParClasseMatiere = []; // "classe|code" => [index de $seances]
foreach ($joursClasse as $d) {
    $jn = $jourNom[(int) $d->format('N')];
    foreach ($edtParJour[$jn] ?? [] as $id) {
        $e = $edt[$id];
        $passee = $heurePassee($d, $e[7]);
        $seances[] = [$id, $d, $passee];
        $seancesParClasseMatiere[$e[0] . '|' . $e[1]][] = count($seances) - 1;
    }
}
// Quelques cours modifiés (absence de professeur, changement de salle) — visibles dans l'emploi du temps
$edtModifs = [];
foreach (array_rand($edt, min(14, count($edt))) as $k => $id) {
    $d = $nextSchoolDay($today->modify('+' . mt_rand(-10, 12) . ' days'));
    $jn = $jourNom[(int) $d->format('N')];
    if ($edt[$id][3] !== $jn) { $d = $d->modify('+' . ((array_search($edt[$id][3], $jours) - array_search($jn, $jours) + 7) % 7) . ' days'); }
    $type = $k % 3 === 0 ? 'deplacement' : 'annulation';
    $edtModifs[] = ['edt_id' => $id, 'date_cours' => $d->format('Y-m-d'), 'type_modification' => $type,
        'nouvelle_salle_id' => $type === 'deplacement' ? $pick($salles['std']) : null,
        'motif' => $type === 'annulation' ? $pick(['Professeur absent', 'Sortie pédagogique', 'Formation du professeur', 'Conseil de classe']) : 'Salle indisponible',
        'createur_id' => $adminId, 'createur_type' => 'administrateur', 'date_creation' => $NOW->modify('-' . mt_rand(1, 9) . ' days')->format('Y-m-d H:i:s')];
}
if ($hasTable('edt_modifications')) { $bulk('edt_modifications', $edtModifs); }
$log(count($seances) . ' séances depuis la rentrée');
