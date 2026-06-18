<?php
/**
 * fmod_cert.php — Issue a Fronote marketplace certificate.
 *
 * A Fronote "cert" is a small JSON document signed by an issuer key:
 *   {
 *     "publisher_id": "fronote-team",
 *     "public_key_b64": "...",     // subject Ed25519 public key
 *     "issuer_fp": "abc123...",    // sha256(issuer public key)
 *     "issued_at":  "2026-05-29T12:00:00Z",
 *     "expires_at": "2027-05-29T12:00:00Z",
 *     "signature_b64": "..."       // Ed25519 sig over the JSON minus this field
 *   }
 *
 * Usage:
 *   php scripts/fmod_cert.php <subject_pub> <issuer_sk> <publisher_id> <days_valid> [out_file]
 *
 * All key paths point to files emitted by fmod_keygen.php (base64 contents).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php scripts/fmod_cert.php <subject_pub> <issuer_sk> <publisher_id> <days> [out_file]\n");
    exit(2);
}
[$_, $subjectPubPath, $issuerSkPath, $publisherId, $daysStr] = $argv;
$days = (int) $daysStr;
$out  = $argv[5] ?? (preg_replace('/[^a-zA-Z0-9_.-]/', '_', $publisherId) . '.cert');

$subjectPub = base64_decode(trim((string) file_get_contents($subjectPubPath)), true);
$issuerSk   = base64_decode(trim((string) file_get_contents($issuerSkPath)), true);
if (!is_string($subjectPub) || strlen($subjectPub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
    fwrite(STDERR, "Bad subject public key\n"); exit(1);
}
if (!is_string($issuerSk) || strlen($issuerSk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Bad issuer secret key\n"); exit(1);
}
$issuerPub = sodium_crypto_sign_publickey_from_secretkey($issuerSk);
$issuerFp  = bin2hex(hash('sha256', $issuerPub, true));

$payload = [
    'publisher_id'   => $publisherId,
    'public_key_b64' => base64_encode($subjectPub),
    'issuer_fp'      => $issuerFp,
    'issued_at'      => gmdate('Y-m-d\TH:i:s\Z'),
    'expires_at'     => gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400),
];
// IMPORTANT: signed bytes must use the exact same canonical representation as the
// verifier (FmodService::canonicalCertBytes — sorted "k=v" lines). Using json_encode
// here would diverge across runtimes (UTF-8 escapes, key ordering quirks).
$signedBytes = \API\Services\FmodService::canonicalCertBytes($payload);
$sig         = sodium_crypto_sign_detached($signedBytes, $issuerSk);
$payload['signature_b64'] = base64_encode($sig);

// Cert on disk: base64 of the JSON envelope. The verifier re-derives the canonical
// signed bytes from this JSON; the JSON itself is only a container.
$certB64 = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
file_put_contents($out, $certB64);
echo "Cert for '{$publisherId}' issued by {$issuerFp}\n";
echo "  → {$out}\n";
