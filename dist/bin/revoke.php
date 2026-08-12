<?php
declare(strict_types=1);
/**
 * Révoque une licence. Usage :
 *   php dist/bin/revoke.php --key=<clé 48 car>
 *   php dist/bin/revoke.php --prefix=<6 premiers car>   (si la clé complète est perdue)
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';
$get = static function (string $n) use ($argv): ?string {
    foreach ($argv as $a) { if (str_starts_with($a, "--{$n}=")) { return substr($a, strlen($n) + 3); } }
    return null;
};
try {
    $store = dist_store(dist_config());
    if ($key = $get('key')) {
        echo $store->revokeLicenseByKey($key) ? "✓ Licence révoquée.\n" : "• Aucune licence pour cette clé.\n";
    } elseif ($prefix = $get('prefix')) {
        $n = $store->revokeLicenseByPrefix($prefix);
        echo $n > 0 ? "✓ {$n} licence(s) révoquée(s) (préfixe {$prefix}).\n" : "• Aucune licence active pour ce préfixe.\n";
    } else {
        fwrite(STDERR, "Usage : php dist/bin/revoke.php --key=<clé> | --prefix=<6 car>\n"); exit(2);
    }
    exit(0);
} catch (\Throwable $e) { fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n"); exit(1); }
