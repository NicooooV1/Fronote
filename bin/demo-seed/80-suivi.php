<?php
/** Démo — suivi des élèves : orientation, stages, plans d'accompagnement, AESH, bourses,
 *  parcours éducatifs, demandes au support interne, archives des années précédentes,
 *  indicateurs de décrochage, mesure d'audience. */
declare(strict_types=1);

try {
    // ── Orientation (3e) : fiches, vœux, entretiens et rendez-vous avec la psy-EN ──
    $wipe('orientation_fiches', 'orientation_voeux', 'orientation_entretiens', 'orientation_rdv');
    $projets = [['Seconde générale et technologique', 'Lycée Jean-Moulin', 'Générale', 'Poursuivre vers des études scientifiques.'],
        ['Seconde générale et technologique', 'Lycée Victor-Duruy', 'Générale', 'Intérêt pour les langues et l\'histoire.'],
        ['CAP Cuisine', 'Lycée hôtelier de Val-Fleuri', 'Professionnelle', 'Passionné(e) de cuisine depuis l\'enfance.'],
        ['Bac pro Métiers de l\'électricité', 'Lycée professionnel Eiffel', 'Professionnelle', 'Aime le concret et le travail manuel.'],
        ['Seconde STHR', 'Lycée hôtelier de Val-Fleuri', 'Technologique', 'Projet dans l\'hôtellerie-restauration.'],
        ['Bac pro Soins, services aux personnes', 'Lycée professionnel Pasteur', 'Professionnelle', 'Souhaite travailler auprès des personnes âgées.']];
    $nbF = 0;
    foreach (['3A', '3B', '3C', '3D'] as $c) {
        foreach ($elevesParClasse[$c] as $eid) {
            $lvl = $eleves[$eid][3];
            $pro = $lvl < 10 ? $pick([2, 3, 5]) : ($lvl < 12 && $chance(0.3) ? $pick([3, 4]) : $pick([0, 1, 4]));
            $statut = $chance(0.6) ? 'brouillon' : 'soumise';
            $fid = $ins('orientation_fiches', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'annee_scolaire' => $annee, 'classe' => $c, 'projet_professionnel' => $projets[$pro][3],
                'centres_interet' => $pick(['Sport, jeux vidéo', 'Lecture, dessin', 'Musique, cuisine', 'Mécanique, bricolage', 'Animaux, nature', 'Informatique, robotique']),
                'points_forts' => $lvl >= 13 ? 'Travail régulier, bonne autonomie.' : 'Motivation dans les matières pratiques.', 'points_amelioration' => $lvl >= 13 ? 'Prendre davantage la parole.' : 'Méthodes de travail, régularité.',
                'commentaire_pp' => $statut === 'soumise' ? 'Projet cohérent, à affiner au 2e trimestre.' : null, 'avis_pp' => $statut === 'soumise' ? 'favorable' : 'en_attente', 'avis_conseil' => 'en_attente', 'statut' => $statut,
                'date_creation' => $today->modify('-' . mt_rand(5, 30) . ' days')->format('Y-m-d 18:00:00')]);
            foreach ([$pro, ($pro + 1) % count($projets)] as $r => $pj) {
                $ins('orientation_voeux', ['etablissement_id' => $ETAB, 'fiche_id' => $fid, 'rang' => $r + 1, 'intitule' => $projets[$pj][0], 'etablissement_vise' => $projets[$pj][1], 'filiere' => $projets[$pj][2],
                    'motivation' => $projets[$pj][3], 'avis_pp' => 'en_attente', 'avis_conseil' => 'en_attente']);
            }
            if ($chance(0.25)) {
                $d = $nextSchoolDay($today->modify('+' . mt_rand(-20, 20) . ' days'));
                $ins('orientation_entretiens', ['eleve_id' => $eid, 'pp_id' => $ppDe[$c], 'date_entretien' => $d->format('Y-m-d'), 'motif' => 'Entretien d\'orientation', 'statut' => $d < $today ? 'realise' : 'planifie',
                    'compte_rendu' => $d < $today ? 'Échange constructif, l\'élève a un projet clair.' : null, 'recommandations' => $d < $today ? 'Participer au forum des métiers.' : null]);
            }
            if ($chance(0.12)) {
                $d = $nextSchoolDay($today->modify('+' . mt_rand(1, 21) . ' days'));
                $ins('orientation_rdv', ['eleve_id' => $eid, 'date_rdv' => $d->format('Y-m-d'), 'heure_rdv' => $pick(['10:05:00', '13:30:00', '15:35:00']), 'motif' => 'Rendez-vous avec la psychologue de l\'Éducation nationale', 'statut' => 'planifie']);
            }
            $nbF++;
        }
    }
    // ── Stages d'observation de 3e (semaine de décembre) ──
    $wipe('stages', 'stages_visites', 'stage_journal');
    $entreprises = [['Boulangerie du Centre', '12 rue de la Paix, Val-Fleuri', 'Mme Roche'], ['Garage Martin Auto', 'ZA des Peupliers', 'M. Gaillard'], ['Mairie de Val-Fleuri', 'Place de la Mairie', 'Mme Lacroix'],
        ['Clinique vétérinaire des Lilas', '4 rue des Lilas', 'Dr Fontaine'], ['Agence Pixel & Co', '18 boulevard Hugo', 'M. Nguyen'], ['Pharmacie de la Gare', '1 place de la Gare', 'Mme Perrin'],
        ['Hôpital de Val-Fleuri', 'Avenue de la Santé', 'Mme Dumont'], ['Restaurant Le Moulin', 'Hameau du Moulin', 'M. Colin'], ['Cabinet d\'architectes Bourgeois', '7 rue des Tanneurs', 'Mme Bourgeois']];
    $sem = $nextSchoolDay(new DateTimeImmutable("$startYear-12-07"));
    $nbS = 0;
    foreach (['3A', '3B', '3C', '3D'] as $c) {
        foreach ($elevesParClasse[$c] as $eid) {
            if (!$chance(0.82)) { continue; } // les autres cherchent encore
            [$ent, $adr, $tut] = $pick($entreprises);
            $fin = $sem->modify('+4 days');
            $st = $fin < $today ? 'termine' : ($sem <= $today ? 'en_cours' : $pick(['valide', 'valide', 'soumis', 'brouillon']));
            $sid = $ins('stages', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'type' => 'stage', 'entreprise_nom' => $ent, 'entreprise_adresse' => $adr, 'entreprise_contact' => '01 00 00 00 ' . sprintf('%02d', mt_rand(20, 99)),
                'tuteur_nom' => $tut, 'tuteur_email' => strtolower(preg_replace('/[^a-z]/i', '', $tut)) . '@entreprise.demo', 'professeur_referent_id' => $ppDe[$c], 'sujet' => 'Stage d\'observation en milieu professionnel',
                'description' => 'Découverte des métiers de l\'entreprise.', 'date_debut' => $sem->format('Y-m-d'), 'date_fin' => $fin->format('Y-m-d'), 'statut' => $st,
                'evaluation_note' => $st === 'termine' ? mt_rand(12, 19) : null, 'created_at' => $today->modify('-' . mt_rand(3, 25) . ' days')->format('Y-m-d 18:00:00')]);
            if ($st === 'termine') {
                $ins('stage_journal', ['stage_id' => $sid, 'semaine' => 1, 'contenu' => 'J\'ai découvert l\'accueil des clients et l\'organisation de l\'équipe.', 'created_at' => $fin->format('Y-m-d 18:00:00')]);
                if ($chance(0.3)) { $ins('stages_visites', ['stage_id' => $sid, 'professeur_id' => $ppDe[$c], 'date_visite' => $sem->modify('+2 days')->format('Y-m-d'), 'commentaire' => 'Élève bien intégré(e), tuteur satisfait.', 'etablissement_id' => $ETAB]); }
            }
            $nbS++;
        }
    }
    $log("orientation : $nbF fiches de 3e ; $nbS conventions de stage");
} catch (\Throwable $e) { $fail('orientation / stages', $e); }

try {
    // ── Besoins particuliers : plans d'accompagnement, aménagements, AESH ──
    $wipe('plans_accompagnement', 'accessibilite_amenagements', 'accessibilite_aesh', 'accessibilite_aesh_affectations');
    $plans = [['PAP', 'Troubles des apprentissages (dyslexie)', 'Temps majoré d\'un tiers, documents agrandis, pas de pénalité pour l\'orthographe.'],
        ['PPS', 'Trouble du spectre autistique', 'Accompagnement AESH, place au calme, emploi du temps visuel.'],
        ['PPRE', 'Difficultés en mathématiques', 'Soutien hebdomadaire, objectifs intermédiaires, point mensuel.'],
        ['PAI', 'Allergie alimentaire sévère', 'Trousse d\'urgence, protocole connu des adultes, menu adapté.'],
        ['PAP', 'Trouble de l\'attention (TDAH)', 'Consignes fractionnées, pauses autorisées, placement devant.']];
    $aeshIds = [];
    foreach ([['Nadège', 'Collin', 'individuel'], ['Romain', 'Petit', 'mutualise']] as [$p, $n, $type]) {
        $aeshIds[] = $ins('accessibilite_aesh', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'email' => strtolower($p) . '.' . strtolower($n) . '@victor-hugo.demo', 'type_contrat' => $type, 'heures_hebdo' => 24,
            'date_debut_contrat' => $rentree->format('Y-m-d'), 'date_fin_contrat' => $finAnnee->format('Y-m-d'), 'statut' => 'actif', 'actif' => 1, 'created_at' => $rentree->format('Y-m-d 09:00:00')]);
    }
    $nbP = 0;
    foreach (array_rand(array_flip($elevesIds), 32) as $k => $eid) {
        [$type, $intit, $amen] = $plans[$k % count($plans)];
        $pid = $ins('plans_accompagnement', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'type_plan' => $type, 'intitule' => $intit, 'description' => $intit . '.', 'amenagements' => $amen,
            'objectifs' => 'Permettre à l\'élève de suivre sa scolarité dans les meilleures conditions.', 'responsable_id' => $ppDe[$eleves[$eid][2]], 'responsable_type' => 'professeur', 'createur_id' => $cpeId,
            'date_debut' => $rentree->modify('+' . mt_rand(5, 30) . ' days')->format('Y-m-d'), 'date_fin' => $finAnnee->format('Y-m-d'), 'statut' => 'actif', 'created_at' => $rentree->modify('+5 days')->format('Y-m-d 10:00:00')]);
        $ins('accessibilite_amenagements', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'plan_id' => $pid, 'type_amenagement' => $type === 'PAP' ? 'Temps majoré' : ($type === 'PPS' ? 'Accompagnement humain' : 'Adaptation pédagogique'),
            'description' => $amen, 'applicable_examens' => in_array($type, ['PAP', 'PPS'], true) ? 1 : 0, 'date_debut' => $rentree->format('Y-m-d'), 'statut' => 'actif', 'notifie_professeurs' => 1, 'prescripteur' => $type === 'PPS' ? 'MDPH' : 'Médecin scolaire']);
        if ($type === 'PPS') { $ins('accessibilite_aesh_affectations', ['aesh_id' => $aeshIds[$k % 2], 'eleve_id' => $eid, 'heures_hebdo' => 12, 'jours' => json_encode(['lundi', 'mardi', 'jeudi']), 'date_debut' => $rentree->format('Y-m-d'), 'statut' => 'actif', 'type_accompagnement' => 'individuel']); }
        $nbP++;
    }
    $log("$nbP plans d'accompagnement, 2 AESH");
} catch (\Throwable $e) { $fail('besoins particuliers', $e); }

try {
    // ── Bourses, parcours éducatifs, support interne ──
    $wipe('bourses_demandes', 'parcours_educatifs', 'tickets_support');
    $types = $pdo->query('SELECT id FROM bourses_types ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($types) {
        $rows = [];
        foreach (array_rand(array_flip($elevesIds), 64) as $eid) {
            $ech = mt_rand(1, 3); $mont = [1 => 117, 2 => 315, 3 => 492][$ech];
            $st = $weighted(['acceptee' => 55, 'en_instruction' => 25, 'soumise' => 15, 'refusee' => 5]);
            $rows[] = ['etablissement_id' => $ETAB, 'parent_id' => $parentsDe[$eid][0], 'eleve_id' => $eid, 'type_id' => $types[0], 'annee_scolaire' => $annee, 'revenu_fiscal' => mt_rand(8, 22) * 1000, 'nb_enfants' => mt_rand(1, 4),
                'echelon_simule' => $ech, 'montant_simule' => $mont, 'echelon_final' => $st === 'acceptee' ? $ech : 0, 'montant_final' => $st === 'acceptee' ? $mont : 0, 'statut' => $st,
                'instructeur_id' => $st !== 'soumise' ? $gestId : null, 'date_soumission' => $rentree->modify('+' . mt_rand(5, 40) . ' days')->format('Y-m-d 20:00:00'), 'date_creation' => $rentree->modify('+5 days')->format('Y-m-d 20:00:00')];
        }
        $bulk('bourses_demandes', $rows);
    }
    $pe = [];
    $acts = ['avenir' => ['Forum des métiers', 'Visite du lycée professionnel', 'Rencontre avec une ingénieure'], 'sante' => ['Atelier gestes de premiers secours', 'Intervention sur le sommeil', 'Prévention des écrans'],
        'citoyen' => ['Élection des délégués', 'Visite de la mairie', 'Commémoration du 11 Novembre'], 'PEAC' => ['Spectacle au théâtre municipal', 'Visite du musée des Beaux-Arts', 'Concert de la chorale']];
    foreach ($elevesIds as $eid) {
        foreach (array_rand($acts, 2) as $tp) {
            $d = $pick($joursClasse);
            $pe[] = ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'type_parcours' => $tp, 'titre' => $pick($acts[$tp]), 'description' => 'Activité du parcours ' . $tp . '.', 'date_activite' => $d->format('Y-m-d'),
                'competences_visees' => 'Autonomie, ouverture culturelle', 'validation' => $d < $today->modify('-10 days') ? 'valide' : 'en_cours', 'annee_scolaire' => $annee, 'created_at' => $d->format('Y-m-d 18:00:00')];
        }
    }
    $bulk('parcours_educatifs', $pe, 800);
    foreach ([[$P, 'professeur', 'Vidéoprojecteur de la salle 202 en panne', 'L\'image clignote puis s\'éteint au bout de 10 minutes.', 'technique', 'haute', 'en_cours', 3],
              [$lea, 'eleve', 'Je ne retrouve pas mon devoir rendu', 'J\'ai rendu le DM de maths mais il n\'apparaît pas.', 'pedagogique', 'normale', 'resolu', 9],
              [$parentDemo, 'parent', 'Changement d\'adresse e-mail', 'Merci de mettre à jour mon adresse de contact.', 'compte', 'basse', 'ouvert', 1],
              [$profDeClasse['5A']['FRA'], 'professeur', 'Accès au module Ressources', 'Je ne peux pas publier de ressource pour les 5A.', 'technique', 'normale', 'resolu', 15],
              [$cpeId, 'vie_scolaire', 'Imprimante de la vie scolaire', 'Bourrage papier récurrent.', 'technique', 'normale', 'ferme', 30]] as [$uid, $ut, $sujet, $desc, $cat, $prio, $st, $off]) {
        $d = $NOW->modify("-$off days");
        $ins('tickets_support', ['etablissement_id' => $ETAB, 'user_id' => $uid, 'user_type' => $ut, 'sujet' => $sujet, 'description' => $desc, 'categorie' => $cat, 'priorite' => $prio, 'statut' => $st,
            'reponse' => in_array($st, ['resolu', 'ferme', 'en_cours'], true) ? 'Pris en charge par l\'équipe, merci pour le signalement.' : null, 'traite_par' => $st !== 'ouvert' ? $adjointId : null,
            'date_reponse' => $st !== 'ouvert' ? $d->modify('+5 hours')->format('Y-m-d H:i:s') : null, 'date_creation' => $d->format('Y-m-d H:i:s'), 'first_response_at' => $st !== 'ouvert' ? $d->modify('+5 hours')->format('Y-m-d H:i:s') : null]);
    }
    $log(count($pe) . ' activités de parcours éducatifs, bourses, demandes au support');
} catch (\Throwable $e) { $fail('bourses / parcours / support', $e); }

try {
    // ── Archives des années précédentes (bilans annuels figés) ──
    $wipe('archives_annuelles', 'archives');
    foreach ([3, 2, 1] as $k) {
        $a = ($startYear - $k) . '-' . ($startYear - $k + 1);
        $bilan = ['annee' => $a, 'effectif' => 395 + 6 * (3 - $k) + mt_rand(-4, 4), 'classes' => 16, 'reussite_dnb' => round(86 + mt_rand(0, 70) / 10, 1), 'mentions_dnb' => round(55 + mt_rand(0, 90) / 10, 1),
            'taux_absenteisme' => round(3.6 + mt_rand(0, 15) / 10, 1), 'incidents' => mt_rand(240, 330), 'moyenne_generale' => round(12.2 + mt_rand(0, 6) / 10, 2),
            'orientation' => ['2nde GT' => 68 + mt_rand(-3, 3), 'Voie professionnelle' => 27 + mt_rand(-3, 3), 'Autre' => 5]];
        $json = json_encode($bilan, JSON_UNESCAPED_UNICODE);
        $ins('archives_annuelles', ['etablissement_id' => $ETAB, 'annee_scolaire' => $a, 'type' => 'bilan_annuel', 'description' => "Bilan de l'année $a (effectifs, résultats, vie scolaire).", 'donnees' => $json,
            'taille' => strlen($json), 'verrouille' => 1, 'archive_par' => $adminId, 'date_archivage' => ($startYear - $k + 1) . '-07-08 10:00:00']);
        $ins('archives', ['annee_scolaire' => $a, 'type' => 'bilan_annuel', 'data_json' => $json, 'created_at' => ($startYear - $k + 1) . '-07-08 10:00:00', 'etablissement_id' => $ETAB]);
    }
    // ── Indicateurs de décrochage (à partir des absences et des notes réelles) ──
    $absParEleve = $pdo->query('SELECT id_eleve, COUNT(*) FROM absences GROUP BY id_eleve')->fetchAll(PDO::FETCH_KEY_PAIR);
    $incParEleve = $pdo->query('SELECT eleve_id, COUNT(*) FROM incidents GROUP BY eleve_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $sv = [];
    foreach ($eleves as $eid => [, , , $lvl]) {
        $r = min(0.99, max(0.01, (12 - $lvl) * 0.06 + ($absParEleve[$eid] ?? 0) * 0.05 + ($incParEleve[$eid] ?? 0) * 0.08));
        $sv[] = ['eleve_id' => $eid, 'risque_decrochage' => round($r, 2), 'derniere_analyse' => $today->format('Y-m-d'), 'notes_json' => json_encode(['absences' => (int) ($absParEleve[$eid] ?? 0), 'incidents' => (int) ($incParEleve[$eid] ?? 0)]), 'etablissement_id' => $ETAB];
    }
    $wipe('suivi_eleves'); $bulk('suivi_eleves', $sv);
    $log('archives de 3 années, indicateurs de suivi');
} catch (\Throwable $e) { $fail('archives / suivi', $e); }

try {
    // ── Mesure d'audience (60 jours, anonyme) + consentements — tableau de bord direction ──
    $wipe('audience_events', 'cookie_consents');
    $roleMods = [
        'eleve' => ['notes' => 30, 'cahierdetextes' => 22, 'emploi_du_temps' => 20, 'accueil' => 18, 'messagerie' => 6, 'agenda' => 4],
        'parent' => ['notes' => 30, 'absences' => 18, 'accueil' => 20, 'messagerie' => 14, 'cahierdetextes' => 10, 'bulletins' => 8, 'reunions' => 6, 'cantine' => 4],
        'professeur' => ['notes' => 28, 'appel' => 24, 'cahierdetextes' => 16, 'emploi_du_temps' => 12, 'messagerie' => 12, 'accueil' => 8, 'competences' => 4],
        'vie_scolaire' => ['absences' => 40, 'discipline' => 20, 'messagerie' => 15, 'accueil' => 15, 'agenda' => 10, 'infirmerie' => 6],
        'administrateur' => ['admin' => 35, 'accueil' => 20, 'reporting' => 15, 'messagerie' => 15, 'annonces' => 15],
    ];
    $paths = ['notes' => '/modules/notes/notes.php', 'cahierdetextes' => '/modules/cahierdetextes/cahierdetextes.php', 'emploi_du_temps' => '/modules/emploi_du_temps/emploi_du_temps.php',
        'accueil' => '/accueil/accueil.php', 'messagerie' => '/modules/messagerie/', 'agenda' => '/modules/agenda/agenda.php', 'absences' => '/modules/absences/absences.php',
        'bulletins' => '/modules/bulletins/bulletins.php', 'appel' => '/modules/appel/appel.php', 'discipline' => '/modules/discipline/incidents.php', 'reunions' => '/modules/reunions/',
        'admin' => '/admin/dashboard.php', 'reporting' => '/modules/reporting/reporting.php', 'annonces' => '/modules/annonces/annonces.php', 'cantine' => '/modules/cantine/', 'competences' => '/modules/competences/', 'infirmerie' => '/modules/infirmerie/'];
    $hoursBy = ['eleve' => [7, 7, 12, 12, 13, 17, 17, 18, 18, 19, 20, 21], 'parent' => [7, 8, 12, 18, 19, 19, 20, 20, 21, 21, 22],
        'professeur' => [7, 8, 8, 10, 12, 13, 16, 17, 18, 20, 21], 'vie_scolaire' => [8, 8, 9, 9, 10, 11, 13, 14, 15, 16], 'administrateur' => [8, 9, 10, 11, 14, 15, 16, 17]];
    $dailyBy = ['eleve' => [110, 190], 'parent' => [70, 140], 'professeur' => [22, 30], 'vie_scolaire' => [4, 6], 'administrateur' => [2, 3]];
    $rows = [];
    for ($dd = 59; $dd >= 0; $dd--) {
        $day = $today->modify("-{$dd} days"); $wd = (int) $day->format('N');
        $factor = $wd >= 6 ? 0.35 : ($wd === 3 ? 0.8 : 1.0);
        foreach ($dailyBy as $role => [$lo, $hi]) {
            $nv = (int) round(mt_rand($lo, $hi) * $factor);
            for ($v = 0; $v < $nv; $v++) {
                $visitor = substr(sha1($role . $v . $day->format('Ymd')), 0, 16);
                $device = in_array($role, ['professeur', 'vie_scolaire', 'administrateur'], true) ? (mt_rand(1, 10) <= 8 ? 'ordinateur' : 'mobile') : (mt_rand(1, 10) <= 7 ? 'mobile' : (mt_rand(0, 1) ? 'ordinateur' : 'tablette'));
                $h0 = $hoursBy[$role][mt_rand(0, count($hoursBy[$role]) - 1)];
                if ($dd === 0 && $h0 > (int) $NOW->format('G')) { continue; }
                for ($k = 0, $pv = mt_rand(2, $role === 'professeur' ? 9 : 6); $k < $pv; $k++) {
                    $m = $weighted($roleMods[$role]);
                    $rows[] = ['etablissement_id' => $ETAB, 'day' => $day->format('Y-m-d'), 'hour' => $h0, 'weekday' => $wd, 'path' => $paths[$m], 'module' => $m, 'role' => $role, 'device' => $device, 'visitor' => $visitor,
                        'duration' => mt_rand(8, $m === 'notes' || $m === 'appel' ? 240 : 150), 'created_at' => $day->format('Y-m-d') . sprintf(' %02d:%02d:00', $h0, mt_rand(0, 59))];
                }
            }
        }
    }
    $pdo->beginTransaction(); $n = $bulk('audience_events', $rows, 1000); $pdo->commit();
    $cc = [];
    for ($k = 0; $k < 520; $k++) {
        $acc = mt_rand(1, 100) <= 71;
        $cc[] = ['etablissement_id' => $ETAB, 'consent_id' => bin2hex(random_bytes(16)), 'preferences' => 0, 'analytics' => $acc ? 1 : 0, 'policy_version' => \API\Services\Privacy\ConsentService::POLICY_VERSION,
            'action' => $acc ? 'accept_all' : 'reject_all', 'created_at' => $today->modify('-' . mt_rand(0, 59) . ' days')->format('Y-m-d') . sprintf(' %02d:%02d:00', mt_rand(7, 21), mt_rand(0, 59))];
    }
    $bulk('cookie_consents', $cc);
    $log("mesure d'audience : $n pages vues sur 60 jours");
} catch (\Throwable $e) { $fail('audience', $e); }
