<?php
declare(strict_types=1);

/**
 * Entrée CLI de l'installation Fronote (provisioning applicatif headless).
 *
 * Appelé par install.sh APRÈS la création de la base + de l'utilisateur DB. Lit sa
 * configuration depuis des variables d'environnement préfixées FRONOTE_ (pour ne pas
 * interférer avec la configuration applicative lue dans .env), puis délègue à
 * API\Install\HeadlessInstaller.
 *
 * Usage :
 *   FRONOTE_DB_NAME=... FRONOTE_DB_USER=... FRONOTE_DB_PASS=... \
 *   FRONOTE_APP_URL=... FRONOTE_ADMIN_MAIL=... FRONOTE_ADMIN_PW=... \
 *   php bin/install.php [--force]
 *
 * --force : réinstalle même si install.lock existe (le supprime au préalable).
 * Sortie : 0 = succès ; 1 = erreur dure. Journal lisible sur stdout.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ce script ne peut être exécuté qu'en ligne de commande.\n");
    exit(2);
}

$baseDir = dirname(__DIR__);
$force = in_array('--force', $argv, true) || getenv('FRONOTE_FORCE') === '1';

$lockFile = $baseDir . '/install.lock';
if (is_file($lockFile) && !$force) {
    fwrite(STDERR, "Installation déjà effectuée (install.lock présent). Utilisez --force pour réinstaller.\n");
    exit(3);
}
if ($force && is_file($lockFile)) {
    @unlink($lockFile);
}

$autoload = $baseDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php introuvable — lancez d'abord « composer install ».\n");
    exit(4);
}
require $autoload;

/** Lit une variable FRONOTE_<NAME> avec valeur par défaut. */
$env = static function (string $name, string $default = ''): string {
    $v = getenv('FRONOTE_' . $name);
    return ($v === false || $v === '') ? $default : $v;
};
$bool = static function (string $name, bool $default = false) use ($env): bool {
    $v = strtolower($env($name, $default ? '1' : '0'));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
};

$appUrl   = $env('APP_URL', 'http://localhost');
$protocol = str_starts_with($appUrl, 'https') ? 'https' : 'http';

$config = [
    // Base de données (créée en amont par install.sh)
    'db_host'    => $env('DB_HOST', '127.0.0.1'),
    'db_port'    => (int) $env('DB_PORT', '3306'),
    'db_name'    => $env('DB_NAME', 'fronote'),
    'db_user'    => $env('DB_USER', 'fronote'),
    'db_pass'    => $env('DB_PASS'),
    'db_charset' => $env('DB_CHARSET', 'utf8mb4'),

    // Application
    'app_name'   => $env('APP_NAME', 'Fronote'),
    'app_env'    => $env('APP_ENV', 'production'),
    'app_debug'  => $bool('APP_DEBUG', false),
    'app_url'    => $appUrl,
    'base_url'   => $env('BASE_URL'),
    'protocol'   => $protocol,
    'app_timezone' => $env('APP_TIMEZONE', 'Europe/Paris'),
    'trust_proxy'  => $bool('TRUST_PROXY', false),
    'allowed_install_ip' => $env('ALLOWED_INSTALL_IP'),

    // Administrateur
    'admin_nom'    => $env('ADMIN_NOM', 'Admin'),
    'admin_prenom' => $env('ADMIN_PRENOM', 'Fronote'),
    'admin_mail'   => $env('ADMIN_MAIL'),
    'admin_pw'     => $env('ADMIN_PW'),

    // WebSocket
    'websocket_enabled'        => $bool('WEBSOCKET_ENABLED', false),
    'websocket_url'            => $env('WEBSOCKET_URL', 'http://127.0.0.1:3000'),
    'websocket_client_url'     => $env('WEBSOCKET_CLIENT_URL', 'http://127.0.0.1:3000'),
    'websocket_allowed_origins' => $env('WEBSOCKET_ALLOWED_ORIGINS'),

    // wipe=true : réinitialisation DESTRUCTIVE (joue les DROP TABLE du socle → efface les
    // données). Défaut false : import non destructif, données préservées sur ré-exécution.
    'wipe' => $bool('WIPE', false),
];

fwrite(STDOUT, "── Fronote · installation applicative (headless) ──\n");

try {
    $installer = new \API\Install\HeadlessInstaller($baseDir, $config);
    $log = $installer->run();
    foreach ($log as $entry) {
        $icon = $entry['level'] === 'ok' ? '  ✓' : '  ⚠';
        // Icônes ASCII portables (évite les soucis d'encodage sur certains terminaux).
        $icon = $entry['level'] === 'ok' ? '  [OK]  ' : '  [WARN]';
        fwrite(STDOUT, $icon . ' ' . $entry['msg'] . "\n");
    }
    fwrite(STDOUT, "\nInstallation applicative terminée avec succès.\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[ÉCHEC] " . $e->getMessage() . "\n");
    exit(1);
}
