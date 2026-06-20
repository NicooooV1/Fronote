<?php
/**
 * Jeu de démonstration — exhibe les dashboards élève / parent / professeur.
 * Crée 3 comptes (via le service métier, hash bcrypt + identifiant + miroir compte),
 * les relie (parent↔élève), et seede l'activité lue par les widgets : notes, devoirs,
 * évaluations, événements, absences. Idempotent (réexécutable sans doublon).
 *
 * Usage : php scripts/seed_demo.php
 * Non accessible depuis le web (préfixe /scripts bloqué en 404).
 */
require_once __DIR__ . '/../API/core.php';

$pdo = getPDO();
$userObj = app('user');
$PW = 'Demo!2026Fr';
$pwHash = \API\Security\PasswordPolicy::hash($PW);
$etab = \API\Core\EstablishmentContext::id();
$CLASSE = '6A';
$MAT_ID = 2;            // Mathématiques
$MAT_NOM = 'Mathématiques';
$T = 3;                 // trimestre courant (juin)

function out(string $s): void { echo $s . "\n"; }

/** Crée (ou récupère) un utilisateur, fixe un mot de passe connu et lève le changement forcé. */
function ensureUser($userObj, PDO $pdo, string $profil, string $table, array $data, string $pwHash): array {
    $s = $pdo->prepare("SELECT id, identifiant FROM `{$table}` WHERE mail = ? LIMIT 1");
    $s->execute([$data['mail']]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $row['id']; $ident = $row['identifiant'];
    } else {
        $m = method_exists($userObj, 'createUser') ? 'createUser' : 'create';
        $res = $userObj->$m($profil, $data);
        if (empty($res['success'])) { throw new \RuntimeException("{$profil}: " . ($res['message'] ?? 'échec création')); }
        $ident = $res['identifiant'];
        $q = $pdo->prepare("SELECT id FROM `{$table}` WHERE identifiant = ? LIMIT 1");
        $q->execute([$ident]); $id = (int) $q->fetchColumn();
    }
    // Mot de passe connu + lève le changement forcé (password_changed_at).
    $pdo->prepare("UPDATE `{$table}` SET mot_de_passe = ?, password_changed_at = NOW() WHERE id = ?")->execute([$pwHash, $id]);
    // Miroir compte (best-effort) : ne pas forcer le changement.
    try { $pdo->prepare("UPDATE tenant_accounts SET must_change_password = 0 WHERE legacy_type = ? AND legacy_id = ?")->execute([$profil, $id]); } catch (\Throwable $e) {}
    return [$id, $ident];
}

/** Insert tolérant : log l'erreur sans interrompre le seed. */
function tryInsert(PDO $pdo, string $sql, array $args, string $label): void {
    try { $pdo->prepare($sql)->execute($args); }
    catch (\Throwable $e) { out("   ⚠ {$label}: " . $e->getMessage()); }
}

out("== Seed démo (établissement #{$etab}, classe {$CLASSE}) ==");

// 1) Comptes
[$eleveId, $eleveIdent] = ensureUser($userObj, $pdo, 'eleve', 'eleves', [
    'nom' => 'Dupont', 'prenom' => 'Jean', 'mail' => 'jean.dupont@demo.fronote',
    'date_naissance' => '2013-04-12', 'lieu_naissance' => 'Lyon', 'classe' => $CLASSE,
    'adresse' => "12 rue des Écoles, Lyon",
], $pwHash);
[$parentId, $parentIdent] = ensureUser($userObj, $pdo, 'parent', 'parents', [
    'nom' => 'Dupont', 'prenom' => 'Marie', 'mail' => 'marie.dupont@demo.fronote',
    'adresse' => "12 rue des Écoles, Lyon", 'metier' => 'Architecte', 'est_parent_eleve' => 'non',
], $pwHash);
[$profId, $profIdent] = ensureUser($userObj, $pdo, 'professeur', 'professeurs', [
    'nom' => 'Bernard', 'prenom' => 'Pierre', 'mail' => 'pierre.bernard@demo.fronote',
    'adresse' => "5 avenue Jean Jaurès, Lyon", 'matiere' => $MAT_NOM, 'professeur_principal' => 'oui',
], $pwHash);
$profNom = 'Pierre Bernard';
out("Comptes : élève={$eleveIdent} (#{$eleveId}), parent={$parentIdent} (#{$parentId}), prof={$profIdent} (#{$profId})");

// 2) Lien parent ↔ élève
tryInsert($pdo, "INSERT IGNORE INTO parent_eleve (id_parent, id_eleve, lien) VALUES (?, ?, 'parent')", [$parentId, $eleveId], 'parent_eleve');

// 3) Purge des données démo précédentes (idempotence)
foreach ([
    ["DELETE FROM notes WHERE id_eleve = ?", [$eleveId]],
    ["DELETE FROM devoirs WHERE classe = ? AND nom_professeur = ?", [$CLASSE, $profNom]],
    ["DELETE FROM absences WHERE id_eleve = ?", [$eleveId]],
    ["DELETE FROM evenements WHERE createur = ?", ['Démo Fronote']],
    ["DELETE FROM evaluations_en_ligne WHERE professeur_id = ?", [$profId]],
] as [$sql, $args]) { try { $pdo->prepare($sql)->execute($args); } catch (\Throwable $e) {} }

// 4) Notes (visibles élève + parent + prof)
$notes = [
    [15.5, 'Contrôle', '2026-06-05', 'Bon travail, raisonnement clair.'],
    [13.0, 'Devoir maison', '2026-06-10', 'Quelques erreurs de calcul.'],
    [17.0, 'Interrogation', '2026-06-16', 'Excellent !'],
];
foreach ($notes as $n) {
    tryInsert($pdo, "INSERT INTO notes (etablissement_id, id_eleve, id_matiere, id_professeur, note, note_sur, coefficient, type_evaluation, date_note, commentaire, trimestre) VALUES (?,?,?,?,?,20,1,?,?,?,?)",
        [$etab, $eleveId, $MAT_ID, $profId, $n[0], $n[1], $n[2], $n[3], $T], 'note');
}

// 5) Devoirs à faire (classe 6A / prof) — date_rendu future
$devoirs = [
    ['Exercices sur les fractions', 'Exercices 1 à 15 page 42.', '2026-06-23'],
    ['Préparer le contrôle de géométrie', 'Réviser les théorèmes du chapitre 7.', '2026-06-26'],
];
foreach ($devoirs as $d) {
    tryInsert($pdo, "INSERT INTO devoirs (etablissement_id, titre, description, classe, nom_matiere, nom_professeur, date_ajout, date_rendu) VALUES (?,?,?,?,?,?,?,?)",
        [$etab, $d[0], $d[1], $CLASSE, $MAT_NOM, $profNom, '2026-06-18', $d[2]], 'devoir');
}

// 6) Évaluation en ligne à venir (classe 6A)
tryInsert($pdo, "INSERT INTO evaluations_en_ligne (etablissement_id, professeur_id, titre, classe, statut, date_ouverture, date_fermeture, questions_config) VALUES (?,?,?,?,?,?,?,?)",
    [$etab, $profId, 'QCM — Théorème de Pythagore', $CLASSE, 'ouverte', '2026-06-21 08:00:00', '2026-06-28 18:00:00', '[]'], 'evaluation');

// 7) Événements à venir (tous rôles)
$evts = [
    ['Conseil de classe 6A', 'reunion', '2026-06-24 17:00:00', '2026-06-24 18:30:00', 'Salle B12'],
    ['Sortie au musée des sciences', 'sortie', '2026-06-25 09:00:00', '2026-06-25 16:00:00', 'Musée des Confluences'],
    ['Fête de fin d\'année', 'evenement', '2026-07-02 14:00:00', '2026-07-02 18:00:00', 'Cour principale'],
];
foreach ($evts as $e) {
    tryInsert($pdo, "INSERT INTO evenements (etablissement_id, titre, description, date_debut, date_fin, type_evenement, statut, createur, visibilite, classes, lieu) VALUES (?,?,?,?,?,?, 'actif', 'Démo Fronote', 'public', ?, ?)",
        [$etab, $e[0], $e[0], $e[2], $e[3], $e[1], $CLASSE, $e[4]], 'evenement');
}

// 8) Absence du jour (vue prof/vie scolaire/admin)
tryInsert($pdo, "INSERT INTO absences (etablissement_id, id_eleve, date_debut, date_fin, type_absence, motif, justifie, signale_par) VALUES (?,?,?,?,?,?,0,?)",
    [$etab, $eleveId, '2026-06-20 08:00:00', '2026-06-20 12:00:00', 'maladie', 'Rendez-vous médical', $profNom], 'absence');

// Récapitulatif
$cnt = fn(string $sql, array $a) => (function() use ($pdo,$sql,$a){ try { $s=$pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); } catch(\Throwable $e){ return -1; } })();
out("");
out("Données seedées : notes=" . $cnt("SELECT COUNT(*) FROM notes WHERE id_eleve=?", [$eleveId])
    . " · devoirs=" . $cnt("SELECT COUNT(*) FROM devoirs WHERE classe=? AND nom_professeur=?", [$CLASSE, $profNom])
    . " · évaluations=" . $cnt("SELECT COUNT(*) FROM evaluations_en_ligne WHERE professeur_id=?", [$profId])
    . " · événements=" . $cnt("SELECT COUNT(*) FROM evenements WHERE createur=?", ['Démo Fronote'])
    . " · absences=" . $cnt("SELECT COUNT(*) FROM absences WHERE id_eleve=?", [$eleveId]));
out("");
out("=== Identifiants démo (mot de passe : {$PW}) ===");
out("  Élève      : {$eleveIdent}");
out("  Parent     : {$parentIdent}");
out("  Professeur : {$profIdent}");
