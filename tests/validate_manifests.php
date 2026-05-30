<?php
/**
 * Validate every modules/<m>/module.json against the ModuleSDK schema rules.
 * Exits non-zero on the first invalid manifest. Used by CI.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/API/bootstrap.php';

use API\Services\ModuleSDK;

// CI : on n'a pas de MySQL — discover()/validate() ne lisent jamais la base, donc on
// passe un PDO sqlite éphémère pour satisfaire le typage du constructeur.
$pdo = new \PDO('sqlite::memory:');
$sdk = new ModuleSDK($pdo, $root);

$manifests = $sdk->discover();
$errors = [];

if (empty($manifests)) {
    fwrite(STDERR, "No manifest discovered — repository broken or layout changed.\n");
    exit(1);
}

foreach ($manifests as $key => $manifest) {
    $res = $sdk->validate($manifest);
    if (!$res['valid']) {
        $errors[] = "[{$key}] " . implode(' | ', $res['errors']);
    }
}

if (!empty($errors)) {
    foreach ($errors as $e) echo $e . PHP_EOL;
    exit(1);
}
echo count($manifests) . " manifests validated successfully.\n";
