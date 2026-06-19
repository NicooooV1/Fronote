<?php
/**
 * Couche ÉTABLISSEMENT — synchronisation du catalogue de rôles (refonte 3-mondes).
 *
 *   php scripts/tenant_sync.php
 *
 * Réconcilie tenant_roles depuis TenantRoleCatalog (aucun rôle plateforme).
 * L'autorisation s'appuie sur le catalogue en code ; tenant_permissions /
 * tenant_role_permissions restent réservés à une future matrice éditable.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Tenant\TenantRoleSync;

$r = (new TenantRoleSync(getPDO()))->sync();
echo "Rôles établissement synchronisés : {$r['synced']}\n";
if (!empty($r['errors'])) { fwrite(STDERR, 'Erreurs : ' . implode(' ; ', $r['errors']) . "\n"); exit(1); }
