<?php
/**
 * fmod_verify.php — Verify a Fronote module package (.fmod) signature and integrity.
 *
 * Usage:
 *   php scripts/fmod_verify.php <pkg.fmod> [root_pubkey ...]
 *
 * If no root_pubkey paths are given, the script loads every *.pub file under
 * config/marketplace/roots/ as a trusted Root CA.
 *
 * Exit codes:
 *   0 = valid, ready to install
 *   1 = verification failed
 *   2 = bad usage / I/O error
 */

declare(strict_types=1);

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\FmodService;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/fmod_verify.php <pkg.fmod> [root_pubkey ...]\n");
    exit(2);
}
$pkg     = $argv[1];
$rootArg = array_slice($argv, 2);

$roots = [];
$rootFiles = !empty($rootArg) ? $rootArg : glob(__DIR__ . '/../config/marketplace/roots/*.pub');
foreach ($rootFiles ?: [] as $f) {
    $pkB64 = trim((string) file_get_contents($f));
    if ($pkB64 === '') continue;
    $fp = FmodService::fingerprint($pkB64);
    $roots[$fp] = $pkB64;
}
if (empty($roots)) {
    fwrite(STDERR, "No Root CA configured (config/marketplace/roots/*.pub is empty).\n");
    exit(2);
}

$coreVersion = '2.1.0';
$verJson = @file_get_contents(__DIR__ . '/../version.json');
if ($verJson !== false) {
    $v = json_decode($verJson, true);
    if (is_array($v) && !empty($v['version'])) $coreVersion = (string) $v['version'];
}

$svc = new FmodService($roots);
$res = $svc->verifyPackage($pkg, $coreVersion);

if ($res['ok']) {
    echo "OK   {$pkg}\n";
    echo "  module    : " . ($res['manifest']['key'] ?? '?') . "\n";
    echo "  version   : " . ($res['manifest']['version'] ?? '?') . "\n";
    echo "  publisher : " . ($res['signature']['publisher_id'] ?? '?') . "\n";
    exit(0);
}
echo "FAIL {$pkg}\n";
foreach ($res['errors'] as $e) {
    echo "  - {$e}\n";
}
exit(1);
