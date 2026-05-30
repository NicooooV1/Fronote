<?php
/**
 * fmod_build.php — Build a signed Fronote module package (.fmod) from a source directory.
 *
 * Usage:
 *   php scripts/fmod_build.php <source_dir> <out.fmod> <editor_sk> <publisher_id> <cert1> [cert2 ...]
 *
 * Example:
 *   php scripts/fmod_build.php modules/cantine dist/cantine-2.3.1.fmod \
 *        config/marketplace/keys/fronote-team.sk fronote-team \
 *        config/marketplace/certs/fronote-team.cert \
 *        config/marketplace/certs/registry-intermediate.cert
 *
 * The cert chain order is: editor cert first, then intermediates, up to the cert
 * issued by the Root CA. The Root CA itself is NOT in the chain — it is embedded
 * in the client.
 */

declare(strict_types=1);

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\FmodService;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php scripts/fmod_build.php <source_dir> <out.fmod> <editor_sk> <publisher_id> <cert1> [cert2 ...]\n");
    exit(2);
}
$sourceDir   = $argv[1];
$outPath     = $argv[2];
$skPath      = $argv[3];
$publisherId = $argv[4];
$certPaths   = array_slice($argv, 5);

if (empty($certPaths)) {
    fwrite(STDERR, "At least one certificate is required (editor cert).\n");
    exit(2);
}
$sk      = trim((string) file_get_contents($skPath));
$chain   = array_map(fn($p) => trim((string) file_get_contents($p)), $certPaths);

$svc = new FmodService();
$svc->buildPackage($sourceDir, $outPath, $sk, $publisherId, $chain);

$pkgSha = hash_file('sha256', $outPath);
echo "Built: {$outPath}\n";
echo "  publisher : {$publisherId}\n";
echo "  sha256    : {$pkgSha}\n";
