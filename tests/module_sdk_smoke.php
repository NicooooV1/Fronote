<?php
/**
 * Smoke test : ModuleSDK discovery works and every module exposes a route.
 * Used by CI as a cheap signal that the module layout did not regress.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/API/bootstrap.php';

use API\Services\ModuleSDK;

// CI : sqlite éphémère — on n'exerce que discover() qui n'interroge pas la base.
$sdk = new ModuleSDK(new \PDO('sqlite::memory:'), $root);
$manifests = $sdk->discover();
if (count($manifests) < 30) {
    fwrite(STDERR, "Only " . count($manifests) . " modules discovered — layout regression?\n");
    exit(1);
}

$problems = 0;
foreach ($manifests as $key => $m) {
    if (empty($m['routes']['main'])) {
        echo "[{$key}] no routes.main declared\n";
        $problems++;
        continue;
    }
    $path = ($m['_path'] ?? '') . DIRECTORY_SEPARATOR . $m['routes']['main'];
    if (!is_file($path)) {
        echo "[{$key}] routes.main points to a missing file: {$path}\n";
        $problems++;
    }
}
if ($problems > 0) {
    fwrite(STDERR, "{$problems} module(s) with broken route.\n");
    exit(1);
}
echo count($manifests) . " modules OK.\n";
