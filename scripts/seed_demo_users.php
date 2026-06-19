<?php
/**
 * Génère ~50 comptes de DÉMONSTRATION (fictifs) avec des rôles/permissions variés,
 * pour tester concrètement le modèle compte + rôle principal + sous-rôles.
 *
 *   php scripts/seed_demo_users.php          # crée les comptes
 *   php scripts/seed_demo_users.php clean     # supprime tous les comptes de démo
 *
 * Tous les comptes partagent un mot de passe commun (affiché en fin d'exécution) et
 * sont reconnaissables à leur e-mail @demo.fronote.test (utilisé par « clean »).
 * L'identifiant de connexion est au format nom.prenom, généré automatiquement.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Security\RoleCatalog;
use API\Services\RoleManagementService;

\API\Core\EstablishmentContext::set(1);

$pdo     = getPDO();
$userObj = app('user');
$rms     = new RoleManagementService($pdo);

$DEMO_DOMAIN   = 'demo.fronote.test';
$DEMO_PASSWORD = 'DemoFronote2026!';
$actor         = ['type' => 'administrateur', 'id' => 0];
$actorRoles    = ['administrateur'];
$tableMap      = ['eleve' => 'eleves', 'parent' => 'parents', 'professeur' => 'professeurs', 'vie_scolaire' => 'vie_scolaire', 'administrateur' => 'administrateurs'];

$cmd = $argv[1] ?? 'seed';

if ($cmd === 'clean') {
    $n = 0;
    foreach ($tableMap as $type => $tbl) {
        try {
            $ids = $pdo->query("SELECT id FROM `{$tbl}` WHERE mail LIKE '%@{$DEMO_DOMAIN}'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $pdo->prepare("DELETE FROM user_roles WHERE user_type = ? AND user_id = ?")->execute([$type, (int) $id]);
                $n++;
            }
            $pdo->prepare("DELETE FROM `{$tbl}` WHERE mail LIKE ?")->execute(["%@{$DEMO_DOMAIN}"]);
        } catch (\Throwable $e) {}
    }
    echo "Comptes de démonstration supprimés ({$n}).\n";
    exit(0);
}

// ─── Définition des comptes (variété de rôles/permissions) ──────────────────────
$NP = [ // prénoms / noms pour générer en masse
    ['Lucas','Martin'],['Emma','Bernard'],['Hugo','Dubois'],['Léa','Thomas'],['Jules','Robert'],
    ['Chloé','Richard'],['Louis','Petit'],['Manon','Durand'],['Adam','Leroy'],['Jade','Moreau'],
    ['Liam','Simon'],['Zoé','Laurent'],['Noah','Lefebvre'],['Inès','Michel'],['Tom','Garcia'],
    ['Sarah','David'],['Nathan','Bertrand'],['Camille','Roux'],['Enzo','Vincent'],['Lina','Fournier'],
];

$accounts = [];

// 18 élèves (classes variées) — rôle unique (leur type)
$classes = ['6emeA','6emeB','5emeA','4emeB','3emeC','2ndeA','1ereS','TaleES'];
for ($i = 0; $i < 18; $i++) {
    [$p, $n] = $NP[$i % count($NP)];
    $accounts[] = ['eleve', $n, $p . ($i + 1), ['classe' => $classes[$i % count($classes)], 'date_naissance' => '2010-0' . (($i % 9) + 1) . '-15', 'lieu_naissance' => 'Paris', 'adresse' => '1 rue des Écoles'], null, []];
}

// 8 parents — rôle unique
for ($i = 0; $i < 8; $i++) {
    [$p, $n] = $NP[($i + 3) % count($NP)];
    $accounts[] = ['parent', $n, $p . 'P' . ($i + 1), ['metier' => 'Profession ' . ($i + 1), 'adresse' => '2 avenue de la Gare'], null, []];
}

// 12 professeurs — rôles pédagogiques variés
$profRoles = [
    [null, []],
    ['professeur_principal', []],
    ['coordinateur_matiere', []],
    ['professeur_principal', ['responsable_bulletin']],
    ['coordinateur_niveau', []],
    ['responsable_examens', []],
    ['tuteur_pedagogique', []],
    ['documentaliste', []],
    ['responsable_orientation', []],
    ['professeur_principal', ['coordinateur_matiere']],
    ['responsable_conseils', []],
    [null, ['referent_pai']],
];
$matieres = ['Mathématiques','Français','Histoire-Géo','Anglais','SVT','Physique-Chimie','EPS','Espagnol','Arts plastiques','Musique','Technologie','Philosophie'];
for ($i = 0; $i < 12; $i++) {
    [$p, $n] = $NP[($i + 7) % count($NP)];
    [$principal, $subs] = $profRoles[$i];
    $accounts[] = ['professeur', $n, $p . 'Prof', ['matiere' => $matieres[$i], 'adresse' => '3 bd des Lilas'], $principal, $subs];
}

// 6 vie scolaire — rôles vie scolaire / santé-social (PAS d'administration)
$vsDefs = [
    ['cpe', [], ['est_CPE' => 'oui']],
    ['infirmerie', [], ['est_infirmerie' => 'oui']],
    ['aesh', [], []],
    ['surveillant', [], []],
    ['referent_absences', [], []],
    [null, ['referent_discipline'], []],
];
for ($i = 0; $i < 6; $i++) {
    [$p, $n] = $NP[($i + 2) % count($NP)];
    [$principal, $subs, $extra] = $vsDefs[$i];
    $accounts[] = ['vie_scolaire', $n, $p . 'VS', $extra, $principal, $subs];
}

// 6 administrateurs — rôles plateforme/direction/administratif
$admRoles = [
    [null, []],
    ['direction', []],
    ['chef_etablissement', []],
    ['secretariat_direction', []],
    ['responsable_permissions', []],
    ['dpo', ['responsable_securite']],
];
for ($i = 0; $i < 6; $i++) {
    [$p, $n] = $NP[($i + 11) % count($NP)];
    [$principal, $subs] = $admRoles[$i];
    $accounts[] = ['administrateur', $n, $p . 'Adm', ['adresse' => '4 place de la Mairie'], $principal, $subs];
}

// ─── Création ──────────────────────────────────────────────────────────────────
$hash    = password_hash($DEMO_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);
$created = [];
foreach ($accounts as $a) {
    [$type, $nom, $prenom, $extra, $principal, $subs] = $a;
    $mail = strtolower($prenom . '.' . $nom) . '@' . $DEMO_DOMAIN;
    $data = array_merge(['nom' => $nom, 'prenom' => $prenom, 'mail' => $mail], (array) $extra);

    $r = $userObj->createUser($type, $data);
    if (empty($r['success'])) {
        echo "  ÉCHEC {$type} {$prenom} {$nom} : " . ($r['message'] ?? '') . "\n";
        continue;
    }
    $tbl = $tableMap[$type];
    $id  = (int) $pdo->query("SELECT id FROM `{$tbl}` WHERE mail = " . $pdo->quote($mail))->fetchColumn();
    // Mot de passe commun + pas de changement forcé (pour tester immédiatement).
    $pdo->prepare("UPDATE `{$tbl}` SET mot_de_passe = ?, password_changed_at = NOW() WHERE id = ?")->execute([$hash, $id]);

    $assigned = [];
    foreach (array_filter(array_merge([$principal], (array) $subs)) as $rk) {
        try { $rms->assign($actor, $actorRoles, $type, $id, $rk); $assigned[] = $rk; }
        catch (\Throwable $e) { /* incompatible → ignoré */ }
    }
    $created[] = [$r['identifiant'], $type, $prenom . ' ' . $nom, $assigned ? implode(' + ', $assigned) : '(rôle de base)'];
}

// ─── Restitution ────────────────────────────────────────────────────────────────
$lines = [];
$lines[] = count($created) . ' comptes de démonstration créés.';
$lines[] = 'Connexion : IDENTIFIANT ci-dessous + mot de passe commun « ' . $DEMO_PASSWORD .' ».';
$lines[] = '';
$lines[] = sprintf('%-22s %-15s %-24s %s', 'IDENTIFIANT', 'TYPE', 'NOM', 'RÔLES');
foreach ($created as $c) {
    $lines[] = sprintf('%-22s %-15s %-24s %s', $c[0], $c[1], $c[2], $c[3]);
}
$out = implode("\n", $lines) . "\n";
echo "\n" . $out;
@file_put_contents('/opt/pronote2-db/demo-users.txt', $out);
echo "\n(Récapitulatif enregistré dans /opt/pronote2-db/demo-users.txt — « php scripts/seed_demo_users.php clean » pour supprimer.)\n";
