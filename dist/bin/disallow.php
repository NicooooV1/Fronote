<?php
declare(strict_types=1);
/** Retire une IP de la whitelist. Usage : php dist/bin/disallow.php <ip> */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';
$ip = $argv[1] ?? '';
if ($ip === '') { fwrite(STDERR, "Usage : php dist/bin/disallow.php <ip>\n"); exit(2); }
try {
    $removed = dist_store(dist_config())->disallowIp($ip);
    echo $removed ? "✓ IP {$ip} retirée de la whitelist.\n" : "• IP {$ip} n'était pas whitelistée.\n";
    exit(0);
} catch (\Throwable $e) { fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n"); exit(1); }
