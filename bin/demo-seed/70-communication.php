<?php
/** Démo — communication : messagerie, annonces et sondages, agenda, notifications, enquêtes,
 *  signalements, documents (avec de vrais fichiers PDF téléchargeables). */
declare(strict_types=1);

try {
    // ── Messagerie : des fils réalistes sur toute la période, dont beaucoup avec les comptes démo ──
    $wipe('conversations', 'conversation_participants', 'messages');
    $nbConv = 0; $nbMsg = 0;
    $conv = function (string $subject, string $type, array $participants, array $messages, DateTimeImmutable $start) use ($ins, $bulk, $ETAB, $pdo, $NOW, &$nbConv, &$nbMsg): void {
        $cid = $ins('conversations', ['etablissement_id' => $ETAB, 'subject' => $subject, 'type' => $type, 'allow_replies' => $type === 'information' ? 0 : 1,
            'created_at' => $start->format('Y-m-d H:i:s'), 'updated_at' => $start->format('Y-m-d H:i:s')]);
        $t = $start; $last = null; $lastSender = null; $ids = [];
        foreach ($messages as $i => [$who, $body]) {
            if ($i > 0) { $t = $t->modify('+' . mt_rand(1, 26) . ' hours'); }
            $h = (int) $t->format('G');
            if ($h < 7 || $h > 21) { $t = $t->setTime(mt_rand(8, 20), mt_rand(0, 59)); }
            if ($t > $NOW) { $t = $NOW->modify('-' . (count($messages) - $i) * mt_rand(9, 50) . ' minutes'); }
            [$sid, $stype] = $participants[$who];
            $last = $ins('messages', ['conversation_id' => $cid, 'sender_id' => $sid, 'sender_type' => $stype, 'body' => $body, 'created_at' => $t->format('Y-m-d H:i:s'), 'status' => 'normal']);
            $ids[] = [$last, "$stype:$sid"]; $lastSender = "$stype:$sid"; $nbMsg++;
        }
        $rows = [];
        foreach ($participants as $k => [$uid, $utype]) {
            $me = "$utype:$uid";
            // non lus = messages des autres après mon dernier message ; les vieux fils sont lus
            $unread = 0;
            foreach (array_reverse($ids) as [$mid, $snd]) { if ($snd === $me) { break; } $unread++; }
            if ($t < $NOW->modify('-5 days')) { $unread = 0; }
            $rows[] = ['conversation_id' => $cid, 'user_id' => $uid, 'user_type' => $utype, 'joined_at' => $start->format('Y-m-d H:i:s'), 'unread_count' => $unread,
                'last_read_message_id' => $unread ? null : $last, 'last_read_at' => $unread ? null : $t->format('Y-m-d H:i:s'), 'is_admin' => $k === 0 ? 1 : 0];
        }
        $bulk('conversation_participants', $rows);
        $pdo->prepare('UPDATE conversations SET last_message_id=?, updated_at=? WHERE id=?')->execute([$last, $t->format('Y-m-d H:i:s'), $cid]);
        $nbConv++;
    };
    $ago = fn(int $days, int $h = 0) => $NOW->modify("-$days days")->setTime($h ?: mt_rand(8, 19), mt_rand(0, 59));
    $profFr4A = $profDeClasse['4A']['FRA']; $profHg4A = $profDeClasse['4A']['HG']; $profAng4A = $profDeClasse['4A']['ANG']; $profEps4A = $profDeClasse['4A']['EPS']; $pp6B = $ppDe['6B'];
    $pName = fn($id) => $profName[$id];
    // — avec les comptes démo —
    $conv('Sortie au musée des Sciences — autorisation', 'individuelle', [[$P, 'professeur'], [$parentDemo, 'parent']], [
        [0, "Bonjour Madame Martin,\n\nLa classe de 4A visitera le musée des Sciences dans quelques jours. Merci de signer l'autorisation dans l'espace famille avant lundi.\n\nCordialement,\nM. Lefort"],
        [1, "Bonjour Monsieur Lefort, c'est fait, merci ! Léa est ravie. Faut-il prévoir un pique-nique ?"],
        [0, "Oui, un pique-nique et une tenue confortable. Départ 8 h 15 devant le collège."],
        [1, "Parfait, merci beaucoup."]], $ago(9));
    $conv('Question sur le DM de géométrie', 'individuelle', [[$lea, 'eleve'], [$P, 'professeur']], [
        [0, "Bonjour Monsieur, pour l'exercice 3 du DM, faut-il utiliser le théorème de Pythagore ou la trigonométrie ?"],
        [1, "Bonjour Léa, Pythagore suffit pour la question a). La trigonométrie viendra au chapitre suivant. Bon courage !"],
        [0, "Merci beaucoup !"]], $ago(4));
    $conv('Conseil pédagogique — progression des 4e', 'groupe', [[$adminId, 'administrateur'], [$P, 'professeur'], [$profFr4A, 'professeur'], [$profHg4A, 'professeur'], [$cpeId, 'vie_scolaire']], [
        [0, "Bonjour à tous,\n\nJe vous propose de faire un point sur la progression des 4e mardi à 17 h 30 en salle de réunion. Merci de préparer vos remarques par classe."],
        [1, "Bien noté. Les 4B ont un peu de retard en calcul littéral, je ferai une proposition de soutien."],
        [4, "Je présenterai le point absentéisme : deux situations à suivre en 4A."],
        [2, "Je serai là. J'aimerais aussi parler du projet théâtre avec les 4A."],
        [0, "Merci à tous, ordre du jour envoyé dans l'agenda."]], $ago(12));
    $conv('Absence de Léa — justificatif', 'individuelle', [[$cpeId, 'vie_scolaire'], [$parentDemo, 'parent']], [
        [1, "Bonjour, Léa était chez l'orthodontiste ce matin. Je dépose le justificatif dans l'espace famille."],
        [0, "Bonjour Madame Martin, justificatif bien reçu, l'absence est régularisée. Bonne journée."]], $ago(6, 9));
    $conv('Rappel : tenue d\'EPS', 'classe', [[$profEps4A, 'professeur'], [$lea, 'eleve']], [
        [0, "Rappel à toute la 4A : cycle endurance à partir de lundi, prévoyez baskets et gourde."]], $ago(10));
    $conv('Hugo — oubli de matériel', 'individuelle', [[$pp6B, 'professeur'], [$parentDemo, 'parent']], [
        [0, "Bonjour Madame Martin,\n\nHugo oublie régulièrement son matériel (cahier de français et trousse). Pourriez-vous vérifier son cartable le soir avec lui ?\n\nCordialement, " . $pName($pp6B)],
        [1, "Bonjour, merci de me prévenir. Nous allons mettre en place une check-list le soir. N'hésitez pas à me tenir informée."],
        [0, "Merci, je vous ferai un retour dans quinze jours."]], $ago(15));
    $conv('Hugo — point d\'étape', 'individuelle', [[$pp6B, 'professeur'], [$parentDemo, 'parent']], [
        [0, "Bonjour, bonne nouvelle : Hugo a toutes ses affaires depuis dix jours et participe davantage. Bravo à lui !"]], $ago(2, 17));
    $conv('Réunion parents-professeurs du 1er trimestre', 'information', [[$adminId, 'administrateur'], [$parentDemo, 'parent'], [$P, 'professeur'], [$cpeId, 'vie_scolaire']], [
        [0, "Madame, Monsieur,\n\nLes rencontres parents-professeurs du premier trimestre approchent. Les créneaux sont réservables dans le module Réunions de votre espace.\n\nLa principale, C. Delmas"]], $ago(7, 8));
    $conv('Exposé d\'histoire en binôme', 'individuelle', [[$profHg4A, 'professeur'], [$lea, 'eleve']], [
        [1, "Bonjour Madame, est-ce que je peux faire mon exposé avec Inès plutôt qu'avec Tom ?"],
        [0, "Bonjour Léa, oui c'est possible si Tom trouve un autre binôme. Tenez-moi au courant d'ici jeudi."],
        [1, "Merci ! Tom va travailler avec Malo."]], $ago(13));
    $conv('Vocabulary test', 'classe', [[$profAng4A, 'professeur'], [$lea, 'eleve']], [
        [0, "Hello 4A! Reminder: vocabulary test on unit 3 next lesson. Good luck!"]], $ago(1, 18));
    $conv('Stage de 3e — entreprises partenaires', 'groupe', [[$adjointId, 'administrateur'], [$P, 'professeur'], [$ppDe['3A'], 'professeur'], [$ppDe['3B'], 'professeur'], [$ppDe['3C'], 'professeur'], [$ppDe['3D'], 'professeur']], [
        [0, "Bonjour,\n\nVous trouverez dans l'espace documents la liste des entreprises partenaires pour le stage d'observation des 3e. Merci de la diffuser aux élèves."],
        [2, "Merci, je la présente demain en vie de classe."],
        [1, "Plusieurs élèves de 3A cherchent encore : je les oriente vers la liste."]], $ago(18));
    $conv('Retards répétés en 4A', 'groupe', [[$cpeId, 'vie_scolaire'], [$P, 'professeur'], [$adminId, 'administrateur']], [
        [0, "Bonjour, trois élèves de 4A cumulent plus de cinq retards ce mois-ci. Je convoque les familles cette semaine."],
        [1, "Merci Sophie. Je confirme, surtout le lundi matin."],
        [2, "Très bien, tenez-moi informée des suites."]], $ago(3));
    $conv('Commission éducative', 'groupe', [[$adminId, 'administrateur'], [$cpeId, 'vie_scolaire'], [$ppDe['5C'], 'professeur'], [$infId, 'vie_scolaire']], [
        [0, "Une commission éducative est programmée pour un élève de 5C. Merci de m'indiquer vos disponibilités jeudi ou vendredi après 16 h 30."],
        [1, "Jeudi me convient."], [2, "Jeudi aussi."], [3, "Je ne serai disponible que vendredi, désolée."],
        [0, "Nous retenons jeudi 16 h 45. Agnès, je vous ferai un compte rendu."]], $ago(5));
    // — trafic général (familles ↔ professeurs ↔ vie scolaire) réparti sur la période —
    $modeles = [
        ['Absence de %s', [['parent', "Bonjour, %s sera absent(e) demain pour un rendez-vous médical. Merci."], ['cpe', "Bonjour, c'est noté. Merci de déposer le justificatif dans l'espace famille."]]],
        ['Résultats de %s en %s', [['parent', "Bonjour, nous sommes inquiets des derniers résultats de %s. Pourrions-nous en parler ?"], ['prof', "Bonjour, bien sûr. Je vous propose un rendez-vous jeudi à 17 h, ou un échange téléphonique."], ['parent', "Jeudi 17 h nous convient, merci."]]],
        ['Devoir non rendu — %s', [['prof', "Bonjour, %s n'a pas rendu le devoir maison prévu cette semaine. Merci de veiller à ce qu'il soit rendu lundi."], ['parent', "Bonjour, merci de l'information, nous y veillons."]]],
        ['Félicitations à %s', [['prof', "Bonjour, je tenais à vous dire que %s a fait d'excellents progrès ce mois-ci. Bravo !"], ['parent', "Merci beaucoup, cela fait plaisir à toute la famille."]]],
        ['Question sur le cours', [['eleve', "Bonjour, je n'ai pas compris l'exercice 4, pouvez-vous m'expliquer la méthode ?"], ['prof', "Bonjour, regarde l'exemple du cours page 82 et reviens vers moi si besoin. On le corrigera ensemble."]]],
        ['Oubli de carnet — %s', [['cpe', "Bonjour, %s s'est présenté(e) sans carnet de liaison à plusieurs reprises. Merci d'y veiller."], ['parent', "Bonjour, c'est noté, merci."]]],
    ];
    $nbFond = 70;
    for ($k = 0; $k < $nbFond; $k++) {
        $eid = $pick($elevesIds); if ($eid === $lea || $eid === $hugo) { continue; }
        [$p, $n, $classe] = $eleves[$eid];
        $parent = $parentsDe[$eid][0];
        $code = $pick(array_keys($profDeClasse[$classe])); $prof = $profDeClasse[$classe][$code];
        [$sujet, $msgs] = $pick($modeles);
        $parts = []; $idx = [];
        foreach ($msgs as [$role]) {
            if (isset($idx[$role])) { continue; }
            $idx[$role] = count($parts);
            $parts[] = match ($role) { 'parent' => [$parent, 'parent'], 'prof' => [$prof, 'professeur'], 'cpe' => [$pick([$cpeId, $cpe2Id]), 'vie_scolaire'], 'eleve' => [$eid, 'eleve'] };
        }
        $list = array_map(fn($m) => [$idx[$m[0]], sprintf($m[1], $p)], $msgs);
        $conv(sprintf($sujet, "$p $n", $matDef[$code][0]), 'individuelle', $parts, $list, $ago(mt_rand(1, max(2, count($joursClasse) + 10))));
    }
    $log("messagerie : $nbConv conversations, $nbMsg messages");
} catch (\Throwable $e) { $fail('messagerie', $e); }

try {
    // ── Annonces (dont 2 avec sondage), lectures ──
    $wipe('annonces', 'annonces_lues', 'sondages', 'sondage_options', 'sondage_votes');
    $annDef = [
        ['Réunions parents-professeurs', "Les créneaux de rencontre du 1er trimestre sont ouverts à la réservation dans le module Réunions.", 'evenement', 1, 3, null],
        ['Photo de classe', 'Le photographe passera la semaine prochaine. Bons de commande distribués par les professeurs principaux.', 'info', 0, 6, null],
        ['Cross du collège', 'Le cross annuel aura lieu au parc des Sports. Tous les élèves participent, tenue de sport obligatoire.', 'evenement', 0, 9, null],
        ['Alerte météo — transports', 'En cas de vigilance orange, consultez les horaires de bus adaptés dans le module Transports.', 'urgent', 0, 2, null],
        ['Club robotique : inscriptions ouvertes', 'Le club robotique reprend le jeudi midi en salle Techno. 16 places.', 'info', 0, 30, null],
        ['Élection des délégués de classe', 'Les élections des délégués auront lieu en vie de classe. Candidatures auprès du professeur principal.', 'info', 0, 40, 'eleve'],
        ['Menu de la semaine du goût', 'La cantine vous fait découvrir des saveurs du monde toute la semaine !', 'info', 0, 20, null],
        ['Sortie de fin d\'année : votre avis', 'Aidez-nous à choisir la destination de la sortie de fin d\'année des 4e.', 'sondage', 0, 5, null],
        ['Horaires de l\'étude du soir', 'L\'étude surveillée est ouverte lundi, mardi et jeudi de 16 h 30 à 17 h 30 en permanence.', 'info', 0, 35, null],
        ['Prévention du harcèlement', 'Une intervention de prévention aura lieu pour toutes les classes de 6e et 5e. Un numéro d\'écoute : 3018.', 'info', 1, 14, null],
        ['Bourses de collège', 'La campagne de bourses est ouverte. Dossier à déposer avant la fin du mois dans le module Bourses.', 'info', 0, 25, 'parent'],
        ['Journée portes ouvertes du CDI', 'Venez découvrir les nouveautés du CDI : romans, BD et un espace numérique rénové.', 'evenement', 0, 11, null],
        ['Tenue d\'EPS en hiver', 'Avec le froid, prévoyez une tenue chaude pour les cours au plateau sportif.', 'info', 0, 1, 'eleve'],
        ['Club théâtre : quel spectacle ?', 'Votez pour la pièce que le club théâtre montera cette année.', 'sondage', 0, 16, null],
    ];
    $annIds = [];
    foreach ($annDef as $i => [$titre, $contenu, $type, $epingle, $off, $cible]) {
        $d = $NOW->modify("-$off days")->setTime(8, mt_rand(0, 40));
        if ($d < $rentree) { $d = $rentree->modify('+' . $i . ' days')->setTime(8, 0); }
        $annIds[$i] = $ins('annonces', ['etablissement_id' => $ETAB, 'titre' => $titre, 'contenu' => $contenu, 'type' => $type, 'auteur_id' => $i % 3 === 2 ? $adjointId : $adminId, 'auteur_type' => 'administrateur',
            'cible_roles' => $cible, 'publie' => 1, 'notified' => 1, 'epingle' => $epingle, 'date_publication' => $d->format('Y-m-d H:i:s'), 'date_creation' => $d->format('Y-m-d H:i:s')]);
    }
    foreach ([[7, 'Où partir en sortie de fin d\'année ?', ['Parc Astérix', 'Futuroscope', 'Château de Versailles', 'Base de loisirs nautique']], [13, 'Quelle pièce pour le club théâtre ?', ['Le Bourgeois gentilhomme', 'Le Petit Prince (adaptation)', 'Une création des élèves']]] as [$i, $q, $opts]) {
        $sid = $ins('sondages', ['etablissement_id' => $ETAB, 'annonce_id' => $annIds[$i], 'question' => $q, 'type_reponse' => 'choix_unique', 'anonyme' => 1, 'date_fin' => $today->modify('+10 days')->format('Y-m-d 23:59:00'), 'actif' => 1]);
        $oids = [];
        foreach ($opts as $o => $l) { $oids[] = $ins('sondage_options', ['sondage_id' => $sid, 'label' => $l, 'ordre' => $o + 1]); }
        $votes = [];
        foreach (array_rand(array_flip($elevesIds), 180) as $e) { $votes[] = ['sondage_id' => $sid, 'option_id' => $oids[$weighted(array_slice([40, 30, 18, 12], 0, count($oids)))], 'user_id' => $e, 'user_type' => 'eleve', 'date_vote' => $today->modify('-' . mt_rand(0, 4) . ' days')->format('Y-m-d 13:00:00')]; }
        $bulk('sondage_votes', $votes);
    }
    $lues = [];
    foreach ($annIds as $i => $aid) { foreach (array_rand(array_flip($elevesIds), 120) as $e) { $lues[] = ['annonce_id' => $aid, 'user_id' => $e, 'user_type' => 'eleve', 'date_lecture' => $NOW->modify('-' . mt_rand(0, 3) . ' days')->format('Y-m-d H:i:s')]; } }
    $bulk('annonces_lues', $lues, 1000);
    $log(count($annDef) . ' annonces, 2 sondages, ' . count($lues) . ' lectures');
} catch (\Throwable $e) { $fail('annonces', $e); }

try {
    // ── Agenda : la vie du collège, passée et à venir ──
    $wipe('evenements');
    $evts = [
        [-55, 'Réunion de rentrée des 6e', 'reunion', '18:00', '19:30', 'Salle polyvalente', '6A,6B,6C,6D', 'public'],
        [-48, 'Photo de classe', 'autre', '08:00', '17:00', 'Gymnase', null, 'public'],
        [-40, 'Élection des délégués', 'autre', '13:30', '14:25', 'Salles de classe', null, 'eleves'],
        [-33, 'Conseil d\'administration', 'reunion', '18:00', '20:00', 'Salle de réunion', null, 'personnel'],
        [-26, 'Semaine du goût', 'autre', '11:30', '13:30', 'Cantine', null, 'public'],
        [-19, 'Cross du collège', 'sortie', '09:00', '12:00', 'Parc des Sports', null, 'public'],
        [-12, 'Conseil pédagogique', 'reunion', '17:30', '19:00', 'Salle de réunion', null, 'professeurs'],
        [-4, 'Journée portes ouvertes du CDI', 'autre', '12:00', '14:00', 'CDI', null, 'public'],
        [3, 'Brevet blanc — oral de présentation (entraînement)', 'examen', '08:00', '12:00', 'Salles 201 à 204', '3A,3B,3C,3D', 'public'],
        [5, 'Sortie au musée des Sciences — 4A', 'sortie', '08:15', '16:30', 'Musée des Sciences', '4A', 'public'],
        [8, 'Intervention prévention harcèlement', 'autre', '10:05', '11:55', 'Salle polyvalente', '6A,6B,6C,6D,5A,5B,5C,5D', 'public'],
        [12, 'Commémoration du 11 Novembre', 'autre', '10:00', '11:00', 'Cour d\'honneur', '3A,3B,3C,3D', 'public'],
        [15, 'Formation premiers secours (PSC1)', 'reunion', '09:00', '16:00', 'Salle de réunion', null, 'personnel'],
        [24, 'Forum des métiers (3e)', 'sortie', '13:30', '17:00', 'Salle polyvalente', '3A,3B,3C,3D', 'public'],
        [38, 'Spectacle de Noël de la chorale', 'autre', '18:30', '20:00', 'Salle polyvalente', null, 'public'],
    ];
    $ev = [];
    foreach ($evts as [$off, $titre, $type, $h1, $h2, $lieu, $cls, $vis]) {
        $d = $nextSchoolDay($today->modify(($off >= 0 ? '+' : '') . $off . ' days'));
        if ($d < $rentree) { continue; }
        $ev[] = ['etablissement_id' => $ETAB, 'titre' => $titre, 'description' => $titre . ' — ' . $lieu . '.', 'date_debut' => $d->format('Y-m-d') . " $h1:00", 'date_fin' => $d->format('Y-m-d') . " $h2:00",
            'type_evenement' => $type, 'statut' => 'actif', 'createur' => 'Claire Delmas', 'visibilite' => $vis, 'lieu' => $lieu, 'classes' => $cls, 'date_creation' => $d->modify('-15 days')->format('Y-m-d 09:00:00')];
    }
    // Contrôles annoncés par les professeurs (2 semaines à venir) et conseils de classe
    foreach (array_keys($classesNoms) as $c) {
        foreach (['MAT', 'FRA', 'HG'] as $code) {
            $d = $nextSchoolDay($today->modify('+' . mt_rand(1, 14) . ' days'));
            $ev[] = ['etablissement_id' => $ETAB, 'titre' => "Contrôle de " . $matDef[$code][0] . " — $c", 'description' => 'Évaluation sur le chapitre en cours.', 'date_debut' => $d->format('Y-m-d 10:05:00'),
                'date_fin' => $d->format('Y-m-d 11:00:00'), 'type_evenement' => 'examen', 'statut' => 'actif', 'createur' => $profName[$profDeClasse[$c][$code]], 'visibilite' => 'eleves', 'classes' => $c, 'matieres' => $matDef[$code][0],
                'lieu' => 'Salle de classe', 'date_creation' => $today->modify('-' . mt_rand(1, 6) . ' days')->format('Y-m-d 18:00:00')];
        }
    }
    $bulk('evenements', $ev);
    $log(count($ev) . ' événements à l\'agenda');
} catch (\Throwable $e) { $fail('agenda', $e); }

try {
    // ── Notifications : un vrai fil pour chaque compte démo (lu / non lu) ──
    $wipe('notifications_globales');
    $notes = $pdo->prepare('SELECT n.note, n.date_note, n.type_evaluation, m.nom FROM notes n JOIN matieres m ON m.id = n.id_matiere WHERE n.id_eleve = ? ORDER BY n.date_creation DESC LIMIT 12');
    $notes->execute([$lea]);
    $nf = [];
    foreach ($notes->fetchAll(PDO::FETCH_ASSOC) as $k => $r) {
        foreach ([[$lea, 'eleve'], [$parentDemo, 'parent']] as [$uid, $ut]) {
            $quand = (new DateTimeImmutable($r['date_note']))->modify('+' . mt_rand(1, 4) . ' days')->setTime(19, mt_rand(0, 59));
            if ($quand > $NOW) { $quand = $NOW->modify('-' . ($k + 1) * 3 . ' hours'); }
            $nf[] = ['etablissement_id' => $ETAB, 'user_id' => $uid, 'user_type' => $ut, 'type' => 'note', 'titre' => 'Nouvelle note en ' . $r['nom'], 'contenu' => ($ut === 'parent' ? 'Léa : ' : '') . rtrim(rtrim((string) $r['note'], '0'), '.') . '/20 — ' . $r['type_evaluation'],
                'lien' => '/modules/notes/notes.php', 'importance' => 'normale', 'lu' => $k > 2 ? 1 : 0, 'source_type' => 'note', 'date_creation' => $quand->format('Y-m-d H:i:s'), 'group_count' => 1];
        }
    }
    foreach ([
        [$lea, 'eleve', 'devoir', 'Nouveau devoir en Français', 'Rédaction à rendre la semaine prochaine.', '/modules/cahierdetextes/cahierdetextes.php', 0, 20],
        [$lea, 'eleve', 'message', 'Nouveau message de Mme ' . explode(' ', $profName[$profHg4A ?? $P])[1], 'Exposé d\'histoire en binôme', '/modules/messagerie/', 1, 300],
        [$lea, 'eleve', 'evenement', 'Sortie au musée des Sciences', 'Départ 8 h 15 devant le collège.', '/modules/agenda/agenda.php', 0, 30],
        [$parentDemo, 'parent', 'absence', 'Absence régularisée', "L'absence de Léa a été justifiée par la vie scolaire.", '/modules/absences/absences.php', 1, 130],
        [$parentDemo, 'parent', 'message', 'Nouveau message de M. Lefort', 'Sortie au musée — autorisation', '/modules/messagerie/', 0, 40],
        [$parentDemo, 'parent', 'message', 'Nouveau message — ' . $profName[$pp6B], 'Hugo — point d\'étape', '/modules/messagerie/', 0, 6],
        [$parentDemo, 'parent', 'facture', 'Facture de demi-pension disponible', 'Montant à régler avant la fin du mois.', '/modules/facturation/', 1, 200],
        [$parentDemo, 'parent', 'reunion', 'Réunions parents-professeurs', 'Réservez vos créneaux avec les professeurs de Léa et Hugo.', '/modules/reunions/', 0, 26],
        [$P, 'professeur', 'message', 'Nouveau message de Léa Martin', 'Question sur le DM de géométrie', '/modules/messagerie/', 0, 50],
        [$P, 'professeur', 'appel', 'Appel non fait', 'Un appel de la journée reste à faire.', '/modules/appel/appel.php', 0, 1],
        [$P, 'professeur', 'devoir', '18 copies rendues en ligne', 'DM de géométrie — 4A', '/modules/cahierdetextes/cahierdetextes.php', 0, 15],
        [$P, 'professeur', 'conseil', 'Conseil de classe de 4A planifié', 'Préparez vos appréciations avant la date du conseil.', '/modules/conseil_classe/', 1, 160],
        [$cpeId, 'vie_scolaire', 'absence', 'Absences à traiter', 'Des absences non justifiées attendent un suivi.', '/modules/absences/valider_absence.php', 0, 3],
        [$cpeId, 'vie_scolaire', 'incident', 'Nouvel incident signalé', 'Un professeur a signalé un incident en 5C.', '/modules/discipline/incidents.php', 0, 8],
        [$cpeId, 'vie_scolaire', 'message', 'Nouveau message de Mme Martin', 'Absence de Léa — justificatif', '/modules/messagerie/', 1, 140],
        [$adminId, 'administrateur', 'support', 'Demande d\'un professeur', 'Problème d\'impression de bulletin signalé.', '/modules/support/tickets.php', 0, 20],
        [$adminId, 'administrateur', 'reunion', 'Commission éducative jeudi 16 h 45', 'Participants confirmés.', '/modules/reunions/', 0, 30],
        [$adminId, 'administrateur', 'message', 'Nouveau message — Conseil pédagogique', 'Progression des 4e', '/modules/messagerie/', 1, 250],
    ] as [$uid, $ut, $type, $titre, $contenu, $lien, $lu, $hAgo]) {
        $nf[] = ['etablissement_id' => $ETAB, 'user_id' => $uid, 'user_type' => $ut, 'type' => $type, 'titre' => $titre, 'contenu' => $contenu, 'lien' => $lien, 'importance' => 'normale', 'lu' => $lu,
            'source_type' => $type, 'date_creation' => $NOW->modify("-$hAgo hours")->format('Y-m-d H:i:s'), 'group_count' => 1];
    }
    $bulk('notifications_globales', $nf);
    $log(count($nf) . ' notifications');
} catch (\Throwable $e) { $fail('notifications', $e); }

try {
    // ── Enquête « climat scolaire » avec réponses ──
    $wipe('enquetes', 'enquete_pages', 'enquete_questions', 'enquete_participations', 'enquete_reponses');
    $eqId = $ins('enquetes', ['etablissement_id' => $ETAB, 'titre' => 'Climat scolaire — automne', 'description' => 'Votre avis sur la vie au collège (anonyme, 3 minutes).', 'type' => 'climat_scolaire',
        'cible_roles' => json_encode(['eleve']), 'anonyme' => 1, 'multi_pages' => 0, 'date_ouverture' => $today->modify('-12 days')->format('Y-m-d 08:00:00'), 'date_fermeture' => $today->modify('+9 days')->format('Y-m-d 23:59:00'),
        'statut' => 'ouverte', 'creee_par' => $adminId, 'created_at' => $today->modify('-14 days')->format('Y-m-d 10:00:00')]);
    $pg = $ins('enquete_pages', ['enquete_id' => $eqId, 'titre' => 'Votre ressenti', 'ordre' => 1]);
    $qs = [];
    foreach ([['likert', 'Je me sens en sécurité au collège.'], ['likert', 'Je m\'entends bien avec les autres élèves.'], ['likert', 'Les adultes du collège sont à l\'écoute.'], ['nps', 'Recommanderais-tu le collège à un ami ?'], ['texte', 'Qu\'aimerais-tu améliorer ?']] as $o => [$type, $txt]) {
        $qs[$type . $o] = [$ins('enquete_questions', ['page_id' => $pg, 'type' => $type, 'enonce' => $txt, 'obligatoire' => $type === 'texte' ? 0 : 1, 'options' => $type === 'likert' ? json_encode(['Pas du tout', 'Plutôt pas', 'Plutôt', 'Tout à fait']) : null, 'ordre' => $o + 1]), $type];
    }
    $idees = ['Plus de bancs dans la cour', 'Des casiers pour tous', 'Moins d\'attente à la cantine', 'Plus de clubs le midi', 'Des toilettes plus propres', 'Un foyer pour les 3e'];
    $rep = [];
    foreach (array_rand(array_flip($elevesIds), 230) as $k => $e) {
        $par = $ins('enquete_participations', ['enquete_id' => $eqId, 'participant_hash' => hash('sha256', "eq$e"), 'date_soumission' => $today->modify('-' . mt_rand(0, 11) . ' days')->format('Y-m-d H:i:s'), 'completed' => 1]);
        foreach ($qs as [$qid, $type]) {
            if ($type === 'texte') { if (mt_rand(1, 100) <= 40) { $rep[] = ['participation_id' => $par, 'question_id' => $qid, 'valeur_texte' => $pick($idees)]; } continue; }
            $rep[] = ['participation_id' => $par, 'question_id' => $qid, 'valeur_numero' => $type === 'nps' ? $weighted([10 => 12, 9 => 14, 8 => 22, 7 => 18, 6 => 12, 5 => 10, 4 => 6, 3 => 6]) : $weighted([4 => 38, 3 => 40, 2 => 15, 1 => 7])];
        }
    }
    $bulk('enquete_reponses', $rep, 1000);
    $log('enquête climat scolaire : 230 réponses');
} catch (\Throwable $e) { $fail('enquêtes', $e); }

try {
    // ── Signalements (harcèlement, danger…) ──
    $wipe('signalements');
    foreach ([['harcelement', 'Des moqueries répétées visent une élève de 5B sur un groupe de messagerie.', 'haute', 'en_cours', 9, 1],
              ['cyber_harcelement', 'Photo d\'un élève diffusée sans son accord sur un réseau social.', 'haute', 'traite', 24, 0],
              ['danger', 'Grille du portail arrière qui ferme mal, des élèves sortent par là.', 'moyenne', 'clos', 30, 0],
              ['intimidation', 'Un élève de 6C dit se faire prendre son goûter à la récréation.', 'moyenne', 'nouveau', 1, 1]] as [$type, $desc, $urg, $st, $off, $anon]) {
        $d = $today->modify("-$off days");
        $ins('signalements', ['etablissement_id' => $ETAB, 'auteur_id' => $anon ? null : $pick($elevesIds), 'auteur_type' => $anon ? null : 'eleve', 'type' => $type, 'description' => $desc, 'lieu' => $pick(['Cour', 'Réseaux sociaux', 'Couloir', 'Abords du collège']),
            'date_faits' => $d->modify('-1 day')->format('Y-m-d'), 'anonyme' => $anon, 'urgence' => $urg, 'statut' => $st, 'traite_par' => $st !== 'nouveau' ? $cpeId : null,
            'date_traitement' => $st !== 'nouveau' ? $d->modify('+1 day')->format('Y-m-d 11:00:00') : null, 'actions_prises' => $st !== 'nouveau' ? 'Entretiens individuels, familles informées, suivi par la CPE.' : null,
            'confidentiel' => 1, 'tracking_token' => bin2hex(random_bytes(8)), 'date_creation' => $d->format('Y-m-d 16:00:00')]);
    }
} catch (\Throwable $e) { $fail('signalements', $e); }

try {
    // ── Documents de l'établissement : de vrais PDF (générés) téléchargeables ──
    $wipe('documents');
    $dir = dirname(__DIR__, 2) . '/modules/documents/uploads/';
    if (is_dir($dir) || @mkdir($dir, 0755, true)) {
        if (!is_file($dir . '.htaccess')) { @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n"); }
        $pdf = static function (string $titre, array $lignes): string {
            $enc = static fn(string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s));
            $y = 780; $ops = "BT /F1 18 Tf 60 $y Td (" . $enc($titre) . ") Tj ET\n";
            foreach ($lignes as $l) { $y -= 22; $ops .= "BT /F1 11 Tf 60 $y Td (" . $enc($l) . ") Tj ET\n"; }
            $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
                "<< /Length " . strlen($ops) . " >>\nstream\n$ops\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
            $out = "%PDF-1.4\n"; $off = [];
            foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
            $x = strlen($out);
            $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
            foreach ($off as $o) { $out .= sprintf("%010d 00000 n \n", $o); }
            return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
        };
        $docs = [['Règlement intérieur', 'Vie de l\'établissement', '[]', ['Article 1 - Horaires : le collège ouvre à 7 h 45.', 'Article 2 - Assiduité : toute absence doit être justifiée.', 'Article 3 - Respect : chacun respecte les personnes et les biens.', 'Article 4 - Téléphones : éteints et rangés dans l\'enceinte.']],
            ['Calendrier de l\'année ' . $annee, 'Organisation', '[]', ['Rentrée : ' . $rentree->format('d/m/Y'), 'Fin du 1er trimestre : 30/11', 'Fin du 2e trimestre : 28/02', 'Fin des cours : 04/07']],
            ['Liste des entreprises partenaires (stage de 3e)', 'Orientation', '["eleve","parent"]', ['Boulangerie du Centre - 12 rue de la Paix', 'Garage Martin Auto - ZA des Peupliers', 'Mairie de Val-Fleuri - service technique', 'Clinique vétérinaire des Lilas']],
            ['Charte informatique', 'Numérique', '[]', ['Je protège mon mot de passe.', 'J\'utilise le matériel avec soin.', 'Je respecte la vie privée des autres.']],
            ['Protocole d\'évacuation incendie', 'Sécurité', '["professeur","vie_scolaire","administrateur"]', ['Au signal : fermer les fenêtres, laisser les affaires.', 'Sortir dans le calme par l\'issue indiquée.', 'Point de rassemblement : plateau sportif.', 'Le professeur fait l\'appel avec la liste de classe.']],
            ['Menus du mois', 'Cantine', '[]', ['Produits locaux et bio chaque semaine.', 'Menu végétarien disponible tous les jours.']],
            ['Fournitures scolaires 6e', 'Rentrée', '["parent","eleve"]', ['Cahiers grand format 96 pages', 'Trousse complète, règle, compas', 'Calculatrice collège', 'Agenda ou cahier de textes']]];
        foreach ($docs as $k => [$t, $cat, $vis, $lignes]) {
            $bin = $pdf($t, $lignes); $fn = sprintf('demo-%02d.pdf', $k + 1);
            file_put_contents($dir . $fn, $bin);
            $ins('documents', ['etablissement_id' => $ETAB, 'titre' => $t, 'description' => "$t — collège Victor-Hugo.", 'categorie' => $cat, 'fichier_nom' => $t . '.pdf', 'fichier_chemin' => 'uploads/' . $fn,
                'fichier_type' => 'application/pdf', 'fichier_taille' => strlen($bin), 'visibilite' => $vis, 'auteur_id' => $k % 2 ? $adjointId : $adminId, 'auteur_type' => 'administrateur',
                'telechargements' => mt_rand(12, 340), 'actif' => 1, 'date_creation' => $rentree->modify('+' . ($k * 3) . ' days')->format('Y-m-d 09:00:00')]);
        }
        $log(count($docs) . ' documents PDF');
    }
} catch (\Throwable $e) { $fail('documents', $e); }
