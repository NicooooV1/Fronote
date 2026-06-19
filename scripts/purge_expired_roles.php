<?php
/**
 * Purge des rôles attribués EXPIRÉS (user_roles.valid_until dépassé).
 *
 *   php scripts/purge_expired_roles.php
 *
 * À planifier en cron (ex. quotidien) pour garantir l'expiration automatique des
 * accès temporaires (technicien, vacataire, invité…) exigée par le cahier des
 * charges (§10.5). Chaque purge est journalisée dans user_role_audit_logs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\RoleManagementService;

$svc = new RoleManagementService(getPDO());
$n   = $svc->purgeExpired();
echo "Rôles expirés purgés : {$n}\n";
