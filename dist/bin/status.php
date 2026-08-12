<?php
declare(strict_types=1);
/**
 * Consultation. Usage :
 *   php dist/bin/status.php <ip>       → statut d'une IP (whitelistée ?)
 *   php dist/bin/status.php --list     → liste des IP whitelistées
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';
try {
    $store = dist_store(dist_config());
    $arg = $argv[1] ?? '';
    if ($arg === '--list') {
        $rows = $store->listAllowed();
        if (!$rows) { echo "Whitelist vide.\n"; exit(0); }
        foreach ($rows as $r) { echo "  {$r['ip']}" . ($r['note'] ? "  ({$r['note']})" : '') . "  depuis {$r['added_at']}\n"; }
    } elseif ($arg !== '') {
        $s = $store->ipStatus($arg);
        echo "IP {$s['ip']} : " . ($s['allowed'] ? "WHITELISTÉE" : "non autorisée")
            . ($s['note'] ? " · {$s['note']}" : '') . ($s['added_at'] ? " · depuis {$s['added_at']}" : '') . "\n";
    } else {
        fwrite(STDERR, "Usage : php dist/bin/status.php <ip> | --list\n"); exit(2);
    }
    exit(0);
} catch (\Throwable $e) { fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n"); exit(1); }
