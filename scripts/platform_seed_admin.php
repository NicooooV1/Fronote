<?php
/**
 * Crée un compte plateforme super_admin (pour accéder au portail /platform).
 *
 *   php scripts/platform_seed_admin.php <email> <username> [password]
 *
 * Si le mot de passe est omis, un mot de passe fort est généré et affiché UNE FOIS.
 * Idempotent : si l'email/identifiant existe déjà, ne fait rien.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Platform\PlatformAccountService;
use API\Platform\PlatformRoleSync;

$email    = $argv[1] ?? null;
$username = $argv[2] ?? null;
$password = $argv[3] ?? null;
if (!$email || !$username) {
    fwrite(STDERR, "Usage : php scripts/platform_seed_admin.php <email> <username> [password]\n");
    exit(1);
}

$pdo = getPDO();
(new PlatformRoleSync($pdo))->sync();

$svc = new PlatformAccountService($pdo);
if ($svc->findActiveByLogin($email) || $svc->findActiveByLogin($username)) {
    echo "Un compte plateforme existe déjà pour cet email/identifiant — rien à faire.\n";
    exit(0);
}

if (!$password) {
    $password = bin2hex(random_bytes(9)); // 18 hex chars
}

$accId = $svc->createAccount([
    'email' => $email, 'username' => $username, 'password' => $password,
    'first_name' => 'Super', 'last_name' => 'Admin', 'status' => 'active',
]);
$saRoleId = (int) $pdo->query("SELECT id FROM platform_roles WHERE role_key = 'super_admin'")->fetchColumn();
$pdo->prepare("INSERT INTO platform_account_roles (platform_account_id, platform_role_id, scope_type, is_active) VALUES (?, ?, 'global', 1)")
    ->execute([$accId, $saRoleId]);

echo "Compte super_admin plateforme créé (#{$accId}).\n";
echo "  identifiant : {$username}\n";
echo "  email       : {$email}\n";
echo "  mot de passe : {$password}\n";
echo "Connexion : /platform/login.php\n";
