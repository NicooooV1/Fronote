<?php
/**
 * fmod_keygen.php — Generate an Ed25519 keypair for Fronote marketplace.
 *
 * Usage:
 *   php scripts/fmod_keygen.php <name> [out_dir]
 *
 * Writes:
 *   <out_dir>/<name>.sk   (base64 secret key — KEEP SECRET, never commit)
 *   <out_dir>/<name>.pub  (base64 public key)
 *   <out_dir>/<name>.fp   (sha256 fingerprint of the public key, hex)
 *
 * Root CA keys live under config/marketplace/roots/.
 * Intermediate and editor secret keys must NEVER touch the repository.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\FmodService;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/fmod_keygen.php <name> [out_dir]\n");
    exit(2);
}
$name   = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $argv[1]);
$outDir = $argv[2] ?? (__DIR__ . '/../config/marketplace/keys');

if (!is_dir($outDir) && !mkdir($outDir, 0700, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

[$sk, $pk, $fp] = FmodService::generateKeypair();

$skPath  = $outDir . '/' . $name . '.sk';
$pkPath  = $outDir . '/' . $name . '.pub';
$fpPath  = $outDir . '/' . $name . '.fp';

// Restrict umask so file_put_contents creates the secret key with 0600 from the start
// (chmod-after-creation leaves a tiny window where the file is world-readable on
// shared hosts).
$prevUmask = umask(0177); // → mode 0666 & ~0177 = 0600 for new files
try {
    file_put_contents($skPath, $sk);
} finally {
    umask($prevUmask);
}
@chmod($skPath, 0600); // belt and suspenders
file_put_contents($pkPath, $pk);
file_put_contents($fpPath, $fp);

echo "Keypair '{$name}' generated.\n";
echo "  secret      : {$skPath}\n";
echo "  public      : {$pkPath}\n";
echo "  fingerprint : {$fp}\n";
echo "\n⚠️  KEEP THE .sk FILE OUT OF VCS. Add config/marketplace/keys/ to .gitignore.\n";
