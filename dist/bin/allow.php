<?php
declare(strict_types=1);
/** Whiteliste une IP (l'autorise à échanger une clé et télécharger).
 *  Usage : php dist/bin/allow.php <ip> ["note"] */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';
$ip = $argv[1] ?? '';
if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { fwrite(STDERR, "Usage : php dist/bin/allow.php <ip> [note]\n"); exit(2); }
try {
    dist_store(dist_config())->allowIp($ip, (string) ($argv[2] ?? ''));
    echo "✓ IP {$ip} whitelistée.\n";
    exit(0);
} catch (\Throwable $e) { fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n"); exit(1); }
