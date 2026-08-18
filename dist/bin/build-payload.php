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

/**
 * Version du produit distribué. Priorité :
 *   1. git (si le dépôt est présent à la racine app) → semver du VERSION + commit court
 *   2. fichier VERSION à la racine app (semver « 4.0.1 »)
 *   3. « 0.0.0 » (dernier recours)
 * Renvoie [version, build] où build = « <semver>+<sha7|date> ».
 *
 * ⚠️ Avant 2026-08-18, RIEN ne portait de version : ni l'archive, ni le manifeste, ni le
 * client. Impossible de savoir quelle version tournait où, ni de comparer local vs serveur
 * pour une mise à jour. version.json (écrit à la racine app, donc inclus dans base.tar.gz)
 * corrige ça de bout en bout.
 */
function fronote_version(string $appRoot): array
{
    $semver = '0.0.0';
    $vfile = $appRoot . '/VERSION';
    if (is_file($vfile)) {
        $semver = trim((string) file_get_contents($vfile)) ?: $semver;
    }
    $commit = '';
    if (is_dir($appRoot . '/.git')) {
        $sha = @trim((string) shell_exec('git -C ' . escapeshellarg($appRoot) . ' rev-parse --short HEAD 2>/dev/null'));
        if ($sha !== '') { $commit = $sha; }
    }
    $build = $semver . '+' . ($commit !== '' ? $commit : date('Ymd.His'));
    return [$semver, $build];
}

try {
    $cfg = dist_config();
    $appRoot = (string) $cfg['app_root'];
    $out = rtrim((string) $cfg['data_dir'], '/') . '/payloads';
    $builder = new \Dist\PayloadBuilder(
        $appRoot, $out, dist_signer($cfg), (array) ($cfg['simple_modules'] ?? [])
    );

    // ── Stamp de version : écrit AVANT le tar pour être inclus dans base.tar.gz ──
    [$semver, $build] = fronote_version($appRoot);
    $channel = (string) ($cfg['release_channel'] ?? 'stable');
    $versionInfo = [
        'version'  => $semver,
        'build'    => $build,
        'channel'  => $channel,
        'built_at' => date('c'),
    ];
    file_put_contents(
        $appRoot . '/version.json',
        json_encode($versionInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $report = [];
    if ($mod = $get('module')) {
        $report['module:' . $mod] = $builder->buildModule($mod);
    } elseif ($has('--base')) {
        $report['base'] = $builder->buildBase();
    } else {
        $report = $builder->buildAll();
    }

    // Manifest récapitulatif (version + sha256/tailles) — sert au contrôle croisé client
    // ET au canal de mise à jour (update.sh compare la version locale à GET /manifest).
    // Fusion : on RELIT le manifeste existant et n'écrase que les artefacts reconstruits,
    // pour qu'un rebuild partiel (--base ou --module=x) ne réduise pas le manifeste à un
    // seul artefact (constat pipeline 2026-08-18).
    $manifestFile = $out . '/manifest.json';
    $prev = is_file($manifestFile) ? (json_decode((string) file_get_contents($manifestFile), true) ?: []) : [];
    $manifest = [
        'version'        => $semver,
        'build'          => $build,
        'channel'        => $channel,
        'built_at'       => date('c'),
        'public_key'     => dist_signer($cfg)->publicKeyHex(),
        'simple_modules' => $builder->baseModules(),
        'premium_modules' => $builder->premiumModules(),
        'artifacts'      => is_array($prev['artifacts'] ?? null) ? $prev['artifacts'] : [],
    ];
    foreach ($report as $name => $r) {
        $manifest['artifacts'][$name] = [
            'file'   => basename($r['archive']),
            'sha256' => $r['sha256'],
            'bytes'  => $r['bytes'],
            // Signature Ed25519 (hex) embarquée : permet à update.sh et au mode --resume du
            // bootstrapper de vérifier l'AUTHENTICITÉ du socle sans re-redeem (2026-08-18).
            'sig'    => trim((string) @file_get_contents($r['sig'])),
        ];
        printf("  ✓ %-22s %8.1f Ko  sha256=%s…\n", basename($r['archive']), $r['bytes'] / 1024, substr($r['sha256'], 0, 12));
    }
    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\n✓ Payloads + signatures écrits dans {$out}\n";
    echo "  version {$build} · manifest.json (" . count($manifest['artifacts']) . " artefact(s)).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n");
    exit(1);
}
