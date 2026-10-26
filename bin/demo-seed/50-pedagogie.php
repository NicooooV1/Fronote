<?php
/** Démo — pédagogie : notes, cahier de textes, devoirs rendus, compétences, ressources,
 *  évaluations en ligne, examens, bulletins et conseils de classe des trimestres écoulés. */
declare(strict_types=1);

try {
    // ── Notes : une évaluation toutes les ~2 semaines par matière, le jour d'un cours ──
    $wipe('notes');
    $typesEval = [['Contrôle', 2.0], ['Interrogation', 1.0], ['Devoir maison', 1.0], ['Exposé', 1.0], ['Évaluation de compétences', 1.5], ['Contrôle', 2.0], ['Interrogation écrite', 1.0]];
    $appreciations = [
        'high' => ['Excellent travail, continue ainsi !', 'Très bonne maîtrise.', 'Copie remarquable.', 'Bravo, raisonnement rigoureux.', 'Très bon travail, soigné et précis.'],
        'mid'  => ['Travail correct, quelques imprécisions.', 'Des progrès, persévère.', 'Bien, mais attention aux détails.', 'Ensemble satisfaisant.', 'Correct, approfondis tes justifications.'],
        'low'  => ['Leçon à revoir.', 'Des efforts sont attendus.', 'Méthode à consolider, viens me voir.', 'Résultat fragile, ne te décourage pas.', 'Travail insuffisant, reprends le cours.'],
    ];
    $biais = []; $noteRows = []; $evals = []; // evals : [classe, code, date, type, coef, profId]
    $notesEleveMatiere = []; // eleveId => code => trimestre => [[note, coef], …]
    foreach ($seancesParClasseMatiere as $key => $idx) {
        [$classe, $code] = explode('|', $key);
        $h = max(1, $matDef[$code][3][$niveauIdx[$classesNoms[$classe]]]);
        $pas = max(2, $h * 2);          // ~ une évaluation toutes les 2 semaines
        $off = mt_rand(1, $pas - 1);
        $profId = $profDeClasse[$classe][$code];
        $n = 0;
        foreach ($idx as $j => $si) {
            [$edtId, $d, $passee] = $seances[$si];
            if (!$passee || ($j - $off) % $pas !== 0) { continue; }
            $saisie = $d->modify('+' . mt_rand(0, 6) . ' days')->setTime(mt_rand(17, 22), mt_rand(0, 59));
            if ($saisie > $NOW) { continue; } // pas encore saisie par le professeur
            [$type, $coef] = $typesEval[$n++ % count($typesEval)];
            $difficulte = $gauss(0, 1.1);
            $ymd = $d->format('Y-m-d'); $tri = $trimestreDe($ymd); $slot = $edt[$edtId][4];
            $evals[] = [$classe, $code, $d, $type, $coef, $profId];
            foreach ($elevesParClasse[$classe] as $eid) {
                if (isset($absentSlots["$eid|$ymd"][$slot])) { continue; } // absent ce jour-là
                $biais[$eid][$code] ??= $gauss(0, 1.6);
                // légère progression (ou baisse) au fil de l'année
                $prog = ($eleves[$eid][3] > 12 ? 0.25 : -0.15) * ($tri - 1);
                $note = round(max(0, min(20, $gauss($eleves[$eid][3] + $biais[$eid][$code] - $difficulte + $prog, 2.2))) * 2) / 2;
                $lvl = $note >= 15 ? 'high' : ($note >= 10 ? 'mid' : 'low');
                $noteRows[] = ['etablissement_id' => $ETAB, 'id_eleve' => $eid, 'id_matiere' => $mat[$code], 'id_professeur' => $profId, 'note' => $note,
                    'note_sur' => 20, 'coefficient' => $coef, 'type_evaluation' => $type, 'date_note' => $ymd,
                    'commentaire' => mt_rand(1, 100) <= 35 ? $pick($appreciations[$lvl]) : null, 'trimestre' => $tri,
                    'date_creation' => $saisie->format('Y-m-d H:i:s')];
                $notesEleveMatiere[$eid][$code][$tri][] = [$note, $coef];
            }
        }
    }
    $pdo->beginTransaction(); $nNotes = $bulk('notes', $noteRows, 800); $pdo->commit();
    unset($noteRows);
    $log(sprintf('%d notes (%d évaluations)', $nNotes, count($evals)));
} catch (\Throwable $e) { $fail('notes', $e); }

try {
    // ── Cahier de textes : un devoir pour ~45 % des cours, à rendre au cours suivant de la matière ──
    $wipe('devoirs', 'devoirs_rendus', 'devoirs_statuts_eleve');
    $sujets = [
        'FRA' => ['Lire les chapitres 3 et 4', 'Rédaction : décrire un lieu familier (20 lignes)', 'Apprendre la conjugaison du passé simple', 'Fiche de lecture', 'Exercices 4 et 5 p. 112 (accords)', 'Apprendre le poème (strophes 1 et 2)', 'Préparer la dictée : mots de la liste 6'],
        'MAT' => ['Exercices 12 à 15 p. 84', 'Apprendre le cours sur les fractions', 'DM : problème de géométrie', 'Réviser pour le contrôle', 'Exercices 3, 7 et 9 p. 132 (calcul littéral)', 'Construire la figure de l\'exercice 21', 'Fiche d\'automatismes n° 4'],
        'HG'  => ['Apprendre la leçon et les repères chronologiques', 'Compléter la carte du chapitre', 'Préparer l\'exposé en binôme', 'Répondre aux questions du document 3 p. 58', 'Réviser le vocabulaire du chapitre'],
        'ANG' => ['Learn the vocabulary list (unit 3)', 'Write 10 sentences with the present perfect', 'Prepare your oral presentation', 'Workbook p. 24, ex. 1 to 4', 'Listen to the podcast and take notes'],
        'ESP' => ['Aprender el vocabulario de la casa', 'Ejercicios 2 y 3 p. 41', 'Preparar la presentación oral', 'Conjugar los verbos en pretérito'],
        'SVT' => ['Schéma légendé de la cellule', 'Revoir le TP sur la germination', 'Apprendre le bilan du chapitre', 'Exercice « Je m\'entraîne » p. 76'],
        'PC'  => ['Compte rendu du TP', 'Exercices sur les circuits électriques', 'Apprendre les définitions (masse, volume)', 'Exercices 5 et 8 p. 90'],
        'TEC' => ['Terminer la fiche projet', 'Rechercher le fonctionnement d\'un capteur', 'Réaliser le croquis de l\'objet technique'],
        'ART' => ['Apporter des magazines pour le collage', 'Terminer la production en couleur', 'Trouver une œuvre d\'art sur le thème du mouvement'],
        'MUS' => ['Écouter le morceau étudié et noter 3 instruments', 'Apprendre le refrain du chant', 'Fiche sur le compositeur'],
        'EPS' => ['Apporter une tenue adaptée (endurance)', 'Prévoir maillot et bonnet (piscine)', 'Apporter une gourde'],
    ];
    $devRows = []; $devId = 0; $devoirsLea = []; $dmIds = [];
    foreach ($seancesParClasseMatiere as $key => $idx) {
        [$classe, $code] = explode('|', $key);
        $profId = $profDeClasse[$classe][$code];
        foreach ($idx as $j => $si) {
            [$edtId, $d] = $seances[$si];
            if (mt_rand(1, 100) > ($code === 'EPS' || $code === 'MUS' || $code === 'ART' ? 20 : 48)) { continue; }
            $rendu = isset($idx[$j + 1]) ? $seances[$idx[$j + 1]][1] : $d->modify('+7 days');
            $titre = $pick($sujets[$code]);
            $isDM = str_starts_with($titre, 'DM') || str_starts_with($titre, 'Rédaction');
            if ($isDM) { $rendu = $nextSchoolDay($rendu->modify('+7 days')); }
            $devId++;
            $devRows[] = ['id' => $devId, 'etablissement_id' => $ETAB, 'titre' => $titre,
                'description' => ($isDM ? "Travail à rendre sur feuille double ou en ligne.\n" : '') . 'Pour la séance du ' . $rendu->format('d/m') . '.',
                'classe' => $classe, 'nom_matiere' => $matDef[$code][0], 'nom_professeur' => $profName[$profId],
                'date_ajout' => $d->format('Y-m-d'), 'date_rendu' => $rendu->format('Y-m-d'), 'date_creation' => $d->format('Y-m-d') . ' ' . $edt[$edtId][7] . ':00'];
            if ($classe === '4A') { $devoirsLea[$devId] = $rendu; }
            if ($isDM) { $dmIds[$devId] = [$classe, $rendu, $profId]; }
        }
    }
    $bulk('devoirs', $devRows, 500);
    // Léa coche ses devoirs faits (presque tous ceux qui sont passés)
    $st = [];
    foreach ($devoirsLea as $id => $rendu) {
        if ($rendu <= $today && mt_rand(1, 100) <= 93) { $st[] = ['eleve_id' => $lea, 'devoir_id' => $id, 'fait' => 1, 'date_marque' => $rendu->modify('-1 day')->format('Y-m-d 19:30:00')]; }
    }
    $bulk('devoirs_statuts_eleve', $st);
    // Devoirs maison rendus en ligne (et corrigés quand l'échéance est passée)
    $rendus = [];
    foreach ($dmIds as $id => [$classe, $rendu, $profId]) {
        foreach ($elevesParClasse[$classe] as $eid) {
            if (mt_rand(1, 100) > 82) { continue; }
            $quand = $rendu->modify('-' . mt_rand(0, 4) . ' days')->setTime(mt_rand(16, 22), mt_rand(0, 59));
            if ($quand > $NOW) { continue; }
            $corrige = $rendu < $today->modify('-3 days');
            $note = $corrige ? round(max(4, min(20, $gauss($eleves[$eid][3], 2.5))) * 2) / 2 : null;
            $rendus[] = ['devoir_id' => $id, 'eleve_id' => $eid, 'contenu' => 'Copie rendue en ligne.', 'date_rendu' => $quand->format('Y-m-d H:i:s'),
                'en_retard' => mt_rand(1, 100) <= 6 ? 1 : 0, 'note' => $note, 'note_sur' => 20,
                'commentaire_prof' => $corrige ? $pick(['Bon travail.', 'Soigne la présentation.', 'Raisonnement juste, rédaction à améliorer.', 'Très bien.', 'Incomplet.']) : null,
                'date_correction' => $corrige ? $rendu->modify('+2 days')->format('Y-m-d 18:00:00') : null, 'statut' => $corrige ? 'corrige' : 'rendu'];
        }
    }
    $bulk('devoirs_rendus', $rendus, 500);
    $log(sprintf('%d devoirs au cahier de textes, %d copies rendues en ligne', count($devRows), count($rendus)));
} catch (\Throwable $e) { $fail('cahier de textes', $e); }

try {
    // ── Compétences du socle et évaluations ──
    $wipe('competences', 'competence_evaluations');
    $socle = [
        ['D1.1', 'Comprendre, s\'exprimer en utilisant la langue française', 'Langages pour penser et communiquer', ['FRA', 'HG']],
        ['D1.2', 'Comprendre, s\'exprimer en utilisant une langue étrangère', 'Langages pour penser et communiquer', ['ANG', 'ESP']],
        ['D1.3', 'Comprendre, s\'exprimer en utilisant les langages mathématiques, scientifiques et informatiques', 'Langages pour penser et communiquer', ['MAT', 'PC', 'TEC']],
        ['D1.4', 'Comprendre, s\'exprimer en utilisant les langages des arts et du corps', 'Langages pour penser et communiquer', ['ART', 'MUS', 'EPS']],
        ['D2', 'Les méthodes et outils pour apprendre', 'Méthodes et outils', ['FRA', 'MAT', 'HG', 'SVT', 'TEC']],
        ['D3', 'La formation de la personne et du citoyen', 'Formation de la personne', ['HG', 'EPS']],
        ['D4', 'Les systèmes naturels et les systèmes techniques', 'Systèmes naturels et techniques', ['SVT', 'PC', 'TEC', 'MAT']],
        ['D5', 'Les représentations du monde et l\'activité humaine', 'Représentations du monde', ['HG', 'ART', 'MUS']],
    ];
    $compIds = []; $compMat = [];
    foreach ($socle as $o => [$code, $nom, $dom, $mats]) {
        $compIds[$code] = $ins('competences', ['etablissement_id' => $ETAB, 'code' => $code, 'nom' => $nom, 'description' => $nom . ' (socle commun, cycle 4).', 'domaine' => $dom, 'niveau' => 4, 'niveau_attendu' => 'acquis', 'ordre' => $o + 1, 'actif' => 1]);
        foreach ($mats as $m) { $compMat[$m][] = $code; }
    }
    $niv = static fn(float $x) => $x >= 15.5 ? 'depasse' : ($x >= 11.5 ? 'acquis' : ($x >= 8.5 ? 'en_cours' : 'non_acquis'));
    $ce = [];
    foreach ($evals as [$classe, $code, $d, $type, $coef, $profId]) {
        // les évaluations « de compétences » + environ un tiers des autres sont aussi positionnées sur le socle
        if (empty($compMat[$code]) || (!str_starts_with($type, 'Évaluation de compétences') && mt_rand(1, 100) > 35)) { continue; }
        $comp = $pick($compMat[$code]);
        foreach ($elevesParClasse[$classe] as $eid) {
            // une seule évaluation par élève, compétence et période (contrainte unique) : la plus récente l'emporte
            $ce[$eid . '|' . $comp . '|' . $trimestreDe($d->format('Y-m-d'))] = ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'competence_id' => $compIds[$comp], 'professeur_id' => $profId, 'matiere_id' => $mat[$code],
                'niveau_acquis' => $niv($gauss($eleves[$eid][3] + ($biais[$eid][$code] ?? 0), 2.0)), 'date_evaluation' => $d->format('Y-m-d'),
                'periode_id' => $periodeIds[$trimestreDe($d->format('Y-m-d'))] ?? null, 'date_creation' => $d->format('Y-m-d 18:00:00')];
        }
    }
    $ce = array_values($ce);
    $bulk('competence_evaluations', $ce, 800);
    $log(count($ce) . ' évaluations de compétences');
} catch (\Throwable $e) { $fail('compétences', $e); }

try {
    // ── Ressources pédagogiques + modèles de cahier ──
    $wipe('ressources_pedagogiques', 'cahier_templates');
    $res = [];
    $resTitres = ['MAT' => ['Fiche méthode : résoudre une équation', 'Exercices corrigés — Pythagore', 'Vidéo : les fractions en 5 minutes', 'QCM d\'automatismes', 'Cours : proportionnalité'],
        'FRA' => ['Fiche : les figures de style', 'Méthode du commentaire de texte', 'Lecture audio — Le Horla', 'Exercices de conjugaison', 'Carte mentale : le récit'],
        'HG' => ['Frise chronologique — XIXe siècle', 'Croquis : la France urbaine', 'Vidéo : la Révolution française', 'Fiche révision : la guerre froide'],
        'ANG' => ['Irregular verbs list', 'Podcast — Daily routines', 'Grammar sheet: present perfect'], 'ESP' => ['Vocabulario: la ciudad', 'Ficha: el pretérito indefinido'],
        'SVT' => ['Schéma : la digestion', 'TP virtuel : la photosynthèse', 'Fiche : les volcans'], 'PC' => ['Simulation : circuit en série', 'Fiche : masse et volume'],
        'TEC' => ['Tutoriel : modéliser en 3D', 'Fiche : les capteurs'], 'ART' => ['Galerie : l\'art du mouvement'], 'MUS' => ['Playlist : orchestre symphonique'], 'EPS' => ['Programme d\'échauffement']];
    foreach ($resTitres as $code => $ts) {
        foreach ($ts as $t) {
            $classe = $pick(array_keys($profDeClasse));
            $auteur = $profDeClasse[$classe][$code] ?? $pick(array_keys($profName));
            $type = str_starts_with($t, 'Vidéo') ? 'video' : (str_starts_with($t, 'QCM') ? 'qcm' : (str_contains($t, 'Exercices') ? 'exercice' : 'cours'));
            $res[] = ['etablissement_id' => $ETAB, 'titre' => $t, 'description' => $t . ' — ressource partagée avec les élèves.', 'type' => $type, 'matiere_id' => $mat[$code],
                'classe_id' => $classes[$classe], 'auteur_id' => $auteur, 'contenu' => "## $t\n\nÀ retravailler à la maison avant le prochain cours.", 'difficulte' => $pick(['facile', 'moyen', 'difficile']),
                'niveau' => $classesNoms[$classe], 'tags' => strtolower($matDef[$code][0]), 'publie' => 1, 'vues' => mt_rand(8, 240),
                'created_at' => $pick($joursClasse)->format('Y-m-d 18:30:00')];
        }
    }
    $bulk('ressources_pedagogiques', $res);
    foreach (['Correction de contrôle', 'Séance de TP', 'Activité de découverte'] as $t) {
        $ins('cahier_templates', ['etablissement_id' => $ETAB, 'professeur_id' => $P, 'matiere_id' => $mat['MAT'], 'titre' => $t, 'contenu' => "Objectif :\nDéroulé :\nTravail à faire :", 'tags' => json_encode(['modèle'])]);
    }
    // Évaluations en ligne : banques de questions des professeurs, évaluations ouvertes, copies corrigées
    if ($hasTable('evaluations_en_ligne')) {
        $wipe('evaluations_en_ligne', 'evaluation_banques', 'evaluation_questions', 'evaluation_sessions', 'evaluation_reponses');
        $bankDef = [
            [$P, 'MAT', 'Géométrie — 4e', [['qcm', 'Dans un triangle rectangle, le plus grand côté s\'appelle :', ['l\'hypoténuse', 'la médiane', 'la hauteur'], 0],
                ['vrai_faux', 'Le théorème de Pythagore s\'applique à tous les triangles.', ['Vrai', 'Faux'], 1],
                ['qcm', 'Si AB = 3 et AC = 4 (triangle rectangle en A), BC vaut :', ['5', '7', '12'], 0],
                ['qcm', 'La somme des angles d\'un triangle vaut :', ['90°', '180°', '360°'], 1]]],
            [$P, 'MAT', 'Calcul littéral — 3e', [['qcm', 'Développer 2(x + 3) donne :', ['2x + 3', '2x + 6', 'x + 6'], 1],
                ['qcm', 'Factoriser x² − 9 :', ['(x − 3)(x + 3)', '(x − 9)(x + 1)', '(x − 3)²'], 0],
                ['vrai_faux', '(a + b)² = a² + b²', ['Vrai', 'Faux'], 1]]],
            [$profDeClasse['5B']['ANG'], 'ANG', 'Vocabulary — unit 2', [['qcm', '« Cuisine » in English is:', ['kitchen', 'chicken', 'kitten'], 0], ['qcm', 'Past of « go »:', ['goed', 'went', 'gone'], 1]]],
            [$profDeClasse['6C']['SVT'], 'SVT', 'La cellule — 6e', [['qcm', 'L\'unité de base du vivant est :', ['la cellule', 'l\'atome', 'l\'organe'], 0], ['vrai_faux', 'Toutes les cellules ont un noyau.', ['Vrai', 'Faux'], 1]]],
        ];
        $nEv = 0; $nSess = 0;
        foreach ($bankDef as $k => [$pid, $code, $titre, $questions]) {
            $bid = $ins('evaluation_banques', ['etablissement_id' => $ETAB, 'titre' => $titre, 'matiere_id' => $mat[$code], 'professeur_id' => $pid, 'description' => "Banque de questions : $titre.", 'statut' => 'publiee',
                'created_at' => $rentree->modify('+' . (10 + $k) . ' days')->format('Y-m-d 18:00:00')]);
            $qids = [];
            foreach ($questions as [$type, $enonce, $choix, $bonne]) {
                $qids[] = [$ins('evaluation_questions', ['banque_id' => $bid, 'type_question' => $type, 'enonce' => $enonce, 'reponses_possibles' => json_encode($choix, JSON_UNESCAPED_UNICODE),
                    'reponse_correcte' => json_encode([$choix[$bonne]], JSON_UNESCAPED_UNICODE), 'points' => 2, 'difficulte' => 'moyen', 'explication' => 'Voir le cours.']), $choix, $bonne];
            }
            $cl = ['MAT' => $k === 0 ? '4A' : '3A', 'ANG' => '5B', 'SVT' => '6C'][$code];
            $ouv = $today->modify('-' . (12 - 2 * $k) . ' days'); $ferm = $ouv->modify('+10 days');
            $evId = $ins('evaluations_en_ligne', ['etablissement_id' => $ETAB, 'titre' => $titre, 'professeur_id' => $pid, 'matiere_id' => $mat[$code], 'classe' => $cl,
                'questions_config' => json_encode(array_column($qids, 0)), 'duree_minutes' => 20, 'date_ouverture' => $ouv->format('Y-m-d 08:00:00'), 'date_fermeture' => $ferm->format('Y-m-d 20:00:00'),
                'mode' => $k === 1 ? 'examen' : 'entrainement', 'anti_triche' => $k === 1 ? 1 : 0, 'melanger_questions' => 1, 'melanger_reponses' => 1, 'note_sur' => 20,
                'statut' => $ferm < $today ? 'fermee' : 'ouverte', 'created_at' => $ouv->modify('-1 day')->format('Y-m-d 18:00:00')]);
            $nEv++;
            foreach ($elevesParClasse[$cl] as $eid) {
                if (!$chance(0.8)) { continue; }
                $deb = $ouv->modify('+' . mt_rand(0, 6) . ' days')->setTime(mt_rand(17, 21), mt_rand(0, 59));
                if ($deb > $NOW) { continue; }
                $sid = $ins('evaluation_sessions', ['evaluation_id' => $evId, 'eleve_id' => $eid, 'date_debut' => $deb->format('Y-m-d H:i:s'), 'date_fin' => $deb->modify('+' . mt_rand(6, 19) . ' minutes')->format('Y-m-d H:i:s'),
                    'note_sur' => 20, 'statut' => 'corrige', 'created_at' => $deb->format('Y-m-d H:i:s')]);
                $pts = 0; $max = 0; $rep = [];
                foreach ($qids as [$qid, $choix, $bonne]) {
                    $ok = mt_rand() / mt_getrandmax() < min(0.95, max(0.25, ($eleves[$eid][3] - 4) / 14));
                    $donnee = $ok ? $choix[$bonne] : $choix[($bonne + 1) % count($choix)];
                    $rep[] = ['session_id' => $sid, 'question_id' => $qid, 'reponse_donnee' => json_encode([$donnee], JSON_UNESCAPED_UNICODE), 'correct' => $ok ? 1 : 0, 'points_obtenus' => $ok ? 2 : 0, 'created_at' => $deb->format('Y-m-d H:i:s')];
                    $pts += $ok ? 2 : 0; $max += 2;
                }
                $bulk('evaluation_reponses', $rep);
                $pdo->prepare('UPDATE evaluation_sessions SET score = ? WHERE id = ?')->execute([round($pts / max(1, $max) * 20, 2), $sid]);
                $nSess++;
            }
        }
    }
    $log(count($res) . " ressources pédagogiques, $nEv évaluations en ligne ($nSess copies)");
} catch (\Throwable $e) { $fail('ressources', $e); }

try {
    // ── Examens : évaluations nationales de 6e (faites) + brevet blanc (3e, à venir ou passé selon la date) ──
    $wipe('examens', 'epreuves', 'epreuve_convocations', 'epreuve_surveillants');
    $exams = [
        ['Évaluations nationales de 6e', 'controle', $nextSchoolDay(new DateTimeImmutable("$startYear-09-15")), ['FRA' => 'Français — compréhension de l\'écrit', 'MAT' => 'Mathématiques — automatismes'], '6', 50],
        ['Brevet blanc n° 1', 'brevet', $nextSchoolDay(new DateTimeImmutable(($startYear + 1) . '-01-19')), ['FRA' => 'Français', 'MAT' => 'Mathématiques', 'HG' => 'Histoire-géographie / EMC', 'SVT' => 'Sciences'], '3', 120],
        ['Brevet blanc n° 2', 'brevet', $nextSchoolDay(new DateTimeImmutable(($startYear + 1) . '-04-13')), ['FRA' => 'Français', 'MAT' => 'Mathématiques', 'HG' => 'Histoire-géographie / EMC', 'SVT' => 'Sciences'], '3', 120],
    ];
    foreach ($exams as [$nom, $type, $d0, $epr, $niveauPrefix, $duree]) {
        $fin = $d0->modify('+' . (count($epr) > 2 ? 1 : 0) . ' days');
        $statut = $fin < $today ? 'termine' : ($d0 <= $today ? 'en_cours' : 'planifie');
        $exId = $ins('examens', ['etablissement_id' => $ETAB, 'nom' => $nom, 'type' => $type, 'date_debut' => $d0->format('Y-m-d'), 'date_fin' => $fin->format('Y-m-d'),
            'description' => $nom . ' — convocations envoyées aux familles.', 'statut' => $statut, 'created_by' => $adminId, 'created_at' => $rentree->format('Y-m-d 09:00:00')]);
        $i = 0;
        $concernes = array_merge(...array_map(fn($c) => $elevesParClasse[$c], array_filter(array_keys($classesNoms), fn($c) => str_starts_with($c, $niveauPrefix))));
        foreach ($epr as $code => $intitule) {
            $quand = $d0->modify('+' . intdiv($i, 2) . ' days')->setTime($i % 2 ? 14 : 8, 0);
            $epId = $ins('epreuves', ['etablissement_id' => $ETAB, 'examen_id' => $exId, 'matiere_id' => $mat[$code], 'intitule' => $intitule, 'date_epreuve' => $quand->format('Y-m-d H:i:s'),
                'duree_minutes' => $duree, 'salle_id' => $salles['reunion'][0] ?? null, 'coefficient' => 1, 'type' => 'ecrit', 'consignes' => 'Calculatrice ' . ($code === 'MAT' ? 'autorisée (mode examen).' : 'interdite.')]);
            $conv = [];
            foreach ($concernes as $n => $eid) {
                $fait = $statut === 'termine';
                $conv[] = ['epreuve_id' => $epId, 'eleve_id' => $eid, 'place' => 'Rang ' . chr(65 + intdiv($n, 20)) . ' — ' . ($n % 20 + 1),
                    'present' => $fait ? (mt_rand(1, 100) <= 97 ? 1 : 0) : null, 'note' => $fait ? round(max(2, min(20, $gauss($eleves[$eid][3], 2.6))) * 2) / 2 : null];
            }
            $bulk('epreuve_convocations', $conv);
            foreach (array_slice(array_keys($profName), $i * 3, 3) as $r => $pid) { $ins('epreuve_surveillants', ['epreuve_id' => $epId, 'professeur_id' => $pid, 'role' => $r === 0 ? 'responsable' : 'surveillant']); }
            $i++;
        }
    }
    $log('3 examens (évaluations de 6e, deux brevets blancs) avec convocations');
} catch (\Throwable $e) { $fail('examens', $e); }

try {
    // ── Bulletins + conseils de classe : trimestres terminés = publiés ; trimestre en cours = conseils planifiés ──
    $wipe('bulletins', 'bulletin_matieres', 'conseil_classe_sessions', 'conseil_classe_participants', 'conseil_classe_eleve_discussions', 'conseil_classe_synthese', 'conseil_classe_votes');
    $avisDe = static fn(float $m) => $m >= 16 ? 'felicitations' : ($m >= 14 ? 'compliments' : ($m >= 12.5 ? 'encouragements' : ($m < 8.5 ? 'avertissement_travail' : 'aucun')));
    $appGen = [
        'felicitations' => ['Excellent trimestre, travail sérieux et régulier. Félicitations !', 'Trimestre remarquable dans toutes les disciplines.'],
        'compliments' => ['Très bon trimestre, continuez ainsi.', 'Bons résultats, une attitude positive en classe.'],
        'encouragements' => ['Des efforts visibles, encouragements à poursuivre.', 'Trimestre satisfaisant, il faut maintenir cette dynamique.'],
        'aucun' => ['Résultats corrects mais un investissement plus régulier est attendu.', 'Ensemble moyen, la participation orale doit progresser.'],
        'avertissement_travail' => ['Trimestre insuffisant : le travail personnel doit impérativement s\'intensifier.', 'Résultats très fragiles, un sursaut est nécessaire.'],
    ];
    foreach ($periodes as [$num, $nomP, $deb, $fin]) {
        $termine = $fin < $today->format('Y-m-d');
        $finD = new DateTimeImmutable($fin);
        foreach (array_keys($classesNoms) as $ci => $classe) {
            // conseil : la semaine qui suit la fin du trimestre, 17 h 15, deux conseils par soir
            $dc = $nextSchoolDay($finD->modify('+' . (1 + intdiv($ci, 2)) . ' days'))->setTime($ci % 2 ? 18 : 17, $ci % 2 ? 30 : 15);
            if ($num > $trimestreCourant + 0 && !$termine) { continue; } // trimestres futurs : rien
            $sess = $ins('conseil_classe_sessions', ['etablissement_id' => $ETAB, 'classe_id' => $classe, 'periode_id' => $periodeIds[$num], 'annee_scolaire' => $annee,
                'date_conseil' => $dc->format('Y-m-d H:i:s'), 'lieu' => 'Salle de réunion', 'president_id' => $ci % 2 ? $adjointId : $adminId, 'president_type' => 'administrateur',
                'secretaire_id' => $ppDe[$classe], 'secretaire_type' => 'professeur', 'statut' => $termine && $dc < $NOW ? 'termine' : 'planifie', 'created_at' => $rentree->format('Y-m-d 09:00:00')]);
            $partic = [['user_id' => $ci % 2 ? $adjointId : $adminId, 'user_type' => 'administrateur', 'role' => 'president'], ['user_id' => $ppDe[$classe], 'user_type' => 'professeur', 'role' => 'secretaire'], ['user_id' => $cpeId, 'user_type' => 'vie_scolaire', 'role' => 'CPE']];
            foreach (array_unique(array_values($profDeClasse[$classe])) as $pid) { if ($pid !== $ppDe[$classe]) { $partic[] = ['user_id' => $pid, 'user_type' => 'professeur', 'role' => 'professeur']; } }
            $partic[] = ['user_id' => $parentsDe[$elevesParClasse[$classe][0]][0], 'user_type' => 'parent', 'role' => 'delegue_parent'];
            $partic[] = ['user_id' => $elevesParClasse[$classe][1], 'user_type' => 'eleve', 'role' => 'delegue_eleve'];
            $bulk('conseil_classe_participants', array_map(fn($p) => $p + ['session_id' => $sess, 'present' => $termine ? (mt_rand(1, 100) <= 92 ? 1 : 0) : null], $partic));
            if (!$termine || $dc > $NOW) { continue; } // bulletins seulement après le conseil
            // moyennes du trimestre
            $moy = []; $moyMat = [];
            foreach ($elevesParClasse[$classe] as $eid) {
                $somme = 0; $nb = 0;
                foreach ($notesEleveMatiere[$eid] ?? [] as $code => $parTri) {
                    $ns = $parTri[$num] ?? []; if (!$ns) { continue; }
                    $m = array_sum(array_map(fn($x) => $x[0] * $x[1], $ns)) / array_sum(array_column($ns, 1));
                    $moyMat[$eid][$code] = $m; $somme += $m; $nb++;
                }
                if ($nb) { $moy[$eid] = $somme / $nb; }
            }
            arsort($moy); $rang = 0;
            $disc = [];
            foreach ($moy as $eid => $m) {
                $rang++;
                $avis = $avisDe($m);
                $bid = $ins('bulletins', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'classe_id' => $classes[$classe], 'periode_id' => $periodeIds[$num], 'annee_scolaire' => $annee,
                    'moyenne_generale' => round($m, 2), 'rang' => $rang, 'appreciation_generale' => $pick($appGen[$avis]), 'appreciation_vie_scolaire' => $pick(['Élève ponctuel et respectueux.', 'Attention aux bavardages.', 'Comportement exemplaire.', 'Assiduité à améliorer.']),
                    'avis_conseil' => $avis, 'nb_absences' => mt_rand(0, 6), 'nb_retards' => mt_rand(0, 4), 'statut' => 'publie', 'consulte_par_parent' => mt_rand(1, 100) <= 78 ? 1 : 0,
                    'valide_par' => $adminId, 'date_validation' => $dc->modify('+2 days')->format('Y-m-d 10:00:00'), 'date_publication' => $dc->modify('+4 days')->format('Y-m-d 08:00:00'), 'date_creation' => $dc->format('Y-m-d H:i:s')]);
                $bm = [];
                foreach ($moyMat[$eid] as $code => $mm) {
                    $col = array_filter(array_map(fn($e) => $moyMat[$e][$code] ?? null, $elevesParClasse[$classe]), fn($v) => $v !== null);
                    $bm[] = ['bulletin_id' => $bid, 'matiere_id' => $mat[$code], 'professeur_id' => $profDeClasse[$classe][$code], 'moyenne_eleve' => round($mm, 2),
                        'moyenne_classe' => round(array_sum($col) / count($col), 2), 'moyenne_min' => round(min($col), 2), 'moyenne_max' => round(max($col), 2),
                        'appreciation' => $pick($appreciations[$mm >= 15 ? 'high' : ($mm >= 10 ? 'mid' : 'low')]), 'coefficient' => 1];
                }
                $bulk('bulletin_matieres', $bm);
                $disc[] = ['session_id' => $sess, 'eleve_id' => $eid, 'ordre' => $rang, 'appreciation' => $pick($appGen[$avis]), 'avis_propose' => $avis, 'avis_final' => $avis,
                    'avis_vote_pour' => count($partic) - 3, 'avis_vote_contre' => 0, 'avis_vote_abstention' => mt_rand(0, 2)];
            }
            $bulk('conseil_classe_eleve_discussions', $disc);
            $vals = array_values($moy);
            $ins('conseil_classe_synthese', ['session_id' => $sess, 'synthese_generale' => sprintf('Classe %s au climat de travail %s. Moyenne de classe : %.2f.', $classe, $pick(['agréable', 'sérieux', 'dynamique mais bavard']), array_sum($vals) / max(1, count($vals))),
                'points_positifs' => 'Participation orale, entraide entre élèves.', 'points_amelioration' => 'Régularité du travail personnel, soin des cahiers.', 'decisions' => 'Point d\'étape avec les familles des élèves en difficulté.']);
        }
    }
    $log('conseils de classe et bulletins des trimestres écoulés');
} catch (\Throwable $e) { $fail('bulletins', $e); }
