<?php
/** Démo — vie scolaire : appel de chaque cours, absences, retards, justificatifs, discipline,
 *  retenues, infirmerie, fiches santé. Tout est COHÉRENT : un élève absent l'est à l'appel de
 *  chacun de ses cours de la demi-journée, et l'absence correspondante existe. */
declare(strict_types=1);

try {
    // 1) Qui manque quoi : épisodes d'absence par élève et par jour (demi-journée / journée / un cours)
    $absentSlots = [];   // "eleveId|Y-m-d" => [slot => episodeIndex]
    $retardDe = [];      // "eleveId|Y-m-d" => minutes (au 1er cours de la journée)
    $episodes = [];
    $motifsAbs = ['Maladie' => 46, 'Rendez-vous médical' => 18, 'Raison familiale' => 12, 'Transport' => 5, 'Compétition sportive' => 3, 'Sans motif' => 16];
    foreach ($joursClasse as $d) {
        $ymd = $d->format('Y-m-d');
        foreach ($eleves as $eid => [, , $classe, , $assid]) {
            if ($eid === $lea && $d != $schoolDay($today->modify('-6 days'))) { continue; }
            $r = mt_rand() / mt_getrandmax();
            if ($r < $assid || ($eid === $lea)) {
                $forme = $eid === $lea ? 'matin' : $weighted(['journee' => 45, 'matin' => 25, 'apresmidi' => 18, 'cours' => 12]);
                $slots = match ($forme) { 'journee' => range(0, 7), 'matin' => [0, 1, 2, 3], 'apresmidi' => [4, 5, 6, 7], default => [mt_rand(0, 7)] };
                $motif = $eid === $lea ? 'Rendez-vous médical' : $weighted($motifsAbs);
                $episodes[] = [$eid, $d, $slots, $motif, $forme];
                foreach ($slots as $s) { $absentSlots["$eid|$ymd"][$s] = count($episodes) - 1; }
            } elseif ($r < $assid + 0.012 + $assid * 0.4) {
                $retardDe["$eid|$ymd"] = $weighted([5 => 40, 10 => 30, 15 => 15, 20 => 10, 30 => 5]);
            }
        }
    }

    // 2) Appels : chaque cours passé a son appel (quelques oublis récents, dont 1 cours du professeur démo aujourd'hui)
    $wipe('appels', 'appel_eleves', 'absences', 'retards', 'justificatifs');
    $appels = []; $appelEleves = [];
    $detailDepuis = $today->modify('-84 days'); // au-delà de 12 semaines, on ne garde que les élèves non présents
    $aid = 0; $nonFaits = 0;
    $dernierCoursDemoAujourdhui = null;
    foreach ($seances as $i => [$edtId, $d, $passee]) {
        if ($d == $today && $edt[$edtId][2] === $P && $passee) { $dernierCoursDemoAujourdhui = $i; }
    }
    $premierSlotDuJour = []; // "classe|Y-m-d" => slot
    foreach ($seances as [$edtId, $d]) {
        $k = $edt[$edtId][0] . '|' . $d->format('Y-m-d');
        $premierSlotDuJour[$k] = min($premierSlotDuJour[$k] ?? 99, $edt[$edtId][4]);
    }
    foreach ($seances as $i => [$edtId, $d, $passee]) {
        if (!$passee) { continue; }
        [$classe, $code, $profId, , $slot, , $deb, $fin] = $edt[$edtId];
        $ymd = $d->format('Y-m-d');
        $recent = $d >= $today->modify('-3 days');
        if ($i === $dernierCoursDemoAujourdhui || ($recent && $profId !== $P && mt_rand(1, 100) <= 4)) { $nonFaits++; continue; }
        $aid++;
        $valide = date('Y-m-d H:i:s', strtotime("$ymd $deb") + mt_rand(3, 14) * 60);
        $appels[] = ['id' => $aid, 'etablissement_id' => $ETAB, 'edt_id' => $edtId, 'classe_id' => $classes[$classe], 'professeur_id' => $profId,
            'matiere_id' => $mat[$code], 'date_appel' => $ymd, 'heure_debut' => $deb, 'heure_fin' => $fin, 'type_appel' => 'cours',
            'statut' => $d < $today ? 'cloture' : 'valide', 'date_creation' => $valide, 'date_validation' => $valide];
        $complet = $d >= $detailDepuis;
        $estPremier = ($premierSlotDuJour["$classe|$ymd"] ?? -1) === $slot;
        foreach ($elevesParClasse[$classe] as $eid) {
            $st = 'present'; $arr = null; $dur = null;
            if (isset($absentSlots["$eid|$ymd"][$slot])) { $st = 'absent'; }
            elseif ($estPremier && isset($retardDe["$eid|$ymd"])) {
                $st = 'retard'; $dur = $retardDe["$eid|$ymd"];
                $arr = date('H:i:s', strtotime("$ymd $deb") + $dur * 60);
            }
            if ($st === 'present' && !$complet) { continue; }
            $appelEleves[] = ['appel_id' => $aid, 'eleve_id' => $eid, 'statut' => $st, 'heure_arrivee' => $arr, 'duree_retard' => $dur,
                'motif' => null, 'notifie' => $st === 'present' ? 0 : 1, 'date_modification' => $valide];
        }
    }
    $pdo->beginTransaction();
    $bulk('appels', $appels, 500);
    $nAE = $bulk('appel_eleves', $appelEleves, 1000);
    $pdo->commit();
    unset($appelEleves);
    $log(sprintf('%d appels (%d lignes élèves), %d appels encore à faire', count($appels), $nAE, $nonFaits));

    // 3) Absences (une ligne par épisode), justificatifs, retards
    $absRows = []; $justifs = []; $absId = 0;
    $creneauHeure = fn(int $s, int $col) => $creneaux[$s][$col];
    foreach ($episodes as [$eid, $d, $slots, $motif, $forme]) {
        $absId++;
        $ymd = $d->format('Y-m-d');
        $debut = "$ymd " . $creneauHeure(min($slots), 1) . ':00';
        $fin = "$ymd " . $creneauHeure(max($slots), 2) . ':00';
        $age = (int) $today->diff($d)->days;
        $justifiable = $motif !== 'Sans motif';
        $traite = $age >= 3 || $eid === $lea;
        $justifie = $justifiable && $traite && mt_rand(1, 100) <= 88;
        $statut = !$traite ? 'signalee' : ($justifie ? 'validee' : ($justifiable ? 'signalee' : 'refusee'));
        if ($eid === $lea) { $statut = 'validee'; $justifie = true; }
        $absRows[] = ['id' => $absId, 'etablissement_id' => $ETAB, 'id_eleve' => $eid, 'date_debut' => $debut, 'date_fin' => $fin,
            'type_absence' => $forme === 'journee' ? 'journee' : ($forme === 'cours' ? 'cours' : 'demi_journee'), 'motif' => $motif,
            'justifie' => $justifie ? 1 : 0, 'signale_par' => 'Appel — ' . $pick(['M1', 'M3', 'S1']), 'statut' => $statut,
            'validated_by' => $traite ? $pick([$cpeId, $cpe2Id]) : null, 'validated_at' => $traite ? $d->modify('+' . mt_rand(1, 3) . ' days')->format('Y-m-d 10:15:00') : null,
            'date_signalement' => $debut, 'created_by' => $cpeId];
        if ($justifiable && ($justifie || mt_rand(1, 100) <= 40)) {
            $justifs[] = ['etablissement_id' => $ETAB, 'id_eleve' => $eid, 'date_soumission' => $d->modify('+' . mt_rand(0, 2) . ' days')->format('Y-m-d'),
                'date_debut_absence' => $ymd, 'date_fin_absence' => $ymd, 'type' => $motif === 'Maladie' || $motif === 'Rendez-vous médical' ? 'medical' : 'familial',
                'motif' => $motif, 'description' => $motif === 'Maladie' ? 'Fièvre, gardé(e) à la maison.' : ($motif === 'Rendez-vous médical' ? 'Rendez-vous chez le spécialiste.' : 'Événement familial.'),
                'soumis_par' => 'Parent', 'id_absence' => $absId, 'traite' => $justifie ? 1 : 0, 'approuve' => $justifie ? 1 : 0,
                'date_traitement' => $justifie ? $d->modify('+' . mt_rand(1, 3) . ' days')->format('Y-m-d 10:20:00') : null, 'traite_par' => $justifie ? 'Sophie Rivière' : null];
        }
    }
    $retRows = [];
    foreach ($retardDe as $k => $min) {
        [$eid, $ymd] = explode('|', $k);
        $d = new DateTimeImmutable($ymd);
        $classe = $eleves[(int) $eid][2];
        $slot = $premierSlotDuJour["$classe|$ymd"] ?? 0;
        if (!$heurePassee($d, $creneaux[$slot][1])) { continue; }
        $retRows[] = ['etablissement_id' => $ETAB, 'id_eleve' => (int) $eid, 'date_retard' => "$ymd " . $creneaux[$slot][1] . ':00', 'duree_minutes' => $min,
            'motif' => $pick(['Bus en retard', 'Panne de réveil', 'Rendez-vous', 'Embouteillages', null, null]), 'justifie' => mt_rand(1, 100) <= 35 ? 1 : 0,
            'signale_par' => 'Vie scolaire', 'date_signalement' => "$ymd " . $creneaux[$slot][1] . ':00'];
    }
    $pdo->beginTransaction();
    $bulk('absences', $absRows); $bulk('justificatifs', $justifs); $bulk('retards', $retRows);
    $pdo->commit();
    $log(sprintf('%d absences, %d justificatifs, %d retards', count($absRows), count($justifs), count($retRows)));

    // 4) Discipline : incidents (quelques élèves récidivistes), sanctions, retenues du mercredi
    $wipe('incidents', 'sanctions', 'retenues', 'retenue_eleves', 'discipline_points');
    $turbulents = array_rand(array_flip($elevesIds), 26);
    $typesInc = ['non_respect' => 30, 'insolence' => 20, 'retard_repete' => 12, 'violence' => 8, 'degradation' => 6, 'fraude' => 8, 'harcelement' => 3, 'autre' => 13];
    $descInc = [
        'non_respect' => ['Téléphone portable utilisé en classe malgré plusieurs rappels.', 'Refus de se mettre au travail et de sortir ses affaires.', 'Chewing-gum et casquette en classe après avertissement.', 'Sortie de classe sans autorisation.'],
        'insolence' => ['Propos irrespectueux envers le professeur devant la classe.', 'Répond de manière insolente à l\'assistant d\'éducation.', 'Refus d\'obtempérer et haussement de ton.'],
        'retard_repete' => ['Cinquième retard non justifié depuis le début du mois.', 'Arrive régulièrement après la sonnerie de 13 h 30.'],
        'violence' => ['Bousculade violente dans l\'escalier B, un élève est tombé.', 'Bagarre dans la cour lors de la récréation de 10 h.'],
        'degradation' => ['Table taguée au feutre indélébile.', 'Porte-manteau arraché dans le couloir du bâtiment A.'],
        'fraude' => ['Antisèche découverte pendant le contrôle.', 'Copie quasi identique à celle du voisin.'],
        'harcelement' => ['Moqueries répétées envers un camarade signalées par plusieurs élèves.'],
        'autre' => ['Bavardages incessants perturbant le cours.', 'Oubli répété du matériel et du carnet de liaison.', 'Jet de projectiles en classe.'],
    ];
    $lieux = ['Salle 104', 'Salle 201', 'Cour', 'Escalier B', 'Cantine', 'Gymnase', 'Couloir du 1er étage', 'CDI', 'Salle 207', 'Labo SVT 1'];
    $incidents = 0; $sanct = 0;
    $retenuesMercredi = []; // Y-m-d => id
    $nbIncidents = (int) round($nbEleves * count($joursClasse) * 0.0042);
    for ($k = 0; $k < $nbIncidents; $k++) {
        $eid = mt_rand(1, 100) <= 70 ? $pick($turbulents) : $pick($elevesIds);
        if ($eid === $lea) { continue; }
        $d = $pick($joursClasse);
        $type = $weighted($typesInc);
        $grav = match ($type) { 'violence', 'harcelement' => $pick(['moyen', 'grave', 'grave']), 'degradation', 'fraude', 'insolence' => $pick(['mineur', 'moyen', 'moyen']), default => $pick(['mineur', 'mineur', 'moyen']) };
        $classe = $eleves[$eid][2];
        $signaleur = mt_rand(1, 100) <= 65 ? [array_values($profDeClasse[$classe])[mt_rand(0, count($profDeClasse[$classe]) - 1)], 'professeur'] : [$pick(array_merge($aedIds, [$cpeId])), 'vie_scolaire'];
        $age = (int) $today->diff($d)->days;
        $statut = $age > 7 ? 'traite' : ($age > 2 ? $pick(['en_traitement', 'traite']) : 'signale');
        $h = sprintf('%02d:%02d:00', $pick([8, 9, 10, 11, 12, 13, 14, 15, 16]), mt_rand(0, 59));
        $iid = $ins('incidents', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'date_incident' => $d->format('Y-m-d') . " $h", 'lieu' => $pick($lieux),
            'type_incident' => $type, 'gravite' => $grav, 'description' => $pick($descInc[$type]), 'temoins' => mt_rand(1, 100) <= 40 ? 'Élèves de la classe' : null,
            'signale_par_id' => $signaleur[0], 'signale_par_type' => $signaleur[1], 'classe_id' => $classes[$classe], 'statut' => $statut,
            'traite_par_id' => $statut === 'traite' ? $cpeId : null, 'traite_at' => $statut === 'traite' ? $d->modify('+1 day')->format('Y-m-d 11:00:00') : null,
            'commentaire_traitement' => $statut === 'traite' ? $pick(['Entretien avec l\'élève et rappel au règlement.', 'Famille contactée par téléphone.', 'Sanction prononcée, famille informée.']) : null,
            'date_creation' => $d->format('Y-m-d') . " $h"]);
        $incidents++;
        if ($grav !== 'mineur' && $statut !== 'signale') {
            $type_s = match ($grav) { 'grave' => $pick(['exclusion_temporaire', 'exclusion_cours', 'blame']), default => $pick(['retenue', 'retenue', 'avertissement', 'travail_interet']) };
            $dateS = $d->modify('+1 day');
            $sid = $ins('sanctions', ['etablissement_id' => $ETAB, 'incident_id' => $iid, 'eleve_id' => $eid, 'type_sanction' => $type_s,
                'motif' => $pick($descInc[$type]), 'date_sanction' => $dateS->format('Y-m-d'),
                'date_debut' => $type_s === 'exclusion_temporaire' ? $nextSchoolDay($dateS->modify('+3 days'))->format('Y-m-d 08:00:00') : null,
                'date_fin' => $type_s === 'exclusion_temporaire' ? $nextSchoolDay($dateS->modify('+4 days'))->format('Y-m-d 17:00:00') : null,
                'duree' => match ($type_s) { 'retenue' => '2 heures', 'exclusion_temporaire' => '2 jours', 'exclusion_cours' => '1 heure', default => null },
                'lieu_retenue' => $type_s === 'retenue' ? 'Permanence' : null, 'convocation_parent' => $grav === 'grave' ? 1 : 0,
                'parent_notifie' => 1, 'decide_par_id' => $grav === 'grave' ? $adminId : $cpeId, 'decide_par_type' => $grav === 'grave' ? 'administrateur' : 'vie_scolaire',
                'statut' => $dateS < $today->modify('-5 days') ? 'executee' : 'prononcee', 'date_creation' => $dateS->format('Y-m-d 09:00:00')]);
            $sanct++;
            if ($type_s === 'retenue') {
                $mer = $dateS;
                while ((int) $mer->format('N') !== 3) { $mer = $mer->modify('+1 day'); }
                $key = $mer->format('Y-m-d');
                if (!isset($retenuesMercredi[$key])) {
                    $retenuesMercredi[$key] = $ins('retenues', ['etablissement_id' => $ETAB, 'date_retenue' => $key, 'heure_debut' => '13:30:00', 'heure_fin' => '15:30:00',
                        'lieu' => 'Permanence', 'surveillant_id' => $pick($aedIds), 'surveillant_type' => 'vie_scolaire', 'capacite_max' => 20,
                        'statut' => $mer < $today ? 'terminee' : 'planifiee', 'date_creation' => $dateS->format('Y-m-d 09:05:00')]);
                }
                $ins('retenue_eleves', ['retenue_id' => $retenuesMercredi[$key], 'sanction_id' => $sid, 'eleve_id' => $eid, 'present' => $mer < $today ? (mt_rand(1, 100) <= 90 ? 1 : 0) : null]);
            }
        }
    }
    $log("$incidents incidents, $sanct sanctions, " . count($retenuesMercredi) . ' séances de retenue');

    // 5) Infirmerie : fiches santé, allergies, protocoles, passages (~4 par jour)
    $wipe('fiches_sante', 'eleve_allergies', 'protocoles_urgence', 'passages_infirmerie');
    $allergenes = ['Arachide' => 'high', 'Fruits à coque' => 'high', 'Lait' => 'medium', 'Gluten' => 'medium', 'Œuf' => 'medium', 'Poisson' => 'medium', 'Pollen' => 'low', 'Pénicilline' => 'high'];
    $fiches = []; $allerg = [];
    foreach ($eleves as $eid => [$p, $n, $classe]) {
        $a = mt_rand(1, 100) <= 9 ? array_rand($allergenes) : null;
        $pai = $a && $allergenes[$a] === 'high' ? 'PAI allergie alimentaire — trousse d\'urgence (adrénaline) à l\'infirmerie.' : (mt_rand(1, 100) <= 3 ? 'PAI asthme — ventoline dans le cartable.' : null);
        $par = $parentsDe[$eid][0] ?? null;
        $fiches[] = ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'allergies' => $a, 'traitements' => $pai && str_contains($pai, 'asthme') ? 'Ventoline si besoin' : null,
            'medecin_traitant' => 'Dr ' . $pick(['Morel', 'Haddad', 'Lucas', 'Perrin', 'Garnier']), 'telephone_urgence' => '06 ' . sprintf('%02d %02d %02d %02d', mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99)),
            'contact_urgence' => $par ? ($parentName[$par] ?? '') : '', 'groupe_sanguin' => $pick(['A+', 'O+', 'B+', 'A-', 'O-', 'AB+']), 'pai' => $pai ? 'oui' : 'non', 'pai_details' => $pai,
            'vaccinations' => 'À jour (DTP, ROR)', 'date_modification' => $rentree->modify('+' . mt_rand(0, 20) . ' days')->format('Y-m-d 10:00:00')];
        if ($a) { $allerg[] = ['eleve_id' => $eid, 'allergene' => $a, 'severity' => $allergenes[$a], 'etablissement_id' => $ETAB]; }
    }
    $bulk('fiches_sante', $fiches); $bulk('eleve_allergies', $allerg);
    foreach ([['Choc anaphylactique', 'Allergie sévère', "1. Allonger l'élève, jambes surélevées.\n2. Injecter l'adrénaline (stylo du PAI).\n3. Appeler le 15.\n4. Prévenir la famille."],
              ['Crise d\'asthme', 'Asthme', "1. Asseoir l'élève, le rassurer.\n2. Ventoline : 2 bouffées, renouveler après 5 min si besoin.\n3. Appeler le 15 si pas d'amélioration."],
              ['Malaise / hypoglycémie', 'Diabète', "1. Resucrer (3 morceaux de sucre).\n2. Contrôler la glycémie après 15 min.\n3. Prévenir la famille."],
              ['Traumatisme crânien', 'Chute', "1. Ne pas déplacer si perte de connaissance.\n2. Surveiller vomissements, somnolence.\n3. Appeler le 15 en cas de doute."]] as [$nom, $path, $cat]) {
        $ins('protocoles_urgence', ['etablissement_id' => $ETAB, 'nom' => $nom, 'pathologie' => $path, 'conduite_a_tenir' => $cat]);
    }
    $motifsInf = ['Maux de tête' => 22, 'Mal de ventre' => 20, 'Chute en EPS' => 10, 'Nausées' => 8, 'Saignement de nez' => 6, 'Fatigue' => 10, 'Coupure légère' => 6, 'Douleurs menstruelles' => 8, 'Malaise' => 4, 'Écoute / mal-être' => 6];
    $soins = ['Maux de tête' => ['Repos 20 min, eau', 'Paracétamol (autorisation parentale)'], 'Mal de ventre' => ['Bouillotte, repos', 'Repos 15 min'], 'Chute en EPS' => ['Glace, bande de contention', 'Désinfection, pansement'],
        'Nausées' => ['Repos allongé', 'Eau sucrée'], 'Saignement de nez' => ['Compression 10 min'], 'Fatigue' => ['Repos 20 min'], 'Coupure légère' => ['Désinfection, pansement'],
        'Douleurs menstruelles' => ['Bouillotte, repos'], 'Malaise' => ['Resucrage, surveillance'], 'Écoute / mal-être' => ['Entretien d\'écoute, suivi proposé']];
    $passages = [];
    foreach ($joursClasse as $d) {
        $n = mt_rand(2, 6);
        for ($k = 0; $k < $n; $k++) {
            $h = sprintf('%02d:%02d', $pick([8, 9, 10, 10, 11, 13, 14, 15, 16]), mt_rand(0, 59));
            if (!$heurePassee($d, $h)) { continue; }
            $motif = $weighted($motifsInf);
            $orient = $weighted(['retour_classe' => 74, 'repos' => 12, 'domicile' => 11, 'urgences' => 1, 'autre' => 2]);
            $passages[] = ['etablissement_id' => $ETAB, 'eleve_id' => $pick($elevesIds), 'date_passage' => $d->format('Y-m-d') . " $h:00", 'motif' => $motif,
                'symptomes' => $motif, 'soins_prodigues' => $pick($soins[$motif]), 'orientation' => $orient, 'parent_prevenu' => in_array($orient, ['domicile', 'urgences'], true) ? 1 : 0,
                'heure_sortie' => date('H:i:s', strtotime($d->format('Y-m-d') . " $h") + mt_rand(10, 45) * 60), 'infirmier_id' => $infId,
                'observations' => $orient === 'domicile' ? 'Famille venue chercher l\'élève.' : null, 'date_creation' => $d->format('Y-m-d') . " $h:00"];
        }
    }
    $bulk('passages_infirmerie', $passages);
    $log(count($passages) . ' passages à l\'infirmerie, ' . count($allerg) . ' allergies déclarées');
} catch (\Throwable $e) { $fail('vie scolaire', $e); }
