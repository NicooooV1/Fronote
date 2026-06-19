<?php
/**
 * Migration des données héritées vers le modèle 3-mondes (refonte, Phase E).
 *
 *   php scripts/tenant_migrate.php          # nécessite --force
 *   php scripts/tenant_migrate.php --force
 *
 * Idempotent (re-jouable) :
 *  - administrateurs/professeurs/vie_scolaire → tenant_accounts (staff) + membership + rôle
 *    (administration / professeur[own_classes+classes] / vie_scolaire[+cpe/+infirmerie]) ;
 *  - eleves   → tenant_accounts (student) + rôle eleve (self) ;
 *  - parents  → tenant_accounts (family)  + rôle parent (children) ;
 *  - parent_eleve → account_relationships (tenant_account, parent_of/…) ;
 *  - technicien_access → platform_accounts (platform_support).
 * Ne supprime ni ne modifie les tables héritées (migration non destructive).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../API/bootstrap.php';

use API\Tenant\TenantAccountService;
use API\Tenant\TenantMembershipService;
use API\Tenant\TenantRoleCatalog;
use API\Tenant\TenantRoleSync;
use API\Platform\PlatformAccountService;
use API\Platform\PlatformRoleSync;

if (!in_array('--force', $argv, true)) {
    fwrite(STDERR, "Migration de données. Relancez avec --force pour exécuter.\n");
    exit(2);
}

$pdo = getPDO();
(new TenantRoleSync($pdo))->sync();
(new PlatformRoleSync($pdo))->sync();
$accSvc = new TenantAccountService($pdo);
$mbrSvc = new TenantMembershipService($pdo);

$roleId = [];
foreach ($pdo->query("SELECT id, role_key FROM tenant_roles") as $r) { $roleId[$r['role_key']] = (int) $r['id']; }

function ensureAccount(TenantAccountService $svc, string $legacyType, array $row, string $accountType): int
{
    $existing = $svc->findByLegacy($legacyType, (int) $row['id']);
    if ($existing) { return (int) $existing['id']; }
    return $svc->createAccount([
        'account_type' => $accountType,
        'username'     => $row['identifiant'] ?? ($legacyType . $row['id']),
        'email'        => $row['mail'] ?? null,
        'password_hash'=> $row['mot_de_passe'] ?? null,
        'first_name'   => $row['prenom'] ?? '',
        'last_name'    => $row['nom'] ?? '',
        'status'       => (isset($row['actif']) && (int) $row['actif'] === 0) ? 'inactive' : 'active',
        'must_change_password' => 0,
        'legacy_type'  => $legacyType,
        'legacy_id'    => (int) $row['id'],
    ]);
}

function ensureRole(PDO $pdo, array $roleId, int $membershipId, string $roleKey): int
{
    if (!isset($roleId[$roleKey])) { return 0; }
    $sel = $pdo->prepare("SELECT id FROM tenant_membership_roles WHERE membership_id = ? AND tenant_role_id = ? LIMIT 1");
    $sel->execute([$membershipId, $roleId[$roleKey]]);
    $id = $sel->fetchColumn();
    if ($id !== false) { return (int) $id; }
    $pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, ?, 1)")
        ->execute([$membershipId, $roleId[$roleKey], TenantRoleCatalog::defaultScope($roleKey)]);
    return (int) $pdo->lastInsertId();
}

function addScope(PDO $pdo, int $mrId, string $type, int $sid): void
{
    $sel = $pdo->prepare("SELECT 1 FROM tenant_membership_role_scope_values WHERE membership_role_id=? AND scope_type=? AND scope_id=? LIMIT 1");
    $sel->execute([$mrId, $type, $sid]);
    if (!$sel->fetchColumn()) {
        $pdo->prepare("INSERT INTO tenant_membership_role_scope_values (membership_role_id, scope_type, scope_id) VALUES (?, ?, ?)")
            ->execute([$mrId, $type, $sid]);
    }
}

$stats = ['staff' => 0, 'student' => 0, 'family' => 0, 'relations' => 0, 'support' => 0];

// — Personnel —
$plan = [
    ['administrateurs', 'administrateur', 'administration'],
    ['professeurs',     'professeur',     'professeur'],
    ['vie_scolaire',    'vie_scolaire',   'vie_scolaire'],
];
foreach ($plan as [$table, $legacyType, $roleKey]) {
    try { $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { continue; }
    foreach ($rows as $row) {
        $etab = (int) ($row['etablissement_id'] ?? 1) ?: 1;
        $accId = ensureAccount($accSvc, $legacyType, $row, 'staff');
        $mId = $mbrSvc->ensure($etab, $accId);
        $mrId = ensureRole($pdo, $roleId, $mId, $roleKey);
        if ($legacyType === 'professeur') {
            // périmètre own_classes : classes du prof (professeur_classes → classes.id)
            try {
                $cs = $pdo->prepare("SELECT c.id FROM professeur_classes pc JOIN classes c ON c.nom = pc.nom_classe AND c.etablissement_id = ? WHERE pc.id_professeur = ?");
                $cs->execute([$etab, (int) $row['id']]);
                foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $cid) { addScope($pdo, $mrId, 'class', (int) $cid); }
            } catch (\Throwable $e) {}
        }
        if ($legacyType === 'vie_scolaire') {
            if (($row['est_CPE'] ?? 'non') === 'oui' || (int) ($row['est_CPE'] ?? 0) === 1) { ensureRole($pdo, $roleId, $mId, 'cpe'); }
            if (($row['est_infirmerie'] ?? 'non') === 'oui' || (int) ($row['est_infirmerie'] ?? 0) === 1) { ensureRole($pdo, $roleId, $mId, 'infirmerie'); }
        }
        $stats['staff']++;
    }
}

// — Élèves —
foreach ($pdo->query("SELECT * FROM eleves")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $etab = (int) ($row['etablissement_id'] ?? 1) ?: 1;
    $accId = ensureAccount($accSvc, 'eleve', $row, 'student');
    $mId = $mbrSvc->ensure($etab, $accId);
    ensureRole($pdo, $roleId, $mId, 'eleve');
    $stats['student']++;
}

// — Parents —
foreach ($pdo->query("SELECT * FROM parents")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $etab = (int) ($row['etablissement_id'] ?? 1) ?: 1;
    $accId = ensureAccount($accSvc, 'parent', $row, 'family');
    $mId = $mbrSvc->ensure($etab, $accId);
    ensureRole($pdo, $roleId, $mId, 'parent');
    $stats['family']++;
}

// — Relations parent → élève (account_relationships, tenant_account) —
try {
    $pe = $pdo->query("SELECT id_parent, id_eleve, lien FROM parent_eleve")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $pe = []; }
foreach ($pe as $link) {
    $parentAcc = $accSvc->findByLegacy('parent', (int) $link['id_parent']);
    $eleveAcc  = $accSvc->findByLegacy('eleve', (int) $link['id_eleve']);
    if (!$parentAcc || !$eleveAcc) { continue; }
    $rel = match ($link['lien'] ?? 'parent') {
        'tuteur_legal'          => 'legal_guardian_of',
        'responsable_financier' => 'financial_responsible_of',
        default                 => 'parent_of',
    };
    $chk = $pdo->prepare("SELECT 1 FROM account_relationships WHERE source_type='tenant_account' AND source_id=? AND target_type='tenant_account' AND target_id=? AND relationship_type=? LIMIT 1");
    $chk->execute([(int) $parentAcc['id'], (int) $eleveAcc['id'], $rel]);
    if (!$chk->fetchColumn()) {
        $pdo->prepare("INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active, created_at) VALUES ('tenant_account', ?, 'tenant_account', ?, ?, 1, NOW())")
            ->execute([(int) $parentAcc['id'], (int) $eleveAcc['id'], $rel]);
        $stats['relations']++;
    }
}

// — Techniciens → comptes plateforme platform_support —
$pSvc = new PlatformAccountService($pdo);
$supportRoleId = (int) $pdo->query("SELECT id FROM platform_roles WHERE role_key='platform_support'")->fetchColumn();
try { $techs = $pdo->query("SELECT * FROM technicien_access")->fetchAll(PDO::FETCH_ASSOC); }
catch (\Throwable $e) { $techs = []; }
foreach ($techs as $t) {
    $login = $t['identifiant'] ?? ($t['email'] ?? null);
    if (!$login) { continue; }
    if ($pSvc->findActiveByLogin($login)) { continue; }
    try {
        $pid = $pSvc->createAccount([
            'email' => $t['email'] ?? ($login . '@support.local'),
            'username' => $login,
            'password_hash' => !empty($t['mot_de_passe']) ? $t['mot_de_passe'] : null,
            'password' => !empty($t['mot_de_passe']) ? null : bin2hex(random_bytes(8)),
            'first_name' => $t['prenom'] ?? 'Support', 'last_name' => $t['nom'] ?? 'Technicien',
            'status' => 'active',
        ]);
        if ($supportRoleId > 0) {
            $pdo->prepare("INSERT INTO platform_account_roles (platform_account_id, platform_role_id, scope_type, is_active) VALUES (?, ?, 'support_only', 1)")
                ->execute([$pid, $supportRoleId]);
        }
        $stats['support']++;
    } catch (\Throwable $e) { error_log('[tenant_migrate] technicien : ' . $e->getMessage()); }
}

echo "Migration terminée :\n";
foreach ($stats as $k => $v) { echo "  {$k} : {$v}\n"; }
