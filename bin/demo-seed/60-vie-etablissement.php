<?php
/** Démo — vie de l'établissement : cantine, facturation, CDI, clubs, associations, transports,
 *  périscolaire, projets, inventaire, réservations de salles, personnel, formations. */
declare(strict_types=1);

try {
    // ── Cantine : menus (rentrée → +3 semaines), demi-pensionnaires, réservations, pointage ──
    $wipe('menus_cantine', 'cantine_tarifs', 'cantine_reservations', 'cantine_pointage', 'cantine_evaluations', 'cantine_gaspillage');
    $entrees = ['Carottes râpées', 'Salade de lentilles', 'Velouté de potiron', 'Taboulé', 'Betteraves vinaigrette', 'Œuf mayonnaise', 'Concombre à la crème', 'Salade de riz', 'Pamplemousse', 'Céleri rémoulade'];
    $plats = [['Poulet rôti', 'Haricots verts'], ['Lasagnes végétariennes', 'Salade verte'], ['Filet de colin', 'Riz pilaf'], ['Bœuf bourguignon', 'Pommes vapeur'], ['Curry de pois chiches', 'Semoule'],
        ['Rôti de porc', 'Purée maison'], ['Hachis parmentier', 'Salade'], ['Poisson pané', 'Épinards à la crème'], ['Chili sin carne', 'Riz'], ['Sauté de dinde', 'Coquillettes'], ['Quiche aux légumes', 'Salade verte'], ['Couscous', 'Légumes du couscous']];
    $desserts = ['Yaourt fermier', 'Compote pomme-poire', 'Fruit de saison', 'Crème dessert vanille', 'Tarte aux pommes', 'Fromage blanc', 'Mousse au chocolat', 'Clafoutis', 'Salade de fruits'];
    foreach ([['Tarif plein', 4.20], ['Tarif réduit (QF < 800)', 2.90], ['Tarif social (QF < 500)', 1.10]] as [$tr, $px]) {
        $ins('cantine_tarifs', ['etablissement_id' => $ETAB, 'tranche' => $tr, 'tarif_repas' => $px, 'type_repas' => 'dejeuner', 'annee_scolaire' => $annee, 'actif' => 1]);
    }
    $menus = []; $menuIdParDate = []; $joursRepas = [];
    $mid = 0;
    for ($d = $rentree; $d <= $today->modify('+21 days'); $d = $d->modify('+1 day')) {
        $n = (int) $d->format('N');
        if ($n >= 6 || $n === 3) { continue; }
        [$pl, $acc] = $pick($plats);
        $mid++;
        $menus[] = ['id' => $mid, 'etablissement_id' => $ETAB, 'date_menu' => $d->format('Y-m-d'), 'entree' => $pick($entrees), 'plat_principal' => $pl, 'accompagnement' => $acc,
            'dessert' => $pick($desserts), 'allergenes' => $pick(['Gluten, lait', 'Œuf', 'Lait', 'Poisson', 'Aucun majeur', 'Gluten, céleri']), 'regime_special' => 'normal'];
        $menuIdParDate[$d->format('Y-m-d')] = $mid; $joursRepas[] = $d;
    }
    $bulk('menus_cantine', $menus);
    $demiPens = array_values(array_filter($elevesIds, fn($e) => $e === $lea || $e === $hugo || mt_rand(1, 100) <= 72));
    $resa = []; $point = []; $rid = 0; $evals = [];
    foreach ($joursRepas as $d) {
        $ymd = $d->format('Y-m-d');
        $passe = $heurePassee($d, '13:30');
        foreach ($demiPens as $eid) {
            $absent = isset($absentSlots["$eid|$ymd"][3]) || isset($absentSlots["$eid|$ymd"][4]);
            $rid++;
            $statut = $passe ? ($absent ? 'annule' : 'consomme') : 'reserve';
            if (!$passe && mt_rand(1, 100) <= 3) { $statut = 'annule'; }
            $resa[] = ['id' => $rid, 'etablissement_id' => $ETAB, 'eleve_id' => $eid, 'date_repas' => $ymd, 'type_repas' => 'dejeuner', 'regime' => mt_rand(1, 100) <= 12 ? 'végétarien' : 'standard',
                'statut' => $statut, 'reserve_par' => 'forfait', 'created_at' => $rentree->modify('-3 days')->format('Y-m-d 10:00:00')];
            if ($statut === 'consomme') {
                $point[] = ['reservation_id' => $rid, 'heure_passage' => $ymd . ' ' . sprintf('12:%02d:%02d', mt_rand(0, 59), mt_rand(0, 59)), 'pointe_par' => $pick($aedIds)];
                if (mt_rand(1, 1000) <= 12) { $evals[] = ['etablissement_id' => $ETAB, 'menu_id' => $menuIdParDate[$ymd], 'eleve_id' => $eid, 'note' => $weighted([5 => 25, 4 => 35, 3 => 22, 2 => 12, 1 => 6]), 'commentaire' => $pick([null, 'Trop salé', 'Très bon dessert !', 'Portions un peu petites', 'Super le couscous', null]), 'created_at' => "$ymd 13:10:00"]; }
            }
        }
    }
    $pdo->beginTransaction();
    $bulk('cantine_reservations', $resa, 1000); $bulk('cantine_pointage', $point, 1000); $bulk('cantine_evaluations', $evals);
    $g = [];
    foreach ($joursRepas as $d) { if ($heurePassee($d, '14:00')) { $g[] = ['etablissement_id' => $ETAB, 'date_mesure' => $d->format('Y-m-d'), 'quantite_kg' => round(mt_rand(180, 420) / 10, 1), 'type' => 'plateaux', 'created_at' => $d->format('Y-m-d 14:30:00')]; } }
    $bulk('cantine_gaspillage', $g);
    $pdo->commit();
    $log(sprintf('cantine : %d demi-pensionnaires, %d réservations, %d passages pointés', count($demiPens), count($resa), count($point)));

    // ── Facturation : une facture de demi-pension par famille et par mois écoulé ──
    $wipe('factures', 'facture_lignes', 'paiements');
    $famille = []; foreach ($demiPens as $eid) { $famille[$parentsDe[$eid][0]][] = $eid; }
    $fid = 0; $fact = []; $lignes = [];
    for ($m = $rentree->modify('first day of this month'); $m <= $today; $m = $m->modify('first day of next month')) {
        $finMois = $m->modify('last day of this month');
        $emission = $nextSchoolDay($finMois->modify('+1 day'));
        if ($emission > $today) { continue; }
        foreach ($famille as $pid => $enfants) {
            $fid++; $total = 0;
            foreach ($enfants as $eid) {
                $nb = 0; foreach ($joursRepas as $d) { if ($d >= $m && $d <= $finMois && $d <= $today) { $nb++; } }
                $px = $pid % 5 === 0 ? 2.90 : 4.20; $mt = round($nb * $px, 2); $total += $mt;
                $lignes[] = ['facture_id' => $fid, 'description' => 'Demi-pension ' . $eleves[$eid][0] . ' — ' . $nb . ' repas', 'quantite' => $nb, 'prix_unitaire' => $px, 'montant' => $mt];
            }
            $echeance = $emission->modify('+30 days');
            $statut = $echeance < $today ? ($pid % 13 === 0 ? 'en_retard' : 'payee') : ($pid % 3 === 0 ? 'payee' : 'emise');
            $fact[] = ['id' => $fid, 'etablissement_id' => $ETAB, 'numero' => sprintf('FAC-%s-%05d', $m->format('Ym'), $fid), 'parent_id' => $pid, 'eleve_id' => $enfants[0],
                'intitule' => 'Demi-pension — ' . $moisFr($m), 'montant_ht' => $total, 'tva' => 0, 'montant_ttc' => $total, 'date_emission' => $emission->format('Y-m-d'),
                'date_echeance' => $echeance->format('Y-m-d'), 'statut' => $statut, 'type' => 'cantine', 'created_at' => $emission->format('Y-m-d 08:00:00')];
        }
    }
    $bulk('factures', $fact); $bulk('facture_lignes', $lignes);
    $log(count($fact) . ' factures de demi-pension');
} catch (\Throwable $e) { $fail('cantine / facturation', $e); }

try {
    // ── CDI : fonds documentaire, emprunts, réservations, listes de lecture ──
    $wipe('livres', 'emprunts', 'livre_reservations', 'bibliotheque_listes_lecture');
    $fonds = [['Le Petit Prince', 'Antoine de Saint-Exupéry', 'Roman'], ['Harry Potter à l\'école des sorciers', 'J. K. Rowling', 'Roman jeunesse'], ['L\'Odyssée', 'Homère', 'Classique'],
        ['Le Horla', 'Guy de Maupassant', 'Nouvelle'], ['Vendredi ou la Vie sauvage', 'Michel Tournier', 'Roman'], ['Le Journal d\'Anne Frank', 'Anne Frank', 'Témoignage'],
        ['Les Misérables (abrégé)', 'Victor Hugo', 'Classique'], ['Le Lion', 'Joseph Kessel', 'Roman'], ['No et moi', 'Delphine de Vigan', 'Roman'], ['Wonder', 'R. J. Palacio', 'Roman jeunesse'],
        ['La Rivière à l\'envers', 'Jean-Claude Mourlevat', 'Roman jeunesse'], ['Le Bourgeois gentilhomme', 'Molière', 'Théâtre'], ['Les Fourberies de Scapin', 'Molière', 'Théâtre'],
        ['Antigone', 'Jean Anouilh', 'Théâtre'], ['Cyrano de Bergerac', 'Edmond Rostand', 'Théâtre'], ['Persepolis', 'Marjane Satrapi', 'Bande dessinée'], ['Astérix le Gaulois', 'Goscinny et Uderzo', 'Bande dessinée'],
        ['Tintin au Tibet', 'Hergé', 'Bande dessinée'], ['L\'Atlas des océans', 'Collectif', 'Documentaire'], ['Le Corps humain expliqué', 'Collectif', 'Documentaire'], ['Les volcans', 'Collectif', 'Documentaire'],
        ['Hunger Games', 'Suzanne Collins', 'Roman jeunesse'], ['Le Seigneur des anneaux (t. 1)', 'J. R. R. Tolkien', 'Fantasy'], ['La Guerre des boutons', 'Louis Pergaud', 'Roman'],
        ['Matin brun', 'Franck Pavloff', 'Nouvelle'], ['Le Cid', 'Corneille', 'Théâtre'], ['Dictionnaire Le Robert collège', 'Le Robert', 'Référence'], ['Atlas mondial', 'Collectif', 'Référence']];
    $livres = []; $lid = 0;
    foreach ($fonds as $k => [$t, $a, $cat]) {
        $lid++; $ex = $cat === 'Référence' ? 2 : mt_rand(2, 6);
        $livres[$lid] = $ex;
        $ins('livres', ['id' => $lid, 'etablissement_id' => $ETAB, 'titre' => $t, 'auteur' => $a, 'isbn' => '978' . sprintf('%010d', mt_rand(0, 999999999)), 'editeur' => $pick(['Gallimard Jeunesse', 'Folio', 'Hachette', 'Magnard', 'Belin', 'Casterman']),
            'annee_publication' => mt_rand(1950, 2024), 'categorie' => $cat, 'description' => "$t — $a.", 'exemplaires_total' => $ex, 'exemplaires_disponibles' => $ex,
            'emplacement' => 'Rayon ' . chr(65 + $k % 8) . ' — ' . ($k % 5 + 1), 'actif' => 1, 'date_creation' => $rentree->modify('-1 year')->format('Y-m-d 10:00:00')]);
    }
    $emp = []; $enCours = array_fill_keys(array_keys($livres), 0);
    foreach ($joursClasse as $d) {
        for ($k = 0; $k < mt_rand(2, 7); $k++) {
            $l = mt_rand(1, $lid); if ($enCours[$l] >= $livres[$l]) { continue; }
            $eid = $pick($elevesIds); $prevue = $d->modify('+21 days');
            $rendu = $prevue < $today ? ($chance(0.9) ? $d->modify('+' . mt_rand(5, 24) . ' days') : null) : ($chance(0.35) ? $d->modify('+' . mt_rand(4, 12) . ' days') : null);
            if ($rendu && $rendu > $today) { $rendu = null; }
            $statut = $rendu ? 'rendu' : ($prevue < $today ? 'en_retard' : 'en_cours');
            if (!$rendu) { $enCours[$l]++; }
            $emp[] = ['etablissement_id' => $ETAB, 'livre_id' => $l, 'emprunteur_id' => $eid, 'emprunteur_type' => 'eleve', 'date_emprunt' => $d->format('Y-m-d'),
                'date_retour_prevue' => $prevue->format('Y-m-d'), 'date_retour_effective' => $rendu?->format('Y-m-d'), 'statut' => $statut, 'date_creation' => $d->format('Y-m-d 12:40:00')];
        }
    }
    $emp[] = ['etablissement_id' => $ETAB, 'livre_id' => 9, 'emprunteur_id' => $lea, 'emprunteur_type' => 'eleve', 'date_emprunt' => $today->modify('-6 days')->format('Y-m-d'),
        'date_retour_prevue' => $today->modify('+15 days')->format('Y-m-d'), 'date_retour_effective' => null, 'statut' => 'en_cours', 'date_creation' => $today->modify('-6 days')->format('Y-m-d 12:40:00')];
    $enCours[9]++;
    $bulk('emprunts', $emp);
    foreach ($enCours as $l => $n) { $pdo->prepare('UPDATE livres SET exemplaires_disponibles = GREATEST(0, exemplaires_total - ?) WHERE id = ?')->execute([$n, $l]); }
    foreach ([[$P, '4A', 'Lectures scientifiques', [19, 20, 21]], [$profDeClasse['4A']['FRA'], '4A', 'Théâtre classique', [12, 13, 26]], [$profDeClasse['6A']['FRA'], '6A', 'Romans d\'aventure', [5, 2, 22]]] as [$pid, $cl, $t, $ids]) {
        $ins('bibliotheque_listes_lecture', ['etablissement_id' => $ETAB, 'titre' => $t, 'professeur_id' => $pid, 'classe' => $cl, 'livres_ids' => json_encode($ids), 'created_at' => $rentree->modify('+10 days')->format('Y-m-d 17:00:00')]);
    }
    $log(count($emp) . ' emprunts au CDI (' . $lid . ' titres)');
} catch (\Throwable $e) { $fail('CDI', $e); }

try {
    // ── Clubs du midi + associations (FSE, AS) ──
    $wipe('clubs', 'club_inscriptions', 'club_seances', 'club_seances_presences', 'club_waitlist', 'club_budget', 'associations', 'association_membres', 'association_activites', 'association_tresorerie');
    $clubsDef = [['Club robotique', 'Sciences', $profDeClasse['4A']['TEC'] ?? $P, 'jeudi', 'Salle Techno 1', 16], ['Chorale', 'Musique', $profDeClasse['6A']['MUS'], 'mardi', 'Salle de musique', 40],
        ['Club théâtre', 'Arts', $profDeClasse['5A']['FRA'], 'lundi', 'Salle polyvalente', 20], ['Échecs', 'Jeux', $tzrId, 'vendredi', 'CDI', 24], ['Journal du collège', 'Médias', $docId, 'mardi', 'CDI', 12],
        ['Club astronomie', 'Sciences', $profDeClasse['3A']['PC'], 'jeudi', 'Labo Physique-Chimie 1', 14], ['Atelier dessin manga', 'Arts', $profDeClasse['4B']['ART'], 'vendredi', 'Salle d\'arts', 18], ['Club éco-délégués', 'Citoyenneté', $profDeClasse['5B']['SVT'], 'lundi', 'Salle 104', 25]];
    $jourIdx = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5];
    foreach ($clubsDef as [$nom, $cat, $resp, $jour, $lieu, $places]) {
        $inscrits = array_rand(array_flip($elevesIds), max(2, $places - mt_rand(0, 4)));
        if ($nom === 'Club théâtre') { $inscrits[] = $lea; }
        $inscrits = array_values(array_unique($inscrits));
        $cid = $ins('clubs', ['etablissement_id' => $ETAB, 'nom' => $nom, 'description' => "$nom — ouvert à tous les niveaux, sur la pause méridienne.", 'categorie' => $cat, 'responsable_id' => $resp, 'responsable_type' => 'professeur',
            'jour' => $jour, 'horaire_debut' => '12:30:00', 'horaire_fin' => '13:25:00', 'horaires' => ucfirst($jour) . ' 12 h 30 – 13 h 25', 'lieu' => $lieu, 'places_max' => $places,
            'places_restantes' => max(0, $places - count($inscrits)), 'date_debut' => $rentree->modify('+14 days')->format('Y-m-d'), 'date_fin' => $finAnnee->format('Y-m-d'), 'actif' => 1, 'date_creation' => $rentree->format('Y-m-d 09:00:00')]);
        $bulk('club_inscriptions', array_map(fn($e) => ['etablissement_id' => $ETAB, 'club_id' => $cid, 'eleve_id' => $e, 'statut' => 'inscrit', 'date_inscription' => $rentree->modify('+' . mt_rand(5, 13) . ' days')->format('Y-m-d 12:00:00')], $inscrits));
        if (count($inscrits) >= $places - 1) { foreach (array_rand(array_flip($elevesIds), 3) as $pos => $e) { $ins('club_waitlist', ['club_id' => $cid, 'eleve_id' => $e, 'position' => $pos + 1, 'created_at' => $rentree->modify('+15 days')->format('Y-m-d 12:00:00')]); } }
        foreach ($joursClasse as $d) {
            if ((int) $d->format('N') !== $jourIdx[$jour] || $d < $rentree->modify('+14 days') || !$heurePassee($d, '13:25')) { continue; }
            $sid = $ins('club_seances', ['club_id' => $cid, 'date_seance' => $d->format('Y-m-d'), 'heure_debut' => '12:30:00', 'heure_fin' => '13:25:00', 'lieu' => $lieu, 'description' => $pick(['Séance de travail', 'Préparation du projet', 'Atelier libre', 'Intervention extérieure'])]);
            $bulk('club_seances_presences', array_map(fn($e) => ['seance_id' => $sid, 'eleve_id' => $e, 'present' => mt_rand(1, 100) <= 88 ? 1 : 0], $inscrits));
        }
        $ins('club_budget', ['club_id' => $cid, 'libelle' => 'Dotation FSE', 'montant' => mt_rand(150, 600), 'type' => 'recette', 'date_operation' => $rentree->modify('+20 days')->format('Y-m-d')]);
        $ins('club_budget', ['club_id' => $cid, 'libelle' => 'Achat de matériel', 'montant' => mt_rand(40, 140), 'type' => 'depense', 'date_operation' => $rentree->modify('+30 days')->format('Y-m-d')]);
    }
    foreach ([['Foyer socio-éducatif (FSE)', 'FSE', $cpeId, 4200], ['Association sportive (AS)', 'association', $profDeClasse['3A']['EPS'], 3100]] as [$nom, $type, $ref, $budget]) {
        $aid = $ins('associations', ['etablissement_id' => $ETAB, 'nom' => $nom, 'type' => $type, 'description' => $nom . ' du collège Victor-Hugo.', 'president_eleve_id' => $pick($elevesParClasse['3B']), 'referent_adulte_id' => $ref,
            'budget_annuel' => $budget, 'statut' => 'active', 'created_at' => $rentree->modify('-2 years')->format('Y-m-d')]);
        $mem = array_rand(array_flip($elevesIds), $type === 'FSE' ? 60 : 85);
        $bulk('association_membres', array_map(fn($e, $i) => ['association_id' => $aid, 'eleve_id' => $e, 'role_membre' => [0 => 'president', 1 => 'vice_president', 2 => 'tresorier', 3 => 'secretaire'][$i] ?? 'membre', 'date_adhesion' => $rentree->modify('+' . mt_rand(3, 25) . ' days')->format('Y-m-d'), 'cotisation_payee' => mt_rand(1, 100) <= 85 ? 1 : 0], $mem, array_keys($mem)));
        foreach ($type === 'FSE' ? [['Vente de gâteaux', -10], ['Sortie cinéma 6e', 12], ['Boum de Noël', 45]] : [['Cross UNSS district', -15], ['Tournoi de handball', 8], ['Championnat départemental', 30]] as [$t, $off]) {
            $d = $today->modify(($off >= 0 ? '+' : '') . $off . ' days');
            $ins('association_activites', ['association_id' => $aid, 'titre' => $t, 'description' => $t . '.', 'date_activite' => $d->format('Y-m-d 13:30:00'), 'lieu' => $type === 'FSE' ? 'Collège' : 'Complexe sportif',
                'budget_alloue' => mt_rand(100, 400), 'budget_depense' => $off < 0 ? mt_rand(60, 300) : 0, 'nb_participants' => mt_rand(20, 90), 'statut' => $off < 0 ? 'termine' : 'planifie']);
        }
        $ins('association_tresorerie', ['association_id' => $aid, 'type' => 'recette', 'montant' => count($mem) * 8, 'libelle' => 'Cotisations', 'categorie' => 'Adhésions', 'date_operation' => $rentree->modify('+30 days')->format('Y-m-d'), 'saisi_par' => $gestId]);
        $ins('association_tresorerie', ['association_id' => $aid, 'type' => 'depense', 'montant' => mt_rand(150, 500), 'libelle' => 'Matériel et transport', 'categorie' => 'Fonctionnement', 'date_operation' => $today->modify('-12 days')->format('Y-m-d'), 'saisi_par' => $gestId]);
    }
    $log(count($clubsDef) . ' clubs avec séances et présences, 2 associations');
} catch (\Throwable $e) { $fail('clubs / associations', $e); }

try {
    // ── Transports scolaires + périscolaire (étude, aide aux devoirs) ──
    $wipe('lignes_transport', 'inscriptions_transport', 'services_periscolaires', 'inscriptions_periscolaire', 'presences_periscolaire');
    $arrets = ['Mairie', 'Église', 'Les Peupliers', 'Zone artisanale', 'Gare', 'Lotissement des Vignes', 'Hameau du Moulin', 'Place du marché'];
    $nbIns = 0;
    foreach ([['Ligne 12 — Val-Fleuri Nord', 'bus', '07:25', '08:05'], ['Ligne 14 — Les Hameaux', 'bus', '07:10', '08:00'], ['Ligne 21 — Gare / Collège', 'navette', '07:40', '07:55'], ['Ligne 33 — Vallée', 'bus', '07:05', '07:58']] as $k => [$nom, $type, $dep, $arr]) {
        $lid = $ins('lignes_transport', ['etablissement_id' => $ETAB, 'nom' => $nom, 'type' => $type, 'itineraire' => implode(' → ', array_slice($arrets, $k, 4)) . ' → Collège', 'horaire_depart' => "$dep:00", 'horaire_arrivee' => "$arr:00", 'capacite' => 59, 'actif' => 1]);
        $ins_ = [];
        foreach (array_rand(array_flip($elevesIds), mt_rand(28, 50)) as $e) { $ins_[] = ['etablissement_id' => $ETAB, 'ligne_id' => $lid, 'eleve_id' => $e, 'arret' => $arrets[($k + mt_rand(0, 3)) % count($arrets)], 'annee_scolaire' => $annee, 'statut' => 'inscrit']; }
        $nbIns += $bulk('inscriptions_transport', $ins_);
    }
    foreach ([['Étude surveillée', 'etude', 'Lundi, mardi, jeudi 16 h 30 – 17 h 30', 0, 60], ['Devoirs faits', 'activite', 'Mardi et jeudi 16 h 30 – 17 h 25', 0, 30], ['Atelier numérique', 'activite', 'Vendredi 16 h 30 – 17 h 30', 2, 16]] as [$nom, $type, $hor, $tarif, $places]) {
        $sid = $ins('services_periscolaires', ['etablissement_id' => $ETAB, 'nom' => $nom, 'type' => $type, 'description' => "$nom après les cours.", 'horaires' => $hor, 'tarif' => $tarif, 'places_max' => $places, 'actif' => 1, 'created_at' => $rentree->format('Y-m-d 09:00:00')]);
        $r = [];
        foreach (array_rand(array_flip($elevesIds), (int) ($places * 0.8)) as $e) { $r[] = ['service_id' => $sid, 'eleve_id' => $e, 'jour' => $pick(['lundi', 'mardi', 'jeudi', 'vendredi']), 'date_debut' => $rentree->modify('+14 days')->format('Y-m-d'), 'statut' => 'inscrit', 'created_at' => $rentree->modify('+10 days')->format('Y-m-d 18:00:00')]; }
        $bulk('inscriptions_periscolaire', $r);
    }
    $log("4 lignes de transport ($nbIns élèves), 3 services périscolaires");
} catch (\Throwable $e) { $fail('transports / périscolaire', $e); }

try {
    // ── Projets pédagogiques, inventaire, réservations de salles ──
    $wipe('projets_pedagogiques', 'projets_pedagogiques_etapes', 'projets_pedagogiques_participants', 'inventaire_assets', 'reservations_salles');
    foreach ([['Voyage en Angleterre (3e)', 'voyage', $profDeClasse['3A']['ANG'], '3A,3B', 'Anglais, Histoire-Géographie', 45, 120, 18000, 'valide'],
              ['EPI « Le corps en mouvement »', 'EPI', $profDeClasse['5A']['SVT'], '5A,5B,5C', 'SVT, EPS', -20, 60, 300, 'en_cours'],
              ['Concours de robotique', 'projet_classe', $profDeClasse['4A']['TEC'] ?? $P, '4A,4C', 'Technologie, Mathématiques', -30, 150, 900, 'en_cours'],
              ['Sortie au musée des Sciences', 'sortie', $P, '4A', 'Mathématiques, Physique-Chimie', 5, 5, 420, 'valide'],
              ['Journal du collège — n° 1', 'projet_classe', $docId, '6A,6B,6C,6D', 'Français, Documentation', -40, 30, 150, 'en_cours'],
              ['Commémoration du 11 Novembre', 'autre', $profDeClasse['3B']['HG'], '3A,3B,3C,3D', 'Histoire-Géographie', -10, 16, 0, 'valide']] as [$t, $type, $resp, $cls, $mats, $off, $len, $budget, $st]) {
        $d0 = $today->modify(($off >= 0 ? '+' : '') . $off . ' days');
        $pid = $ins('projets_pedagogiques', ['etablissement_id' => $ETAB, 'titre' => $t, 'description' => "$t : projet porté par l'équipe pédagogique.", 'objectifs' => 'Développer l\'autonomie, travailler en équipe, ouvrir sur le monde.', 'type' => $type,
            'responsable_id' => $resp, 'classes' => $cls, 'matieres' => $mats, 'date_debut' => $d0->format('Y-m-d'), 'date_fin' => $d0->modify("+$len days")->format('Y-m-d'), 'budget' => $budget, 'statut' => $st,
            'created_at' => $rentree->modify('+' . mt_rand(3, 30) . ' days')->format('Y-m-d 17:00:00')]);
        foreach (['Préparation', 'Réalisation', 'Bilan'] as $o => $et) {
            $ech = $d0->modify('+' . (int) ($len * ($o + 1) / 3) . ' days');
            $ins('projets_pedagogiques_etapes', ['projet_id' => $pid, 'titre' => $et, 'description' => "$et du projet.", 'date_echeance' => $ech->format('Y-m-d'), 'statut' => $ech < $today ? 'termine' : ($o === 0 || $ech->modify("-$len days") < $today ? 'en_cours' : 'a_faire'), 'ordre' => $o + 1]);
        }
        $ins('projets_pedagogiques_participants', ['projet_id' => $pid, 'user_id' => $resp, 'user_type' => 'professeur', 'role_projet' => 'Responsable']);
    }
    $inv = []; $n = 0;
    foreach ($salleNom as $sid => $nom) {
        foreach ([['Vidéoprojecteur', 'videoprojecteur', 'Epson', 'EB-W51', 520], ['Poste enseignant', 'ordinateur', 'Dell', 'OptiPlex 7010', 780]] as [$t, $cat, $mq, $mod, $px]) {
            $n++; $acq = $rentree->modify('-' . mt_rand(1, 6) . ' years');
            $inv[] = ['etablissement_id' => $ETAB, 'nom' => "$t — $nom", 'categorie' => $cat, 'marque' => $mq, 'modele' => $mod, 'numero_serie' => strtoupper(substr(md5((string) $n), 0, 10)), 'code_inventaire' => sprintf('INV-%04d', $n),
                'qr_code_token' => substr(sha1("qr$n"), 0, 32), 'salle_id' => $sid, 'date_acquisition' => $acq->format('Y-m-d'), 'valeur_achat' => $px, 'duree_amortissement' => 5, 'fournisseur' => $pick(['UGAP', 'LDLC Pro', 'Manutan Collectivités']),
                'garantie_fin' => $acq->modify('+3 years')->format('Y-m-d'), 'etat' => $etat = $weighted(['bon' => 60, 'usage' => 25, 'neuf' => 8, 'en_panne' => 7]), 'created_at' => $acq->format('Y-m-d 10:00:00'),
                'type_asset' => $cat, 'prix_achat' => $px, 'date_achat' => $acq->format('Y-m-d'), 'qr_token' => substr(sha1("qt$n"), 0, 32), 'statut' => $etat === 'en_panne' ? 'en_panne' : 'actif', 'date_creation' => $acq->format('Y-m-d 10:00:00')];
        }
    }
    for ($k = 0; $k < 2; $k++) { foreach (range(1, 15) as $t) { $n++; $inv[] = ['etablissement_id' => $ETAB, 'nom' => 'Tablette élève n° ' . ($k * 15 + $t), 'categorie' => 'tablette', 'marque' => 'Apple', 'modele' => 'iPad 10e gén.', 'numero_serie' => strtoupper(substr(md5("t$n"), 0, 10)), 'code_inventaire' => sprintf('INV-%04d', $n), 'qr_code_token' => substr(sha1("qr$n"), 0, 32), 'salle_id' => $salles['cdi'][0] ?? null, 'date_acquisition' => $rentree->modify('-1 year')->format('Y-m-d'), 'valeur_achat' => 389, 'duree_amortissement' => 4, 'fournisseur' => 'UGAP', 'garantie_fin' => $rentree->modify('+1 year')->format('Y-m-d'), 'etat' => 'bon', 'created_at' => $rentree->modify('-1 year')->format('Y-m-d 10:00:00'), 'type_asset' => 'tablette', 'prix_achat' => 389, 'date_achat' => $rentree->modify('-1 year')->format('Y-m-d'), 'qr_token' => substr(sha1("qt$n"), 0, 32), 'statut' => 'actif', 'date_creation' => $rentree->modify('-1 year')->format('Y-m-d 10:00:00')]; } }
    $bulk('inventaire_assets', $inv);
    $rs = [];
    foreach (range(1, 46) as $k) {
        $d = $nextSchoolDay($today->modify('+' . mt_rand(-30, 20) . ' days'));
        [$sid, $obj] = $pick([[$salles['reunion'][0], 'Réunion d\'équipe pédagogique'], [$salles['informatique'][0] ?? $salles['std'][0], 'Séance en salle informatique'], [$salles['cdi'][0], 'Recherche documentaire'], [$salles['reunion'][2] ?? $salles['reunion'][0], 'Entretien avec une famille'], [$salles['reunion'][0], 'Répétition du club théâtre']]);
        $h = $pick(['10:05', '13:30', '14:25', '16:30', '17:15']);
        $rs[] = ['etablissement_id' => $ETAB, 'salle_id' => $sid, 'reserveur_id' => $k % 5 === 0 ? $cpeId : $pick(array_keys($profName)), 'reserveur_type' => $k % 5 === 0 ? 'vie_scolaire' : 'professeur', 'objet' => $obj,
            'date_reservation' => $d->format('Y-m-d'), 'heure_debut' => "$h:00", 'heure_fin' => date('H:i:s', strtotime("2000-01-01 $h") + 3300), 'statut' => $d < $today ? 'confirmee' : $weighted(['confirmee' => 75, 'en_attente' => 25]), 'recurrence' => 'aucune', 'created_at' => $d->modify('-' . mt_rand(2, 12) . ' days')->format('Y-m-d 18:00:00')];
    }
    $bulk('reservations_salles', $rs);
    $log('6 projets pédagogiques, ' . count($inv) . ' équipements inventoriés, ' . count($rs) . ' réservations de salles');
} catch (\Throwable $e) { $fail('projets / inventaire / salles', $e); }

try {
    // ── Personnel : absences, remplacements, congés, heures supplémentaires, formations ──
    $wipe('personnel_absences', 'remplacements', 'personnel_conges', 'personnel_heures_sup', 'formations', 'formation_inscriptions');
    $profIds = array_keys($profName);
    foreach (range(1, 9) as $k) {
        $pid = $pick(array_values(array_diff($profIds, [$P, $docId, $tzrId])));
        $d0 = $nextSchoolDay($today->modify('+' . mt_rand(-45, 10) . ' days')); $len = $pick([0, 0, 1, 2, 4]);
        $type = $weighted(['maladie' => 55, 'formation' => 25, 'personnel' => 15, 'autre' => 5]);
        $abs = $ins('personnel_absences', ['etablissement_id' => $ETAB, 'personnel_id' => $pid, 'personnel_type' => 'professeur', 'type_absence' => $type, 'date_debut' => $d0->format('Y-m-d'),
            'date_fin' => $d0->modify("+$len days")->format('Y-m-d'), 'motif' => $type === 'formation' ? 'Formation académique' : null, 'statut' => $d0 < $today ? 'validee' : 'declaree', 'created_at' => $d0->modify('-2 days')->format('Y-m-d 08:00:00')]);
        if ($len >= 1) {
            $code = $profMatiere[$pid];
            $ins('remplacements', ['etablissement_id' => $ETAB, 'absence_id' => $abs, 'professeur_absent_id' => $pid, 'professeur_remplacant_id' => $code === 'MAT' ? $tzrId : ($chance(0.5) ? null : $tzrId),
                'matiere_id' => $mat[$code] ?? null, 'classe_id' => $classes[$classesDuProf[$pid][0] ?? '6A'], 'date_debut' => $d0->format('Y-m-d'), 'date_fin' => $d0->modify("+$len days")->format('Y-m-d'),
                'statut' => $code === 'MAT' ? 'pourvu' : $pick(['pourvu', 'a_pourvoir']), 'commentaire' => 'Remplacement de courte durée.', 'created_at' => $d0->modify('-1 day')->format('Y-m-d 16:00:00')]);
        }
    }
    foreach (array_slice($profIds, 0, 12) as $k => $pid) {
        if ($k % 3) { continue; }
        $d0 = $today->modify('+' . mt_rand(10, 60) . ' days');
        $ins('personnel_conges', ['personnel_id' => $pid, 'type' => $pick(['Autorisation d\'absence', 'Congé formation', 'Garde d\'enfant malade']), 'date_debut' => $d0->format('Y-m-d'), 'date_fin' => $d0->modify('+1 day')->format('Y-m-d'), 'statut' => $pick(['demande', 'valide']), 'etablissement_id' => $ETAB]);
        $ins('personnel_heures_sup', ['personnel_id' => $pid, 'date_heures' => $pick($joursClasse)->format('Y-m-d'), 'heures' => $pick([1, 1.5, 2]), 'motif' => $pick(['Devoirs faits', 'Remplacement', 'Soutien 3e']), 'created_at' => $today->modify('-5 days')->format('Y-m-d 18:00:00')]);
    }
    foreach ([['Gérer l\'hétérogénéité en classe', 'Pédagogie', 'interne', 18, 6], ['Premiers secours (PSC1)', 'Sécurité', 'externe', 32, 7], ['Le numérique au service des apprentissages', 'Numérique', 'en_ligne', -15, 3], ['Accueillir un élève à besoins particuliers', 'Inclusion', 'interne', -40, 6]] as [$t, $cat, $type, $off, $h]) {
        $d = $nextSchoolDay($today->modify(($off >= 0 ? '+' : '') . $off . ' days'));
        $fid = $ins('formations', ['etablissement_id' => $ETAB, 'titre' => $t, 'description' => "Formation : $t.", 'categorie' => $cat, 'type' => $type, 'date_debut' => $d->format('Y-m-d 09:00:00'), 'date_fin' => $d->format('Y-m-d 16:00:00'),
            'duree_heures' => $h, 'lieu' => $type === 'en_ligne' ? 'Distanciel' : 'Salle de réunion', 'formateur' => $pick(['Équipe académique', 'Croix-Rouge', 'Réseau Canopé']), 'places_max' => 15, 'cout_unitaire' => 0,
            'statut' => $off < 0 ? 'terminee' : 'ouverte', 'created_at' => $rentree->format('Y-m-d 09:00:00')]);
        foreach (array_rand(array_flip($profIds), mt_rand(4, 9)) as $pid) {
            $ins('formation_inscriptions', ['formation_id' => $fid, 'personnel_id' => $pid, 'personnel_type' => 'professeur', 'statut' => $off < 0 ? 'realisee' : $pick(['demandee', 'validee']), 'motivation' => 'Besoin identifié en équipe.', 'created_at' => $rentree->modify('+5 days')->format('Y-m-d 18:00:00')]);
        }
    }
    $log('personnel : absences, remplacements, congés, formations');
} catch (\Throwable $e) { $fail('personnel', $e); }
