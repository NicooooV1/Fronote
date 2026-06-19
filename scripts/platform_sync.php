<?php
/**
 * Couche PLATEFORME — synchronisation + migration (refonte 3-mondes, Phase 1).
 *
 *   php scripts/platform_sync.php
 *
 * 1. Synchronise platform_roles depuis PlatformRoleCatalog.
 * 2. Migre super_admins → platform_accounts (idempotent via legacy_super_admin_id)
 *    + attribue le rôle super_admin. Ne touche PAS aux tables héritées.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Platform\PlatformRoleSync;
use API\Platform\PlatformAccountService;

$pdo = getPDO();

$r = (new PlatformRoleSync($pdo))->sync();
echo "Rôles plateforme synchronisés : {$r['synced']}\n";
if (!empty($r['errors'])) { fwrite(STDERR, 'Erreurs sync : ' . implode(' ; ', $r['errors']) . "\n"); }

$svc = new PlatformAccountService($pdo);
$saRoleId = (int) $pdo->query("SELECT id FROM platform_roles WHERE role_key = 'super_admin'")->fetchColumn();

$created = 0; $skipped = 0; $errors = 0;
try {
    $rows = $pdo->query("SELECT * FROM super_admins")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "Table super_admins absente.\n");
    $rows = [];
}
foreach ($rows as $sa) {
    $legacyId = (int) ($sa['id'] ?? 0);
    if ($legacyId <= 0) { continue; }
    if ($svc->findByLegacySuperAdmin($legacyId)) { $skipped++; continue; }
    try {
        $accId = $svc->createAccount([
            'email'         => $sa['mail'] ?? ('sa' . $legacyId . '@platform.local'),
            'username'      => $sa['identifiant'] ?? ('superadmin' . $legacyId),
            'password_hash' => $sa['mot_de_passe'] ?? null,
            'first_name'    => $sa['prenom'] ?? 'Super',
            'last_name'     => $sa['nom'] ?? 'Admin',
            'status'        => (isset($sa['actif']) && (int) $sa['actif'] === 0) ? 'inactive' : 'active',
            'legacy_super_admin_id' => $legacyId,
        ]);
        if ($saRoleId > 0) {
            $pdo->prepare(
                "INSERT INTO platform_account_roles (platform_account_id, platform_role_id, scope_type, is_active)
                 VALUES (?, ?, 'global', 1)"
            )->execute([$accId, $saRoleId]);
        }
        $created++;
    } catch (\Throwable $e) {
        $errors++;
        error_log('[platform_sync] super_admin#' . $legacyId . ' : ' . $e->getMessage());
    }
}
echo "Comptes plateforme créés depuis super_admins : {$created} · déjà présents : {$skipped} · erreurs : {$errors}\n";
