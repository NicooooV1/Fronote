#!/usr/bin/env php
<?php
/**
 * Fronote CLI — Install a .fmod module package.
 *
 * Usage:
 *   php scripts/install-module.php <path/to/module.fmod> [--allow-test]
 *
 * Options:
 *   --allow-test   Allow test_only modules (equivalent to ALLOW_TEST_MODULES=true)
 *   --dry-run      Verify signature and scan without installing
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

// ── Bootstrap ────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('API_PATH', BASE_PATH . '/API');

require_once API_PATH . '/bootstrap.php';

// ── Parse args ───────────────────────────────────────────────────────────
$args      = array_slice($argv, 1);
$fmodPath  = null;
$allowTest = false;
$dryRun    = false;

foreach ($args as $arg) {
    if ($arg === '--allow-test') { $allowTest = true; }
    elseif ($arg === '--dry-run') { $dryRun = true; }
    elseif (!str_starts_with($arg, '--')) { $fmodPath = $arg; }
}

if (!$fmodPath) {
    echo "Usage: php scripts/install-module.php <module.fmod> [--allow-test] [--dry-run]\n";
    exit(1);
}

if (!is_file($fmodPath)) {
    echo "[ERROR] File not found: {$fmodPath}\n";
    exit(1);
}

if ($allowTest) {
    putenv('ALLOW_TEST_MODULES=true');
}

echo "Fronote Module Installer\n";
echo str_repeat('─', 50) . "\n";
echo "Package : {$fmodPath}\n";
echo "Size    : " . round(filesize($fmodPath) / 1024, 1) . " KB\n";
echo str_repeat('─', 50) . "\n";

// ── Verify ───────────────────────────────────────────────────────────────
$rootsDir = BASE_PATH . '/config/marketplace/roots';
$rootFiles = glob($rootsDir . '/*.pub') ?: [];

if (empty($rootFiles)) {
    echo "[ERROR] No Root CA configured in {$rootsDir}\n";
    echo "        Run scripts/pki/generate-test-ca.sh to generate test PKI.\n";
    exit(1);
}

echo "[INFO] Root CA keys found: " . count($rootFiles) . "\n";
foreach ($rootFiles as $f) {
    echo "       - " . basename($f) . "\n";
}
echo "\n";

/** @var \API\Services\MarketplaceService $marketplace */
$marketplace = app('marketplace');

if ($dryRun) {
    // Dry run: verify only, no install
    $roots = [];
    foreach ($rootFiles as $f) {
        $pkB64 = trim((string) @file_get_contents($f));
        if ($pkB64 === '') continue;
        try {
            $fp = \API\Services\FmodService::fingerprint($pkB64);
            $roots[$fp] = $pkB64;
        } catch (\Throwable $e) {}
    }
    $fmod   = new \API\Services\FmodService($roots);
    $result = $fmod->verifyPackage($fmodPath, '2.0.0');

    echo "[DRY-RUN] Verification results:\n";
    if ($result['ok']) {
        echo "[OK] Signature and integrity verified\n";
        echo "[OK] Module: " . ($result['manifest']['key'] ?? '?') . " v" . ($result['manifest']['version'] ?? '?') . "\n";
        echo "[OK] Publisher: " . ($result['signature']['publisher_id'] ?? '?') . "\n";
    } else {
        echo "[FAIL] Verification failed:\n";
        foreach ($result['errors'] as $err) {
            echo "  - {$err}\n";
        }
        exit(1);
    }
    exit(0);
}

// ── Install ───────────────────────────────────────────────────────────────
echo "Verifying .fmod package...\n";
$result = $marketplace->installFromFmod($fmodPath);

if (!empty($result['pending_consent'])) {
    $manifest = $result['manifest'];
    $perms    = $result['permissions'];
    echo "\n[CONSENT REQUIRED] Module requests the following permissions:\n";
    foreach ($perms as $perm) {
        echo "  - {$perm}\n";
    }
    echo "\nGrant all permissions? [y/N] ";
    $answer = trim(fgets(STDIN) ?: '');
    if (strtolower($answer) !== 'y') {
        echo "Installation cancelled.\n";
        // Clean up staging
        if (is_dir($result['staging'])) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($result['staging'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
            rmdir($result['staging']);
        }
        exit(1);
    }
    $result = $marketplace->confirmInstall($result['staging'], $manifest, $fmodPath, $result['sig_data']);
}

if ($result['success'] ?? false) {
    echo "[OK] " . ($result['message'] ?? 'Module installed.') . "\n";
    echo "[OK] Module: " . ($result['module']['key'] ?? '?') . " v" . ($result['module']['version'] ?? '?') . "\n";
    exit(0);
} else {
    echo "[ERROR] " . ($result['error'] ?? 'Unknown error') . "\n";
    if (!empty($result['violations'])) {
        echo "Security violations:\n";
        foreach ($result['violations'] as $v) {
            echo "  - " . (is_string($v) ? $v : json_encode($v)) . "\n";
        }
    }
    exit(1);
}
