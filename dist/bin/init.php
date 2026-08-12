<?php
declare(strict_types=1);
/**
 * Initialise le serveur de distribution : base SQLite (schéma) + paire de clés de
 * signature Ed25519. Affiche la CLÉ PUBLIQUE (hex) à embarquer dans le bootstrapper.
 * Idempotent : ne réécrit pas des clés existantes.
 *
 * Usage : php dist/bin/init.php
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';

try {
    $cfg = dist_config();
    $dir = rtrim((string) $cfg['data_dir'], '/');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer {$dir}");
    }
    dist_store($cfg); // crée/migre le schéma
    $res = \Dist\Signer::ensureKeypair($dir . '/signing.key', $dir . '/signing.pub');

    echo "✓ Base SQLite initialisée : {$dir}/dist.sqlite\n";
    echo ($res['created'] ? "✓ Paire de clés de signature générée.\n" : "• Paire de clés déjà présente (conservée).\n");
    echo "\nCLÉ PUBLIQUE (à embarquer dans le bootstrapper, variable PUBKEY_HEX) :\n";
    echo $res['public_hex'] . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n");
    exit(1);
}
