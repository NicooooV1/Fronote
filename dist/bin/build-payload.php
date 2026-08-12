<?php
declare(strict_types=1);
/**
 * Construit + signe les archives de distribution dans dist/data/payloads/ :
 *   - base.tar.gz          (socle + modules simples)
 *   - module-<x>.tar.gz    (chaque module premium)
 * plus un .sig (Ed25519) par archive et un manifest.json récapitulatif.
 *
 * Usage :
 *   php dist/bin/build-payload.php           # tout (base + tous les modules premium)
 *   php dist/bin/build-payload.php --base    # seulement le socle
 *   php dist/bin/build-payload.php --module=internat
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';

$has = static fn(string $f): bool => in_array($f, $GLOBALS['argv'], true);
$get = static function (string $n) use ($argv): ?string {
    foreach ($argv as $a) { if (str_starts_with($a, "--{$n}=")) { return substr($a, strlen($n) + 3); } }
    return null;
};

try {
    $cfg = dist_config();
    $out = rtrim((string) $cfg['data_dir'], '/') . '/payloads';
    $builder = new \Dist\PayloadBuilder(
        (string) $cfg['app_root'], $out, dist_signer($cfg), (array) ($cfg['simple_modules'] ?? [])
    );

    $report = [];
    if ($mod = $get('module')) {
        $report['module:' . $mod] = $builder->buildModule($mod);
    } elseif ($has('--base')) {
        $report['base'] = $builder->buildBase();
    } else {
        $report = $builder->buildAll();
    }

    // Manifest récapitulatif (sha256/tailles) — utile au client pour un contrôle croisé.
    $manifest = [
        'built_at'       => date('c'),
        'public_key'     => dist_signer($cfg)->publicKeyHex(),
        'simple_modules' => array_values((array) ($cfg['simple_modules'] ?? [])),
        'premium_modules' => $builder->premiumModules(),
        'artifacts'      => [],
    ];
    foreach ($report as $name => $r) {
        $manifest['artifacts'][$name] = [
            'file'   => basename($r['archive']),
            'sha256' => $r['sha256'],
            'bytes'  => $r['bytes'],
        ];
        printf("  ✓ %-22s %8.1f Ko  sha256=%s…\n", basename($r['archive']), $r['bytes'] / 1024, substr($r['sha256'], 0, 12));
    }
    file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\n✓ Payloads + signatures écrits dans {$out}\n";
    echo "  manifest.json généré (" . count($manifest['artifacts']) . " artefact(s)).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n");
    exit(1);
}
