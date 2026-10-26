<?php
/** Démo — réunions (refonte 4.6) : tout ce qui n'est pas un cours, à tous les stades
 *  (planifiées avec convocations, en cours, terminées avec compte rendu). */
declare(strict_types=1);

try {
    if (!$hasTable('reunion_participants')) {
        $m = dirname(__DIR__, 2) . '/database/migrations/2026_10_26_000002_reunions_refonte.php';
        if (is_file($m)) { (require $m)->up($pdo); $colsCache = []; }
    }
    $wipe('reunion_reservations', 'reunion_creneaux', 'reunion_points', 'reunion_participants', 'reunions');
    $nbR = 0;
    $salleId = static fn(string $nom) => (int) (array_search($nom, $salleNom, true) ?: 0) ?: null;
    $reunion = function (array $d, array $participants, array $points, string $etat = 'planifiee', ?string $cr = null) use ($ins, $bulk, $ETAB, $NOW, &$nbR): int {
        $deb = new DateTimeImmutable($d['date_debut']); $fin = new DateTimeImmutable($d['date_fin']);
        $id = $ins('reunions', $d + ['etablissement_id' => $ETAB, 'statut' => $etat, 'pv_contenu' => $cr, 'cr_publie' => $cr ? 1 : 0,
            'started_at' => $etat !== 'planifiee' ? $deb->modify('+' . mt_rand(0, 6) . ' minutes')->format('Y-m-d H:i:s') : null,
            'ended_at' => $etat === 'terminee' ? $fin->modify('-' . mt_rand(0, 15) . ' minutes')->format('Y-m-d H:i:s') : null,
            'date_creation' => $deb->modify('-' . mt_rand(8, 20) . ' days')->format('Y-m-d 18:00:00')]);
        $rows = [];
        foreach ($participants as [$type, $uid, $role]) {
            if (!$uid) { continue; }
            $conv = $role === 'organisateur' ? 'confirmee' : ($etat === 'planifiee' ? (mt_rand(1, 100) <= 62 ? 'confirmee' : (mt_rand(1, 100) <= 85 ? 'envoyee' : 'excusee')) : (mt_rand(1, 100) <= 92 ? 'confirmee' : 'excusee'));
            $pres = $etat === 'planifiee' ? null : ($conv === 'excusee' ? 'excuse' : (mt_rand(1, 100) <= 93 ? 'present' : 'retard'));
            $rows["$type:$uid"] = ['reunion_id' => $id, 'user_id' => $uid, 'user_type' => $type, 'role' => $role, 'convocation' => $conv, 'presence' => $pres,
                'arrive_at' => $pres === 'present' || $pres === 'retard' ? $deb->format('Y-m-d H:i:s') : null];
        }
        $bulk('reunion_participants', array_values($rows));
        $o = 0; $pts = [];
        foreach ($points as $p) {
            [$titre, $duree] = $p; $notes = $p[2] ?? null; $dec = $p[3] ?? null;
            $o++;
            $st = $etat === 'terminee' ? 'traite' : 'a_traiter';
            if ($etat === 'en_cours') { $st = $o < 3 ? 'traite' : ($o === 3 ? 'en_cours' : 'a_traiter'); }
            $pts[] = ['reunion_id' => $id, 'ordre' => $o, 'titre' => $titre, 'duree_minutes' => $duree, 'statut' => $st, 'notes' => $st === 'traite' ? $notes : null, 'decision' => $st === 'traite' ? $dec : null,
                'debut_at' => $st === 'en_cours' ? $NOW->modify('-6 minutes')->format('Y-m-d H:i:s') : null];
        }
        $bulk('reunion_points', $pts);
        if ($etat === 'en_cours') {
            $cur = $GLOBALS['pdo']->query("SELECT id FROM reunion_points WHERE reunion_id = $id AND statut = 'en_cours'")->fetchColumn();
            $GLOBALS['pdo']->prepare('UPDATE reunions SET point_courant = ? WHERE id = ?')->execute([$cur ?: null, $id]);
        }
        $nbR++;
        return $id;
    };
    $at = fn(int $jours, string $hm) => $nextSchoolDay($today->modify(($jours >= 0 ? '+' : '') . $jours . ' days'))->format('Y-m-d') . " $hm:00";
    $atPast = fn(int $jours, string $hm) => $schoolDay($today->modify("-$jours days"))->format('Y-m-d') . " $hm:00";
    $plus = fn(string $dt, int $min) => (new DateTimeImmutable($dt))->modify("+$min minutes")->format('Y-m-d H:i:s');
    $familleDe = fn(int $eid) => array_map(fn($p) => ['parent', $p, 'famille'], $parentsDe[$eid] ?? []);
    $classeIdDe = fn(int $eid) => $classes[$eleves[$eid][2]];

    // ── 1. Rencontres parents-professeurs du 1er trimestre (toutes classes, créneaux de 10 min) ──
    $soir = $at(10, '16:30');
    $ppId = $reunion(['titre' => 'Rencontres parents-professeurs — 1er trimestre', 'description' => "Chaque professeur reçoit les familles dans sa salle habituelle.\nRéservez un créneau de 10 minutes avec les professeurs de vos enfants.",
        'type' => 'parents_profs', 'date_debut' => $soir, 'date_fin' => $plus($soir, 210), 'lieu' => 'Bâtiments A et B', 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur', 'duree_creneau' => 10],
        array_merge([['administrateur', $adminId, 'organisateur']], array_map(fn($pid) => ['professeur', $pid, 'professeur'], array_keys($classesDuProf))), []);
    $salleProf = [];
    foreach ($edt as [$c, $code, $pid, , , $sid]) { $salleProf[$pid][$sid] = ($salleProf[$pid][$sid] ?? 0) + 1; }
    $creneauRows = []; $cid = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM reunion_creneaux')->fetchColumn();
    $creneauxProf = [];
    foreach (array_keys($classesDuProf) as $pid) {
        arsort($salleProf[$pid]); $sn = $salleNom[array_key_first($salleProf[$pid])] ?? null;
        for ($t = strtotime('2000-01-01 16:30'); $t + 600 <= strtotime('2000-01-01 20:00'); $t += 600) {
            $cid++;
            $creneauRows[] = ['id' => $cid, 'reunion_id' => $ppId, 'professeur_id' => $pid, 'heure_debut' => date('H:i:s', $t), 'heure_fin' => date('H:i:s', $t + 600), 'duree_minutes' => 10, 'salle' => $sn];
            $creneauxProf[$pid][] = [$cid, date('H:i:s', $t)];
        }
    }
    $bulk('reunion_creneaux', $creneauRows);
    // Réservations : la famille démo a déjà 3 rendez-vous (Léa : maths + français, Hugo : professeur principal)
    $resa = []; $pris = []; $occupe = [];
    $book = function (int $pid, int $parent, int $eid, ?string $heure = null, ?string $com = null) use (&$resa, &$pris, &$occupe, $creneauxProf): bool {
        foreach ($creneauxProf[$pid] ?? [] as [$c, $h]) {
            if (isset($pris[$c]) || isset($occupe["$parent|$h"]) || ($heure && $h !== $heure)) { continue; }
            $pris[$c] = true; $occupe["$parent|$h"] = true;
            $resa[] = ['creneau_id' => $c, 'parent_id' => $parent, 'eleve_id' => $eid, 'statut' => 'confirmee', 'commentaire' => $com, 'date_reservation' => date('Y-m-d H:i:s', time() - mt_rand(3600, 86400 * 5))];
            return true;
        }
        return false;
    };
    $book($P, $parentDemo, $lea, '17:00:00', 'Comment l\'aider à gagner en confiance en géométrie ?');
    $book($profDeClasse['4A']['FRA'], $parentDemo, $lea, '17:20:00');
    $book($ppDe['6B'], $parentDemo, $hugo, '18:00:00', 'Organisation et oublis de matériel.');
    foreach ($elevesIds as $eid) {
        if ($eid === $lea || $eid === $hugo || mt_rand(1, 100) > 34) { continue; }
        $par = $parentsDe[$eid][0];
        $profsEleve = array_values(array_unique(array_values($profDeClasse[$eleves[$eid][2]])));
        shuffle($profsEleve);
        $n = $eleves[$eid][3] < 10 ? 4 : mt_rand(1, 3);
        foreach (array_slice($profsEleve, 0, $n) as $pid) { $book($pid, $par, $eid, null, mt_rand(1, 100) <= 15 ? $pick(['Point sur les résultats.', 'Orientation.', 'Comportement en classe.', 'Méthodes de travail.']) : null); }
    }
    $bulk('reunion_reservations', $resa, 500);

    // ── 2. Conseils de classe du 1er trimestre (planifiés, liés aux sessions du module Conseils) ──
    $sess = $pdo->query("SELECT id, classe_id, date_conseil FROM conseil_classe_sessions WHERE periode_id = " . (int) ($periodeIds[1] ?? 0))->fetchAll(PDO::FETCH_ASSOC);
    $agendaConseil = [['Bilan général de la classe (professeur principal)', 10], ['Parole aux délégués des élèves', 5], ['Parole aux délégués des parents', 5], ['Examen des situations individuelles', 45], ['Synthèse et décisions', 10]];
    foreach ($sess as $s) {
        $c = (string) $s['classe_id'];
        if (!isset($classes[$c])) { continue; }
        $part = [['administrateur', in_array($c[1] ?? '', ['A', 'C'], true) ? $adminId : $adjointId, 'president'], ['professeur', $ppDe[$c], 'secretaire'], ['vie_scolaire', $cpeId, 'cpe']];
        foreach (array_unique(array_values($profDeClasse[$c])) as $pid) { if ($pid !== $ppDe[$c]) { $part[] = ['professeur', $pid, 'professeur']; } }
        $part[] = ['eleve', $elevesParClasse[$c][1], 'delegue_eleve'];
        $part[] = ['parent', $parentsDe[$elevesParClasse[$c][0]][0], 'delegue_parent'];
        $dt = (string) $s['date_conseil'];
        $etat = (new DateTimeImmutable($dt)) < $NOW ? 'terminee' : 'planifiee';
        $rid = $reunion(['titre' => "Conseil de classe — $c", 'description' => "Conseil du 1er trimestre de la $c. Saisissez vos appréciations avant la séance.", 'type' => 'conseil_classe', 'date_debut' => $dt, 'date_fin' => $plus($dt, 75),
            'salle_id' => $salleId('Salle de réunion'), 'classe_id' => $classes[$c], 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur', 'conseil_session_id' => (int) $s['id'], 'periode_id' => $periodeIds[1] ?? null], $part, $agendaConseil, $etat);
    }

    // ── 3. Discipline : conseil de discipline (passé, avec compte rendu) et commission éducative (passée) ──
    $cas = array_values(array_filter($turbulents, fn($e) => $e !== $lea && $e !== $hugo));
    $e1 = $cas[0]; $e2 = $cas[1]; $e3 = $cas[2]; $e4 = $cas[3];
    $n1 = $eleves[$e1][0] . ' ' . $eleves[$e1][1];
    $d1 = $atPast(19, '17:00');
    $reunion(['titre' => "Conseil de discipline — $n1", 'description' => 'Faits de violence répétés dans la cour (rapport de la CPE joint au dossier).', 'type' => 'conseil_discipline', 'date_debut' => $d1, 'date_fin' => $plus($d1, 90),
        'salle_id' => $salleId('Salle de réunion'), 'eleve_id' => $e1, 'classe_id' => $classeIdDe($e1), 'confidentiel' => 1, 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        array_merge([['administrateur', $adminId, 'president'], ['administrateur', $adjointId, 'membre'], ['vie_scolaire', $cpeId, 'cpe'], ['professeur', $ppDe[$eleves[$e1][2]], 'professeur'], ['eleve', $e1, 'eleve']], $familleDe($e1)),
        [['Ouverture de la séance, rappel des droits de la défense', 5, 'Quorum atteint, l\'élève est assisté de sa mère.'], ['Lecture du rapport et des faits reprochés', 15, 'Trois altercations en quatre semaines, dont une ayant blessé un camarade.'],
         ['Audition de l\'élève et de ses responsables', 20, 'L\'élève reconnaît les faits et exprime des regrets. La famille demande un accompagnement.'], ['Audition des témoins', 15, 'Deux AED confirment les faits.'],
         ['Délibération et vote', 25, 'Vote à bulletin secret : 7 pour, 1 contre.', 'Exclusion temporaire de 3 jours, assortie d\'un sursis total, et mesure de responsabilisation au CDI (5 h).'],
         ['Notification de la décision', 10, 'Décision notifiée oralement, courrier recommandé envoyé le jour même.']], 'terminee',
        "CONSEIL DE DISCIPLINE — $n1\nPrésidé par Claire Delmas, principale.\n\nDécision : exclusion temporaire de 3 jours avec sursis total ; mesure de responsabilisation de 5 heures au CDI.\nVote : 7 pour, 1 contre.\nUn suivi hebdomadaire avec la CPE est mis en place pour le reste du trimestre.");
    $n2 = $eleves[$e2][0] . ' ' . $eleves[$e2][1];
    $d2 = $atPast(8, '16:45');
    $reunion(['titre' => "Commission éducative — $n2", 'description' => 'Insolences répétées et refus de travail en classe.', 'type' => 'commission_educative', 'date_debut' => $d2, 'date_fin' => $plus($d2, 60),
        'salle_id' => $salleId('Salle de réunion'), 'eleve_id' => $e2, 'classe_id' => $classeIdDe($e2), 'confidentiel' => 1, 'organisateur_id' => $cpeId, 'organisateur_type' => 'vie_scolaire'],
        array_merge([['vie_scolaire', $cpeId, 'organisateur'], ['administrateur', $adjointId, 'president'], ['professeur', $ppDe[$eleves[$e2][2]], 'professeur'], ['eleve', $e2, 'eleve']], $familleDe($e2)),
        [['Présentation de la situation', 10, 'Six signalements depuis la rentrée, surtout en fin de journée.'], ['Échange avec l\'élève et sa famille', 20, 'L\'élève évoque des difficultés de sommeil et un manque de confiance.'],
         ['Mesures et engagements', 20, 'Fiche de suivi quotidienne signée par chaque professeur.', 'Fiche de suivi pendant 4 semaines ; rendez-vous avec l\'infirmière scolaire.'], ['Modalités de suivi', 10, 'Point d\'étape dans un mois.']], 'terminee',
        "COMMISSION ÉDUCATIVE — $n2\nEngagements : fiche de suivi quotidienne pendant 4 semaines, rendez-vous avec l'infirmière scolaire, point d'étape dans un mois avec la famille.");
    // À venir : commission éducative (jeudi), conseil de discipline (convocations envoyées)
    $n3 = $eleves[$e3][0] . ' ' . $eleves[$e3][1];
    $d3 = $at(3, '16:45');
    $reunion(['titre' => "Commission éducative — $n3", 'description' => 'Comportement en classe et retards répétés.', 'type' => 'commission_educative', 'date_debut' => $d3, 'date_fin' => $plus($d3, 60),
        'salle_id' => $salleId('Salle de réunion'), 'eleve_id' => $e3, 'classe_id' => $classeIdDe($e3), 'confidentiel' => 1, 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        array_merge([['administrateur', $adminId, 'president'], ['vie_scolaire', $cpeId, 'cpe'], ['professeur', $ppDe[$eleves[$e3][2]], 'professeur'], ['vie_scolaire', $infId, 'sante'], ['eleve', $e3, 'eleve']], $familleDe($e3)),
        [['Présentation de la situation', 10], ['Échange avec l\'élève et sa famille', 20], ['Mesures et engagements', 20], ['Modalités de suivi', 10]]);
    $n4 = $eleves[$e4][0] . ' ' . $eleves[$e4][1];
    $d4 = $at(6, '17:30');
    $reunion(['titre' => "Conseil de discipline — $n4", 'description' => 'Dégradation volontaire de matériel et menaces envers un camarade.', 'type' => 'conseil_discipline', 'date_debut' => $d4, 'date_fin' => $plus($d4, 90),
        'salle_id' => $salleId('Salle de réunion'), 'eleve_id' => $e4, 'classe_id' => $classeIdDe($e4), 'confidentiel' => 1, 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        array_merge([['administrateur', $adminId, 'president'], ['administrateur', $adjointId, 'membre'], ['vie_scolaire', $cpeId, 'cpe'], ['professeur', $ppDe[$eleves[$e4][2]], 'professeur'], ['professeur', $P, 'temoin'], ['eleve', $e4, 'eleve']], $familleDe($e4)),
        [['Ouverture de la séance, rappel des droits de la défense', 5], ['Lecture du rapport et des faits reprochés', 15], ['Audition de l\'élève et de ses responsables', 20], ['Audition des témoins', 15], ['Délibération et vote', 25], ['Notification de la décision', 10]]);

    // ── 4. Équipe éducative (ESS) pour un élève suivi en PPS ──
    $pps = (int) $pdo->query("SELECT eleve_id FROM plans_accompagnement WHERE type_plan = 'PPS' ORDER BY id LIMIT 1")->fetchColumn() ?: $pick($elevesIds);
    $n5 = $eleves[$pps][0] . ' ' . $eleves[$pps][1];
    $d5 = $at(9, '16:30');
    $reunion(['titre' => "Équipe de suivi de scolarisation — $n5", 'description' => 'Bilan des aménagements du PPS et de l\'accompagnement AESH.', 'type' => 'equipe_educative', 'date_debut' => $d5, 'date_fin' => $plus($d5, 60),
        'salle_id' => $salleId('Salle de réunion'), 'eleve_id' => $pps, 'classe_id' => $classeIdDe($pps), 'confidentiel' => 1, 'organisateur_id' => $cpeId, 'organisateur_type' => 'vie_scolaire'],
        array_merge([['vie_scolaire', $cpeId, 'organisateur'], ['administrateur', $adjointId, 'president'], ['professeur', $ppDe[$eleves[$pps][2]], 'professeur'], ['vie_scolaire', $infId, 'sante']], $familleDe($pps)),
        [['Point sur la scolarité et les difficultés', 15], ['Avis des professionnels (santé, AESH, psy-EN)', 15], ['Aménagements proposés', 20], ['Calendrier de suivi', 10]]);

    // ── 5. Instances et équipes ──
    $tousPP = array_map(fn($pid) => ['professeur', $pid, 'membre'], array_values(array_unique(array_values($ppDe))));
    $d6 = $atPast(12, '17:30');
    $reunion(['titre' => 'Conseil pédagogique — progression des 4e', 'description' => 'Point d\'étape sur les progressions communes et le soutien en calcul littéral.', 'type' => 'conseil_pedagogique', 'date_debut' => $d6, 'date_fin' => $plus($d6, 90),
        'salle_id' => $salleId('Salle de réunion'), 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        array_merge([['administrateur', $adminId, 'president'], ['vie_scolaire', $cpeId, 'membre']], $tousPP, [['professeur', $P, 'secretaire']]),
        [['Point d\'actualité', 10, 'Calendrier des conseils et des rencontres parents-professeurs validé.'], ['Progressions et évaluations communes', 30, 'Les 4B ont deux semaines de retard en calcul littéral.', 'Atelier de soutien le mardi en Devoirs faits (M. Lefort).'],
         ['Projets pédagogiques', 30, 'Projet théâtre des 4A et concours de robotique présentés.', 'Projet théâtre validé ; budget FSE de 300 €.'], ['Questions diverses', 20, 'Rappel : saisie des appréciations une semaine avant les conseils.']], 'terminee',
        "CONSEIL PÉDAGOGIQUE — progression des 4e\n\nDécisions :\n- Atelier de soutien en calcul littéral le mardi (Devoirs faits), animé par M. Lefort.\n- Projet théâtre des 4A validé, 300 € du FSE.\n- Appréciations saisies au plus tard une semaine avant les conseils de classe.");
    $d7 = $at(20, '17:30');
    $reunion(['titre' => 'Conseil pédagogique — évaluation par compétences', 'type' => 'conseil_pedagogique', 'date_debut' => $d7, 'date_fin' => $plus($d7, 90), 'salle_id' => $salleId('Salle de réunion'), 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        array_merge([['administrateur', $adminId, 'president']], $tousPP), [['Point d\'actualité', 10], ['Harmoniser l\'évaluation du socle', 40], ['Préparation du brevet blanc', 30], ['Questions diverses', 10]]);
    $d8 = $atPast(33, '18:00');
    $reunion(['titre' => 'Conseil d\'administration n° 1', 'type' => 'conseil_administration', 'date_debut' => $d8, 'date_fin' => $plus($d8, 120), 'salle_id' => $salleId('Salle polyvalente'), 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        [['administrateur', $adminId, 'president'], ['administrateur', $gestId, 'secretaire'], ['administrateur', $adjointId, 'membre'], ['vie_scolaire', $cpeId, 'membre'], ['professeur', $ppDe['3A'], 'membre'], ['professeur', $ppDe['5B'], 'membre'], ['parent', $parentDemo, 'delegue_parent']],
        [['Approbation du procès-verbal précédent', 5, 'Approuvé à l\'unanimité.'], ['Budget et décisions modificatives', 40, 'Décision modificative n° 1 (crédits numériques).', 'Adoptée : 22 pour, 0 contre.'],
         ['Voyages et sorties', 25, 'Voyage en Angleterre des 3e (avril).', 'Voyage adopté ; participation des familles plafonnée à 280 €.'], ['Règlement intérieur', 30, 'Ajout d\'un article sur l\'usage du téléphone.', 'Adopté.'], ['Questions diverses', 20]], 'terminee',
        "CONSEIL D'ADMINISTRATION n° 1\nDécisions : DM n° 1 adoptée ; voyage en Angleterre des 3e adopté (participation plafonnée à 280 €) ; modification du règlement intérieur adoptée.");
    $d9 = $at(28, '18:00');
    $reunion(['titre' => 'Conseil d\'administration n° 2 — budget', 'type' => 'conseil_administration', 'date_debut' => $d9, 'date_fin' => $plus($d9, 120), 'salle_id' => $salleId('Salle polyvalente'), 'organisateur_id' => $adminId, 'organisateur_type' => 'administrateur'],
        [['administrateur', $adminId, 'president'], ['administrateur', $gestId, 'secretaire'], ['administrateur', $adjointId, 'membre'], ['parent', $parentDemo, 'delegue_parent']],
        [['Approbation du procès-verbal précédent', 5], ['Budget de l\'année prochaine', 60], ['Tarifs de la demi-pension', 25], ['Questions diverses', 20]]);
    // Réunion d'équipe : EN COURS pendant les heures de cours (le professeur démo l'anime), sinon demain
    $enCours = (int) $NOW->format('G') >= 8 && (int) $NOW->format('G') < 19 && (int) $NOW->format('N') <= 5;
    $d10 = $enCours ? $NOW->modify('-22 minutes')->format('Y-m-d H:i:00') : $at(1, '17:00');
    $reunion(['titre' => 'Équipe pédagogique 4A — projet théâtre', 'description' => 'Organisation du projet théâtre et des répétitions.', 'type' => 'reunion_equipe', 'date_debut' => $d10, 'date_fin' => $plus($d10, 60),
        'salle_id' => $salleId('Salle des professeurs'), 'classe_id' => $classes['4A'], 'organisateur_id' => $P, 'organisateur_type' => 'professeur'],
        array_merge([['professeur', $P, 'organisateur']], array_map(fn($pid) => ['professeur', $pid, 'membre'], array_values(array_diff(array_unique(array_values($profDeClasse['4A'])), [$P]))), [['vie_scolaire', $cpeId, 'cpe']]),
        [['Tour de table', 10, 'Toute l\'équipe est partante.'], ['Calendrier des répétitions', 15, 'Jeudi midi en salle polyvalente, à partir de la rentrée de novembre.', 'Répétitions le jeudi de 12 h 30 à 13 h 25.'], ['Répartition des rôles et des textes', 20], ['Budget et costumes', 10], ['Prochaines étapes', 5]], $enCours ? 'en_cours' : 'planifiee');

    // ── 6. Entretiens avec les familles ──
    $d11 = $atPast(16, '17:30');
    $reunion(['titre' => 'Entretien — Hugo Martin (6B)', 'description' => 'Oublis de matériel répétés.', 'type' => 'entretien', 'date_debut' => $d11, 'date_fin' => $plus($d11, 30), 'salle_id' => $salleId('Salle 104'), 'eleve_id' => $hugo, 'classe_id' => $classes['6B'], 'confidentiel' => 1,
        'organisateur_id' => $ppDe['6B'], 'organisateur_type' => 'professeur'],
        [['professeur', $ppDe['6B'], 'organisateur'], ['parent', $parentDemo, 'famille']],
        [['Motif de la rencontre', 10, 'Hugo oublie souvent son matériel de français.'], ['Échange', 15, 'Mise en place d\'une check-list le soir avec sa mère.'], ['Suite donnée', 5, '', 'Point d\'étape dans quinze jours par messagerie.']], 'terminee',
        "ENTRETIEN — Hugo Martin (6B)\nUne check-list est mise en place le soir à la maison. Point d'étape dans quinze jours par messagerie.");
    $fam4A = $elevesParClasse['4A'][3];
    $d12 = $at(2, '17:00');
    $reunion(['titre' => 'Entretien — ' . $eleves[$fam4A][0] . ' ' . $eleves[$fam4A][1] . ' (4A)', 'description' => 'Baisse des résultats en mathématiques.', 'type' => 'entretien', 'date_debut' => $d12, 'date_fin' => $plus($d12, 30), 'salle_id' => $salleId('Salle 202'),
        'eleve_id' => $fam4A, 'classe_id' => $classes['4A'], 'confidentiel' => 1, 'organisateur_id' => $P, 'organisateur_type' => 'professeur'],
        array_merge([['professeur', $P, 'organisateur']], $familleDe($fam4A)), [['Motif de la rencontre', 10], ['Échange', 15], ['Suite donnée', 5]]);
    // Une réunion annulée (pour voir l'état)
    $d13 = $at(4, '12:30');
    $reunion(['titre' => 'Réunion des professeurs principaux de 3e', 'type' => 'reunion_equipe', 'date_debut' => $d13, 'date_fin' => $plus($d13, 45), 'salle_id' => $salleId('Salle de réunion'), 'organisateur_id' => $adjointId, 'organisateur_type' => 'administrateur'],
        [['administrateur', $adjointId, 'organisateur'], ['professeur', $ppDe['3A'], 'membre'], ['professeur', $ppDe['3B'], 'membre'], ['professeur', $ppDe['3C'], 'membre'], ['professeur', $ppDe['3D'], 'membre']], [['Stage d\'observation', 25], ['Forum des métiers', 20]], 'annulee');

    $log("$nbR réunions (dont " . count($sess) . ' conseils de classe), ' . count($creneauRows) . ' créneaux parents-professeurs, ' . count($resa) . ' réservés');
} catch (\Throwable $e) { $fail('réunions', $e); }
