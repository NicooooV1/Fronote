<?php
/**
 * Génère des DONNÉES de démonstration réalistes pour visualiser l'application
 * « en pleine activité » : notes, absences, retards, sanctions/exclusions,
 * incidents, retenues, messagerie, devoirs, agenda, infirmerie, suivi élève,
 * bulletins, compétences, appels.
 *
 *   php scripts/seed_demo_data.php          # (ré)injecte les données (idempotent)
 *   php scripts/seed_demo_data.php clean     # supprime uniquement les données de démo
 *
 * Toutes les lignes générées sont marquées par le sentinel « [demo-seed] » (dans un
 * champ texte) ou rattachées à une ligne parente marquée → « clean » les retire sans
 * toucher aux autres données. À lancer après scripts/seed_demo_users.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../API/bootstrap.php';

\API\Core\EstablishmentContext::set(1);
$pdo  = getPDO();
$ETAB = 1;
$SENT = '[demo-seed]';
mt_srand(20260619); // reproductible

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ───────────────────────── Helpers ───────────────────────── */
function pick(array $a) { return $a[array_rand($a)]; }
function chance(int $pct): bool { return mt_rand(1, 100) <= $pct; }
function randDateBetween(string $a, string $b): string {
    $t = mt_rand(strtotime($a), strtotime($b));
    return date('Y-m-d', $t);
}
function ins(PDO $pdo, string $table, array $data): int {
    $cols = array_keys($data);
    $sql  = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $pdo->prepare($sql)->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}
function stripA(string $s): string {
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s));
}

/* ───────────────────────── Fixtures ───────────────────────── */
$eleves   = $pdo->query("SELECT id,nom,prenom,classe FROM eleves WHERE etablissement_id={$ETAB} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$profsAll = $pdo->query("SELECT id,nom,prenom,matiere FROM professeurs WHERE etablissement_id={$ETAB} AND mail LIKE '%@demo.fronote.test' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$vs       = $pdo->query("SELECT id,nom,prenom,est_CPE,est_infirmerie FROM vie_scolaire WHERE etablissement_id={$ETAB} AND mail LIKE '%@demo.fronote.test' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$parents  = $pdo->query("SELECT id,nom,prenom FROM parents WHERE etablissement_id={$ETAB} AND mail LIKE '%@demo.fronote.test' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$admins   = $pdo->query("SELECT id,nom,prenom FROM administrateurs WHERE etablissement_id={$ETAB} AND mail LIKE '%@demo.fronote.test' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$matieres = $pdo->query("SELECT id,nom,coefficient FROM matieres WHERE etablissement_id={$ETAB} AND actif=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$periodes = $pdo->query("SELECT id,numero,nom,date_debut,date_fin FROM periodes WHERE etablissement_id={$ETAB} ORDER BY numero")->fetchAll(PDO::FETCH_ASSOC);
$classesT = $pdo->query("SELECT id,nom FROM classes WHERE etablissement_id={$ETAB} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

if (!$eleves || !$profsAll || !$matieres || !$periodes) {
    exit("Données de référence manquantes (élèves/profs/matières/périodes). Lancez d'abord seed_demo_users.php.\n");
}

// Map matière → professeur (par correspondance de nom, repli round-robin).
$matiereProf = [];
$rr = 0;
foreach ($matieres as $m) {
    $hit = null; $mn = stripA($m['nom']);
    foreach ($profsAll as $p) {
        $pn = stripA((string) $p['matiere']);
        if ($pn !== '' && (strncmp($mn, $pn, min(4, strlen($pn))) === 0 || strpos($mn, $pn) !== false || strpos($pn, $mn) !== false)) { $hit = $p; break; }
    }
    if (!$hit) { $hit = $profsAll[$rr % count($profsAll)]; $rr++; }
    $matiereProf[$m['id']] = $hit;
}

// Map chaîne classe élève (ex "6emeA") → un classe_id réel (cosmétique pour appels/bulletins).
$classeStrings = array_values(array_unique(array_map(fn($e) => $e['classe'], $eleves)));
$classeIdByStr = [];
foreach ($classeStrings as $i => $cs) { $classeIdByStr[$cs] = $classesT[$i % count($classesT)]['id']; }

// Noms d'affichage du personnel (signale_par).
$staffNames = [];
foreach ($vs as $p)  $staffNames[] = "M./Mme {$p['prenom']} {$p['nom']}";
foreach ($profsAll as $p) $staffNames[] = "M./Mme {$p['prenom']} {$p['nom']}";
$cpe = null; $infirmier = null;
foreach ($vs as $p) { if ($p['est_CPE'] === 'oui' && !$cpe) $cpe = $p; if ($p['est_infirmerie'] === 'oui' && !$infirmier) $infirmier = $p; }
$cpe = $cpe ?: ($vs[0] ?? null);
$infirmier = $infirmier ?: ($vs[0] ?? null);
$decideur = $cpe ?: ($admins[0] ?? null);

// Niveau scolaire par élève (pour des moyennes cohérentes/variées).
$niveau = [];
foreach ($eleves as $e) { $niveau[$e['id']] = 7.5 + (($e['id'] * 37) % 90) / 10; } // ~7.5 à 16.5

/* ───────────────────────── CLEAN ───────────────────────── */
function cleanAll(PDO $pdo, string $S, int $ETAB): array {
    $like = '%' . $S . '%';
    $n = [];
    $del = function (string $sql, array $p = []) use ($pdo, &$n) {
        $st = $pdo->prepare($sql); $st->execute($p);
        $t = preg_match('/FROM\s+`?(\w+)`?/i', $sql, $mm) ? $mm[1] : '?';
        $n[$t] = ($n[$t] ?? 0) + $st->rowCount();
    };
    try {
        // Messagerie (enfants d'abord)
        $del("DELETE FROM message_notifications WHERE message_id IN (SELECT id FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE subject LIKE ?))", [$like]);
        $del("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE subject LIKE ?)", [$like]);
        $del("DELETE FROM conversation_participants WHERE conversation_id IN (SELECT id FROM conversations WHERE subject LIKE ?)", [$like]);
        $del("DELETE FROM conversations WHERE subject LIKE ?", [$like]);
        // Bulletins
        $del("DELETE FROM bulletin_matieres WHERE bulletin_id IN (SELECT id FROM bulletins WHERE appreciation_generale LIKE ?)", [$like]);
        $del("DELETE FROM bulletin_appreciations WHERE bulletin_id IN (SELECT id FROM bulletins WHERE appreciation_generale LIKE ?)", [$like]);
        $del("DELETE FROM bulletins WHERE appreciation_generale LIKE ?", [$like]);
        // Appels
        $del("DELETE FROM appel_eleves WHERE appel_id IN (SELECT id FROM appels WHERE commentaire LIKE ?)", [$like]);
        $del("DELETE FROM appels WHERE commentaire LIKE ?", [$like]);
        // Retenues
        $del("DELETE FROM retenue_eleves WHERE retenue_id IN (SELECT id FROM retenues WHERE commentaire LIKE ?)", [$like]);
        $del("DELETE FROM retenues WHERE commentaire LIKE ?", [$like]);
        // Devoirs
        $del("DELETE FROM devoirs_rendus WHERE devoir_id IN (SELECT id FROM devoirs WHERE description LIKE ?)", [$like]);
        $del("DELETE FROM devoirs_statuts_eleve WHERE devoir_id IN (SELECT id FROM devoirs WHERE description LIKE ?)", [$like]);
        $del("DELETE FROM devoirs WHERE description LIKE ?", [$like]);
        // Compétences
        $del("DELETE FROM competence_evaluations WHERE commentaire LIKE ?", [$like]);
        $del("DELETE FROM competences WHERE description LIKE ?", [$like]);
        // Simples (champ texte marqué)
        $del("DELETE FROM notes WHERE commentaire LIKE ?", [$like]);
        $del("DELETE FROM absences WHERE commentaire LIKE ?", [$like]);
        $del("DELETE FROM retards WHERE commentaire LIKE ?", [$like]);
        $del("DELETE FROM sanctions WHERE commentaire LIKE ?", [$like]);
        $del("DELETE FROM incidents WHERE description LIKE ?", [$like]);
        $del("DELETE FROM evenements WHERE description LIKE ?", [$like]);
        $del("DELETE FROM passages_infirmerie WHERE observations LIKE ?", [$like]);
        $del("DELETE FROM suivi_eleves WHERE JSON_EXTRACT(notes_json,'$.demo') = true");
    } catch (\Throwable $e) {
        echo "  ⚠ clean: " . $e->getMessage() . "\n";
    }
    return array_filter($n);
}

$cmd = $argv[1] ?? 'seed';
if ($cmd === 'clean') {
    $n = cleanAll($pdo, $SENT, $ETAB);
    echo "Données de démo supprimées :\n";
    foreach ($n as $t => $c) printf("  - %-26s %d\n", $t, $c);
    echo "Terminé.\n";
    exit(0);
}

// Idempotence : on nettoie avant de (re)générer.
cleanAll($pdo, $SENT, $ETAB);

$count = [];
$bump  = function (string $k, int $by = 1) use (&$count) { $count[$k] = ($count[$k] ?? 0) + $by; };

/* ───────────────────────── 1. NOTES ───────────────────────── */
$typesEval = ['Contrôle', 'Devoir surveillé', 'Interrogation', 'Devoir maison', 'Oral', 'TP'];
$apprMat   = ['Excellent travail', 'Bon trimestre', 'Ensemble correct', 'Des efforts à poursuivre', 'Trop d\'irrégularité', 'Doit travailler davantage', 'Très sérieux', 'En progrès'];
$moy = []; // $moy[eleve][matiere][trim] = [notes...]
foreach ($eleves as $e) {
    foreach ($matieres as $m) {
        $prof = $matiereProf[$m['id']];
        foreach ($periodes as $per) {
            $nb = mt_rand(2, 3);
            for ($k = 0; $k < $nb; $k++) {
                $base = $niveau[$e['id']] + (mt_rand(-30, 30) / 10);
                $note = max(2, min(20, round($base, 2)));
                $coef = pick([1, 1, 1, 2, 2, 0.5]);
                ins($pdo, 'notes', [
                    'etablissement_id' => $ETAB,
                    'id_eleve'   => $e['id'],
                    'id_matiere' => $m['id'],
                    'id_professeur' => $prof['id'],
                    'note'       => $note,
                    'note_sur'   => 20,
                    'coefficient' => $coef,
                    'type_evaluation' => pick($typesEval),
                    'date_note'  => randDateBetween($per['date_debut'], min($per['date_fin'], date('Y-m-d'))),
                    'commentaire' => pick(['Bon devoir', 'Peut mieux faire', 'Des lacunes', 'Très bien', 'À revoir', '']) . " {$SENT}",
                    'trimestre'  => (int) $per['numero'],
                ]);
                $moy[$e['id']][$m['id']][$per['numero']][] = $note;
                $bump('notes');
            }
        }
    }
}

/* ───────────────────────── 2. ABSENCES ───────────────────────── */
$motifs = ['maladie', 'rdv_medical', 'familial', 'transport', 'autre', ''];
foreach ($eleves as $e) {
    if (!chance(70)) continue;
    $nb = mt_rand(1, 4);
    for ($k = 0; $k < $nb; $k++) {
        $d = randDateBetween('2025-09-15', date('Y-m-d'));
        $type = pick(['cours', 'cours', 'demi-journee', 'journee']);
        $h = mt_rand(8, 15);
        $deb = "{$d} " . sprintf('%02d:00:00', $h);
        $fin = $type === 'journee' ? "{$d} 17:00:00" : ($type === 'demi-journee' ? "{$d} 12:00:00" : "{$d} " . sprintf('%02d:00:00', $h + 1));
        $just = chance(55);
        ins($pdo, 'absences', [
            'etablissement_id' => $ETAB,
            'id_eleve'   => $e['id'],
            'date_debut' => $deb,
            'date_fin'   => $fin,
            'type_absence' => $type,
            'motif'      => pick($motifs),
            'justifie'   => $just ? 1 : 0,
            'commentaire' => ($just ? 'Justificatif fourni' : 'En attente de justificatif') . " {$SENT}",
            'signale_par' => pick($staffNames),
            'statut'     => $just ? 'justifiee' : pick(['non_justifiee', 'signalee']),
            'type'       => $type,
        ]);
        $bump('absences');
    }
}

/* ───────────────────────── 3. RETARDS ───────────────────────── */
foreach ($eleves as $e) {
    if (!chance(55)) continue;
    $nb = mt_rand(1, 3);
    for ($k = 0; $k < $nb; $k++) {
        $d = randDateBetween('2025-09-15', date('Y-m-d'));
        $just = chance(40);
        ins($pdo, 'retards', [
            'etablissement_id' => $ETAB,
            'id_eleve'   => $e['id'],
            'date_retard' => "{$d} " . sprintf('%02d:%02d:00', mt_rand(8, 10), pick([0, 5, 10, 15, 20])),
            'duree_minutes' => pick([5, 10, 10, 15, 20, 30]),
            'motif'      => pick(['Réveil tardif', 'Transport', 'Rendez-vous médical', 'Embouteillages', 'Non précisé']),
            'justifie'   => $just ? 1 : 0,
            'commentaire' => ($just ? 'Retard justifié' : 'Retard non justifié') . " {$SENT}",
            'signale_par' => pick($staffNames),
        ]);
        $bump('retards');
    }
}

/* ───────────────────── 4. INCIDENTS + SANCTIONS (dont exclusions) ───────────────────── */
$typesIncident = ['indiscipline', 'insolence', 'bagarre', 'materiel_degrade', 'tricherie', 'absenteisme'];
$gravites = ['mineur', 'moyen', 'grave', 'tres_grave'];
$incidentsByEleve = [];
foreach ($eleves as $e) {
    if (!chance(35)) continue;
    $g = pick($gravites);
    $id = ins($pdo, 'incidents', [
        'etablissement_id' => $ETAB,
        'eleve_id'   => $e['id'],
        'date_incident' => randDateBetween('2025-09-20', date('Y-m-d')) . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 16), pick([0, 15, 30, 45])),
        'lieu'       => pick(['Salle de classe', 'Cour de récréation', 'Couloir', 'Cantine', 'Gymnase']),
        'type_incident' => pick($typesIncident),
        'gravite'    => $g,
        'description' => pick(['Comportement perturbateur en cours.', 'Manque de respect envers un adulte.', 'Altercation avec un camarade.', 'Dégradation de matériel.', 'Tentative de tricherie lors d\'une évaluation.']) . " {$SENT}",
        'temoins'    => pick(['Plusieurs élèves', 'Un enseignant', '']),
        'signale_par_id' => $cpe['id'] ?? ($profsAll[0]['id']),
        'signale_par_type' => 'vie_scolaire',
        'classe_id'  => $classeIdByStr[$e['classe']] ?? null,
        'statut'     => pick(['signale', 'en_traitement', 'traite']),
    ]);
    $incidentsByEleve[$e['id']] = $id;
    $bump('incidents');
}

// Plan de sanctions : couverture GARANTIE de chaque type, exclusions bien représentées
// (3 exclusions temporaires + 2 exclusions de cours + 2 retenues + avertissements/blâmes/TIG).
$sanctionPlan = [
    'exclusion_temporaire', 'exclusion_cours', 'retenue', 'avertissement', 'blame',
    'exclusion_temporaire', 'exclusion_cours', 'travail_supplementaire', 'retenue',
    'exclusion_temporaire', 'avertissement', 'blame',
];
$sanctionRetenue = []; // sanction_id => eleve_id pour retenues
foreach ($sanctionPlan as $idx => $type) {
    $e = $eleves[($idx * 5 + 2) % count($eleves)]; // répartition déterministe sur des élèves variés
    $d = randDateBetween('2025-09-25', date('Y-m-d'));
    $row = [
        'etablissement_id' => $ETAB,
        'incident_id' => $incidentsByEleve[$e['id']] ?? null,
        'eleve_id'   => $e['id'],
        'type_sanction' => $type,
        'motif'      => pick(['Comportement inadapté répété', 'Manquement au règlement intérieur', 'Insolence envers un professeur', 'Bagarre dans la cour', 'Tricherie']) . " {$SENT}",
        'date_sanction' => $d,
        'date_debut' => null,
        'date_fin'   => null,
        'duree'      => null,
        'lieu_retenue' => null,
        'convocation_parent' => chance(50) ? 1 : 0,
        'parent_notifie' => 1,
        'decide_par_id' => $decideur['id'] ?? 0,
        'decide_par_type' => isset($cpe['id']) && ($decideur['id'] ?? null) === $cpe['id'] ? 'vie_scolaire' : 'administrateur',
        'commentaire' => "Sanction prononcée par la vie scolaire. {$SENT}",
        'statut'     => pick(['prononcee', 'executee']),
    ];
    if (in_array($type, ['exclusion_temporaire', 'exclusion_cours'], true)) {
        $jours = $type === 'exclusion_temporaire' ? mt_rand(1, 3) : 1;
        $row['date_debut'] = "{$d} 08:00:00";
        $row['date_fin']   = date('Y-m-d', strtotime("{$d} +{$jours} day")) . ' 17:00:00';
        $row['duree']      = $type === 'exclusion_temporaire' ? "{$jours} jour(s)" : '1 cours';
    } elseif ($type === 'retenue') {
        $row['duree'] = '1 heure';
        $row['lieu_retenue'] = 'Salle d\'étude';
    }
    $sid = ins($pdo, 'sanctions', $row);
    if ($type === 'retenue') $sanctionRetenue[$sid] = $e['id'];
    $bump('sanctions');
}

/* ───────────────────────── 5. RETENUES (sessions) ───────────────────────── */
$retSessions = [];
for ($k = 0; $k < 3; $k++) {
    $d = randDateBetween(date('Y-m-d'), date('Y-m-d', strtotime('+3 weeks')));
    $rid = ins($pdo, 'retenues', [
        'etablissement_id' => $ETAB,
        'date_retenue' => $d,
        'heure_debut' => '13:00:00',
        'heure_fin'  => '14:00:00',
        'lieu'       => pick(['Salle d\'étude', 'Salle 12', 'Permanence']),
        'surveillant_id' => $cpe['id'] ?? null,
        'surveillant_type' => 'vie_scolaire',
        'capacite_max' => 30,
        'commentaire' => "Retenue hebdomadaire. {$SENT}",
        'statut'     => pick(['planifiee', 'terminee']),
    ]);
    $retSessions[] = $rid;
    $bump('retenues');
}
// Rattacher des élèves aux retenues (dont ceux sanctionnés en retenue).
foreach ($sanctionRetenue as $sid => $eid) {
    ins($pdo, 'retenue_eleves', ['retenue_id' => pick($retSessions), 'sanction_id' => $sid, 'eleve_id' => $eid, 'present' => chance(80) ? 1 : 0, 'commentaire' => $SENT]);
    $bump('retenue_eleves');
}
foreach ($eleves as $e) {
    if (chance(15)) {
        ins($pdo, 'retenue_eleves', ['retenue_id' => pick($retSessions), 'sanction_id' => null, 'eleve_id' => $e['id'], 'present' => 1, 'commentaire' => $SENT]);
        $bump('retenue_eleves');
    }
}

/* ───────────────────────── 6. MESSAGERIE ───────────────────────── */
$mkConv = function (string $subject, string $type, array $participants, array $msgs, bool $allowReplies = true) use ($pdo, $SENT, &$bump): void {
    $cid = ins($pdo, 'conversations', [
        'etablissement_id' => 1, 'subject' => $subject . " {$SENT}", 'type' => $type, 'allow_replies' => $allowReplies ? 1 : 0,
    ]);
    $bump('conversations');
    foreach ($participants as $p) {
        ins($pdo, 'conversation_participants', [
            'conversation_id' => $cid, 'user_id' => $p[0], 'user_type' => $p[1],
            'is_admin' => !empty($p[2]) ? 1 : 0,
        ]);
        $bump('conversation_participants');
    }
    $lastId = null; $lastSender = null; $lastWhen = null;
    foreach ($msgs as $mi => $m) {
        $when = date('Y-m-d H:i:s', strtotime('-' . (count($msgs) - $mi) . ' day') + mt_rand(0, 36000));
        $lastId = ins($pdo, 'messages', [
            'conversation_id' => $cid, 'sender_id' => $m[0], 'sender_type' => $m[1], 'body' => $m[2],
            'status' => $m[3] ?? 'normal', 'created_at' => $when, 'updated_at' => $when,
        ]);
        $lastSender = [$m[0], $m[1]]; $lastWhen = $when;
        $bump('messages');
    }
    $pdo->prepare("UPDATE conversations SET last_message_id=?, updated_at=? WHERE id=?")->execute([$lastId, $lastWhen, $cid]);
    // Notifications "non lu" pour les destinataires (≠ dernier expéditeur).
    foreach ($participants as $p) {
        if ($lastSender && $p[0] == $lastSender[0] && $p[1] === $lastSender[1]) continue;
        ins($pdo, 'message_notifications', [
            'user_id' => $p[0], 'user_type' => $p[1], 'message_id' => $lastId,
            'notification_type' => $type === 'annonce' || $type === 'information' ? 'broadcast' : 'unread', 'is_read' => 0,
        ]);
        $bump('message_notifications');
        $pdo->prepare("UPDATE conversation_participants SET unread_count=unread_count+1 WHERE conversation_id=? AND user_id=? AND user_type=?")->execute([$cid, $p[0], $p[1]]);
    }
};

$profM = $profsAll[0]; $profF = $profsAll[1] ?? $profsAll[0];
$el0 = $eleves[3]; $par0 = $parents[0] ?? null;
// 6.1 Prof ↔ parent (individuelle)
if ($par0) $mkConv(
    "Point sur le travail de {$el0['prenom']}", 'individuelle',
    [[$profM['id'], 'professeur'], [$par0['id'], 'parent']],
    [
        [$profM['id'], 'professeur', "Bonjour, je souhaitais faire un point : le travail est en progrès mais l'attention en classe reste fragile.", 'normal'],
        [$par0['id'], 'parent', "Bonjour, merci pour ce retour. Nous en discuterons à la maison ce week-end.", 'normal'],
        [$profM['id'], 'professeur', "Parfait, n'hésitez pas si vous avez des questions.", 'normal'],
    ]
);
// 6.2 Annonce de classe (prof → élèves)
$classeCible = $classeStrings[0] ?? '6emeA';
$elClasse = array_values(array_filter($eleves, fn($e) => $e['classe'] === $classeCible));
$parts = [[$profF['id'], 'professeur', true]];
foreach ($elClasse as $e) $parts[] = [$e['id'], 'eleve'];
$mkConv(
    "Sortie pédagogique au musée — classe {$classeCible}", 'annonce', $parts,
    [[$profF['id'], 'professeur', "Bonjour à tous, la sortie au musée est confirmée pour vendredi prochain. Pensez à rapporter l'autorisation signée.", 'annonce']],
    false
);
// 6.3 CPE ↔ parent (absence)
if ($cpe && isset($parents[1])) $mkConv(
    "Signalement d'absence", 'individuelle',
    [[$cpe['id'], 'vie_scolaire'], [$parents[1]['id'], 'parent']],
    [
        [$cpe['id'], 'vie_scolaire', "Bonjour, votre enfant a été absent ce matin sans justificatif. Merci de régulariser sous 48h.", 'important'],
        [$parents[1]['id'], 'parent', "Bonjour, il s'agissait d'un rendez-vous médical, je vous transmets le justificatif aujourd'hui.", 'normal'],
    ]
);
// 6.4 Équipe pédagogique (groupe de profs)
$profParts = [];
foreach (array_slice($profsAll, 0, 5) as $p) $profParts[] = [$p['id'], 'professeur', $p === $profsAll[0]];
$mkConv(
    "Préparation du conseil de classe", 'groupe', $profParts,
    [
        [$profsAll[0]['id'], 'professeur', "Bonjour à tous, merci de saisir vos appréciations avant vendredi.", 'normal'],
        [$profsAll[1]['id'], 'professeur', "C'est noté, je termine les miennes ce soir.", 'normal'],
        [$profsAll[2]['id'], 'professeur', "Idem, quelques cas à discuter en séance.", 'normal'],
    ]
);
// 6.5 Annonce administration (information large)
if ($admins) {
    $adm = $admins[0];
    $parts = [[$adm['id'], 'administrateur', true]];
    foreach (array_slice($profsAll, 0, 6) as $p) $parts[] = [$p['id'], 'professeur'];
    foreach (array_slice($vs, 0, 3) as $p) $parts[] = [$p['id'], 'vie_scolaire'];
    $mkConv(
        "Réunion de rentrée — informations importantes", 'information', $parts,
        [[$adm['id'], 'administrateur', "Bonjour, la réunion plénière aura lieu lundi 9h en salle polyvalente. Ordre du jour en pièce jointe.", 'important']],
        false
    );
}
// 6.6 Élève → prof (question)
$mkConv(
    "Question sur le devoir maison", 'individuelle',
    [[$eleves[0]['id'], 'eleve'], [$profM['id'], 'professeur']],
    [
        [$eleves[0]['id'], 'eleve', "Bonjour, je n'ai pas compris la question 3 de l'exercice, pouvez-vous m'aider ?", 'normal'],
        [$profM['id'], 'professeur', "Bonjour, regarde l'exemple vu en cours, la méthode est la même. On en reparle demain.", 'normal'],
    ]
);

/* ───────────────────────── 7. DEVOIRS (cahier de textes) ───────────────────────── */
$devoirTitres = [
    ['Exercices p.42', 'Faire les exercices 1 à 5 page 42.'],
    ['Lecture chapitre 3', 'Lire le chapitre 3 et préparer un résumé.'],
    ['Rédaction', 'Rédiger un texte de 20 lignes sur le thème vu en classe.'],
    ['Réviser le contrôle', 'Réviser l\'ensemble du chapitre pour l\'évaluation.'],
    ['Carte mentale', 'Réaliser une carte mentale du cours.'],
    ['Exposé', 'Préparer un exposé de 5 minutes.'],
];
foreach ($classeStrings as $cs) {
    foreach (array_slice($matieres, 0, 5) as $m) {
        if (!chance(60)) continue;
        $prof = $matiereProf[$m['id']];
        [$titre, $desc] = pick($devoirTitres);
        $dAjout = randDateBetween('2026-05-15', date('Y-m-d'));
        $dRendu = date('Y-m-d', strtotime($dAjout . ' +' . mt_rand(2, 10) . ' day'));
        $did = ins($pdo, 'devoirs', [
            'etablissement_id' => $ETAB,
            'titre' => $titre,
            'description' => $desc . " {$SENT}",
            'classe' => $cs,
            'nom_matiere' => $m['nom'],
            'nom_professeur' => "{$prof['prenom']} {$prof['nom']}",
            'date_ajout' => $dAjout,
            'date_rendu' => $dRendu,
        ]);
        $bump('devoirs');
        // Statuts "fait" + quelques rendus en ligne pour les élèves de la classe.
        $elClasse = array_values(array_filter($eleves, fn($e) => $e['classe'] === $cs));
        foreach ($elClasse as $e) {
            ins($pdo, 'devoirs_statuts_eleve', ['eleve_id' => $e['id'], 'devoir_id' => $did, 'fait' => chance(70) ? 1 : 0, 'date_marque' => date('Y-m-d H:i:s')]);
            $bump('devoirs_statuts_eleve');
            if (chance(45)) {
                $late = chance(20);
                $corrige = chance(50);
                ins($pdo, 'devoirs_rendus', [
                    'devoir_id' => $did, 'eleve_id' => $e['id'],
                    'contenu' => "Travail rendu par {$e['prenom']}. {$SENT}",
                    'date_rendu' => $dRendu . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 22), mt_rand(0, 59)),
                    'en_retard' => $late ? 1 : 0,
                    'note' => $corrige ? round($niveau[$e['id']] + mt_rand(-20, 20) / 10, 2) : null,
                    'note_sur' => 20,
                    'commentaire_prof' => $corrige ? pick($apprMat) : null,
                    'statut' => $corrige ? 'corrige' : 'rendu',
                ]);
                $bump('devoirs_rendus');
            }
        }
    }
}

/* ───────────────────────── 8. AGENDA / ÉVÉNEMENTS ───────────────────────── */
$events = [
    ['Conseil de classe T3', 'conseil', 'professeurs', '+5 day', '17:00', '19:00', 'Salle des professeurs'],
    ['Réunion parents-professeurs', 'reunion', 'parents', '+8 day', '17:30', '20:30', 'Préau'],
    ['Brevet blanc', 'examen', 'classes_specifiques', '+12 day', '08:00', '12:00', 'Gymnase'],
    ['Sortie pédagogique au musée', 'sortie', 'classes_specifiques', '+3 day', '09:00', '16:00', 'Musée des Beaux-Arts'],
    ['Vacances d\'été', 'autre', 'public', '+20 day', '00:00', '23:59', ''],
    ['Photo de classe', 'autre', 'eleves', '+6 day', '10:00', '12:00', 'Cour'],
    ['Réunion d\'équipe', 'reunion', 'personnel', '+2 day', '12:30', '13:30', 'Salle B12'],
    ['Portes ouvertes', 'autre', 'public', '+15 day', '09:00', '17:00', 'Établissement'],
];
foreach ($events as $ev) {
    [$titre, $type, $vis, $rel, $hd, $hf, $lieu] = $ev;
    $day = date('Y-m-d', strtotime($rel));
    ins($pdo, 'evenements', [
        'etablissement_id' => $ETAB,
        'titre' => $titre,
        'description' => "{$titre} — organisé par l'établissement. {$SENT}",
        'date_debut' => "{$day} {$hd}:00",
        'date_fin'   => "{$day} {$hf}:00",
        'type_evenement' => $type,
        'statut' => 'actif',
        'createur' => $admins[0]['prenom'] . ' ' . $admins[0]['nom'] ?? 'Administration',
        'visibilite' => $vis,
        'personnes_concernees' => '',
        'lieu' => $lieu,
        'classes' => $vis === 'classes_specifiques' ? implode(',', array_slice($classeStrings, 0, 3)) : null,
    ]);
    $bump('evenements');
}

/* ───────────────────────── 9. INFIRMERIE ───────────────────────── */
foreach ($eleves as $e) {
    if (!chance(30)) continue;
    $d = randDateBetween('2025-10-01', date('Y-m-d'));
    ins($pdo, 'passages_infirmerie', [
        'etablissement_id' => $ETAB,
        'eleve_id' => $e['id'],
        'date_passage' => "{$d} " . sprintf('%02d:%02d:00', mt_rand(8, 16), pick([0, 15, 30, 45])),
        'motif' => pick(['Maux de tête', 'Maux de ventre', 'Petite blessure', 'Malaise', 'Fièvre', 'Chute en EPS']),
        'symptomes' => pick(['Fatigue', 'Douleur localisée', 'Nausées', '']),
        'soins_prodigues' => pick(['Repos', 'Pansement', 'Prise de température', 'Mise au calme']),
        'medicaments_donnes' => chance(30) ? 'Paracétamol (avec accord parental)' : null,
        'orientation' => pick(['retour_classe', 'retour_classe', 'repos', 'domicile']),
        'parent_prevenu' => chance(40) ? 1 : 0,
        'heure_sortie' => sprintf('%02d:%02d:00', mt_rand(9, 17), pick([0, 15, 30])),
        'infirmier_id' => $infirmier['id'] ?? null,
        'observations' => "Passage à l'infirmerie. {$SENT}",
    ]);
    $bump('passages_infirmerie');
}

/* ───────────────────────── 10. SUIVI ÉLÈVE (décrochage) ───────────────────────── */
foreach ($eleves as $e) {
    if ($niveau[$e['id']] < 10 && chance(80)) {
        $risque = round((12 - $niveau[$e['id']]) / 12, 2);
        ins($pdo, 'suivi_eleves', [
            'eleve_id' => $e['id'],
            'risque_decrochage' => max(0, min(1, $risque)),
            'derniere_analyse' => date('Y-m-d'),
            'notes_json' => json_encode(['demo' => true, 'facteurs' => ['absences', 'baisse_resultats'], 'commentaire' => 'Suivi renforcé recommandé.'], JSON_UNESCAPED_UNICODE),
            'etablissement_id' => $ETAB,
        ]);
        $bump('suivi_eleves');
    }
}

/* ───────────────────────── 11. COMPÉTENCES (socle) ───────────────────────── */
$socle = [
    ['SOCLE_D1', 'Les langages pour penser et communiquer', 'Comprendre, s\'exprimer en français'],
    ['SOCLE_D2', 'Les méthodes et outils pour apprendre', 'Organisation du travail personnel'],
    ['SOCLE_D3', 'La formation de la personne et du citoyen', 'Respect des règles et d\'autrui'],
    ['SOCLE_D4', 'Les systèmes naturels et techniques', 'Démarche scientifique'],
    ['SOCLE_D5', 'Les représentations du monde', 'Culture, espace et temps'],
];
$compIds = [];
foreach ($socle as $i => $c) {
    $compIds[] = ins($pdo, 'competences', [
        'etablissement_id' => $ETAB, 'code' => $c[0], 'nom' => $c[1],
        'description' => $c[2] . " {$SENT}", 'domaine' => 'Socle commun', 'niveau' => 1, 'ordre' => $i + 1, 'actif' => 1,
    ]);
    $bump('competences');
}
$niveaux = ['non_acquis', 'en_cours', 'acquis', 'depasse'];
foreach ($eleves as $e) {
    if (!chance(70)) continue;
    foreach ($compIds as $ci) {
        if (!chance(60)) continue;
        $m = pick($matieres); $prof = $matiereProf[$m['id']];
        ins($pdo, 'competence_evaluations', [
            'etablissement_id' => $ETAB, 'eleve_id' => $e['id'], 'competence_id' => $ci,
            'professeur_id' => $prof['id'], 'matiere_id' => $m['id'],
            'niveau_acquis' => pick($niveaux),
            'commentaire' => "Évaluation par compétence. {$SENT}",
            'date_evaluation' => randDateBetween('2025-10-01', date('Y-m-d')),
            'periode_id' => pick($periodes)['id'],
        ]);
        $bump('competence_evaluations');
    }
}

/* ───────────────────────── 12. BULLETINS (trimestre 1) ───────────────────────── */
$perT1 = $periodes[0];
// Moyenne élève/matière T1
$moyEM = []; // [eleve][matiere] = moyenne
foreach ($eleves as $e) {
    foreach ($matieres as $m) {
        $ns = $moy[$e['id']][$m['id']][1] ?? [];
        if ($ns) $moyEM[$e['id']][$m['id']] = round(array_sum($ns) / count($ns), 2);
    }
}
// Stats classe (par classe_id mappé, par matière)
$byClasse = [];
foreach ($eleves as $e) $byClasse[$classeIdByStr[$e['classe']]][] = $e['id'];
foreach ($eleves as $e) {
    $cid = $classeIdByStr[$e['classe']];
    $mg = []; foreach ($matieres as $m) if (isset($moyEM[$e['id']][$m['id']])) $mg[] = $moyEM[$e['id']][$m['id']];
    if (!$mg) continue;
    $moyenneGen = round(array_sum($mg) / count($mg), 2);
    // absences/retards T1
    $nbAbs = (int) $pdo->query("SELECT COUNT(*) FROM absences WHERE id_eleve={$e['id']} AND commentaire LIKE " . $pdo->quote('%' . $SENT . '%'))->fetchColumn();
    $nbRet = (int) $pdo->query("SELECT COUNT(*) FROM retards WHERE id_eleve={$e['id']} AND commentaire LIKE " . $pdo->quote('%' . $SENT . '%'))->fetchColumn();
    $avis = $moyenneGen >= 16 ? 'felicitations' : ($moyenneGen >= 14 ? 'compliments' : ($moyenneGen >= 12 ? 'encouragements' : ($moyenneGen < 9 ? 'avertissement_travail' : 'aucun')));
    $bid = ins($pdo, 'bulletins', [
        'etablissement_id' => $ETAB, 'eleve_id' => $e['id'], 'classe_id' => $cid, 'periode_id' => $perT1['id'],
        'annee_scolaire' => '2025-2026', 'moyenne_generale' => $moyenneGen, 'rang' => null,
        'appreciation_generale' => ($moyenneGen >= 14 ? 'Très bon trimestre, continuez ainsi.' : ($moyenneGen >= 11 ? 'Trimestre satisfaisant, des efforts à confirmer.' : 'Trimestre difficile, un sursaut est attendu.')) . " {$SENT}",
        'appreciation_vie_scolaire' => $nbAbs > 2 ? 'Absences à régulariser.' : 'Comportement correct.',
        'avis_conseil' => $avis,
        'nb_absences' => $nbAbs, 'nb_retards' => $nbRet,
        'statut' => 'publie',
    ]);
    $bump('bulletins');
    foreach ($matieres as $m) {
        if (!isset($moyEM[$e['id']][$m['id']])) continue;
        $cls = array_filter($byClasse[$cid], fn($id2) => isset($moyEM[$id2][$m['id']]));
        $vals = array_map(fn($id2) => $moyEM[$id2][$m['id']], $cls);
        $prof = $matiereProf[$m['id']];
        ins($pdo, 'bulletin_matieres', [
            'bulletin_id' => $bid, 'matiere_id' => $m['id'], 'professeur_id' => $prof['id'],
            'moyenne_eleve' => $moyEM[$e['id']][$m['id']],
            'moyenne_classe' => $vals ? round(array_sum($vals) / count($vals), 2) : null,
            'moyenne_min' => $vals ? min($vals) : null,
            'moyenne_max' => $vals ? max($vals) : null,
            'appreciation' => pick($apprMat),
            'coefficient' => (float) $m['coefficient'] ?: 1,
        ]);
        $bump('bulletin_matieres');
    }
}
// Rang par classe
foreach ($byClasse as $cid => $ids) {
    $rows = $pdo->query("SELECT id,moyenne_generale FROM bulletins WHERE classe_id={$cid} AND periode_id={$perT1['id']} AND appreciation_generale LIKE " . $pdo->quote('%' . $SENT . '%') . " ORDER BY moyenne_generale DESC")->fetchAll(PDO::FETCH_ASSOC);
    $r = 1; foreach ($rows as $row) { $pdo->prepare("UPDATE bulletins SET rang=? WHERE id=?")->execute([$r++, $row['id']]); }
}

/* ───────────────────────── 13. APPELS (faire l'appel) ───────────────────────── */
foreach ($classeStrings as $cs) {
    $cid = $classeIdByStr[$cs];
    $elClasse = array_values(array_filter($eleves, fn($e) => $e['classe'] === $cs));
    if (!$elClasse) continue;
    for ($k = 0; $k < 2; $k++) {
        $m = pick($matieres); $prof = $matiereProf[$m['id']];
        $d = randDateBetween('2026-06-01', date('Y-m-d'));
        $hd = pick(['08:00', '09:00', '10:00', '14:00']);
        $aid = ins($pdo, 'appels', [
            'etablissement_id' => $ETAB, 'edt_id' => null, 'classe_id' => $cid,
            'professeur_id' => $prof['id'], 'matiere_id' => $m['id'], 'date_appel' => $d,
            'heure_debut' => "{$hd}:00", 'heure_fin' => sprintf('%02d:00:00', (int) substr($hd, 0, 2) + 1),
            'type_appel' => 'cours', 'statut' => 'valide',
            'commentaire' => "Appel du cours de {$m['nom']}. {$SENT}",
        ]);
        $bump('appels');
        foreach ($elClasse as $e) {
            $st = 'present';
            $r = mt_rand(1, 100);
            if ($r <= 8) $st = 'absent'; elseif ($r <= 15) $st = 'retard'; elseif ($r <= 17) $st = 'exclu'; elseif ($r <= 19) $st = 'dispense';
            ins($pdo, 'appel_eleves', [
                'appel_id' => $aid, 'eleve_id' => $e['id'], 'statut' => $st,
                'heure_arrivee' => $st === 'retard' ? sprintf('%02d:%02d:00', (int) substr($hd, 0, 2), mt_rand(5, 25)) : null,
                'duree_retard' => $st === 'retard' ? mt_rand(5, 25) : null,
                'motif' => in_array($st, ['absent', 'retard', 'exclu'], true) ? pick(['Non justifié', 'Transport', 'Maladie', 'Comportement']) : null,
                'commentaire' => null, 'notifie' => 0,
            ]);
            $bump('appel_eleves');
        }
    }
}

/* ───────────────────────── Restitution ───────────────────────── */
ksort($count);
echo "\n✅ Données de démonstration générées (établissement {$ETAB}) :\n\n";
$total = 0;
foreach ($count as $k => $v) { printf("  %-26s %5d\n", $k, $v); $total += $v; }
printf("\n  %-26s %5d lignes au total.\n", 'TOTAL', $total);
echo "\nSuppression : php scripts/seed_demo_data.php clean\n";
