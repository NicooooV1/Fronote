<?php
declare(strict_types=1);
/**
 * FmodService — Build, sign and verify Fronote module packages (.fmod).
 *
 * A .fmod package is a ZIP that contains, at its root:
 *   - the module source tree (module.json + code + Database/install.sql + assets…)
 *   - MANIFEST.sha256  — line-per-file `<sha256>  <relative path>` (sorted by path)
 *   - SIGNATURE.json   — detached Ed25519 signature of MANIFEST.sha256 + cert chain
 *
 * The trust model is "zero network trust": the client verifies signatures against
 * a Root CA public key embedded in the install (config/marketplace/roots/*.pub),
 * regardless of the source URL. TLS is necessary, never sufficient.
 *
 * No Composer dependency. Uses ext-sodium (Ed25519) and ext-zip only.
 */

namespace API\Services;

class FmodService
{
    public const MANIFEST_FILE  = 'MANIFEST.sha256';
    public const SIGNATURE_FILE = 'SIGNATURE.json';
    public const SIG_ALG        = 'Ed25519';

    /** Files we never include in the package or the manifest. */
    private const EXCLUDE_NAMES = ['.git', '.gitignore', '.DS_Store', 'node_modules', '.idea', '.vscode'];

    /** @var array<string,string> fingerprint => label of embedded Root CA public keys */
    private array $rootKeys;

    /**
     * @param array<string,string> $rootKeys [fingerprint_hex => base64_public_key]
     *        Empty array = no Root CA configured; verifyPackage() will refuse.
     */
    public function __construct(array $rootKeys = [])
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('ext-sodium is required for FmodService');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ext-zip is required for FmodService');
        }
        $this->rootKeys = $rootKeys;
    }

    // ───────────────────────── Key management ─────────────────────────

    /**
     * Generate an Ed25519 keypair. Returns [secret_key_base64, public_key_base64, fingerprint_hex].
     * The secret key MUST be stored securely; never commit it.
     */
    public static function generateKeypair(): array
    {
        $kp  = sodium_crypto_sign_keypair();
        $sk  = sodium_crypto_sign_secretkey($kp);
        $pk  = sodium_crypto_sign_publickey($kp);
        $fp  = bin2hex(hash('sha256', $pk, true));
        return [base64_encode($sk), base64_encode($pk), $fp];
    }

    /**
     * Fingerprint of a base64-encoded public key.
     */
    public static function fingerprint(string $publicKeyBase64): string
    {
        $pk = base64_decode($publicKeyBase64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('Invalid Ed25519 public key');
        }
        return bin2hex(hash('sha256', $pk, true));
    }

    /**
     * Fingerprint of a base64-encoded certificate (sha256 of its decoded raw bytes).
     * Returns null if the input is not valid base64 — never silently hashes the b64 string.
     */
    public static function certFingerprint(string $certBase64): ?string
    {
        $raw = base64_decode($certBase64, true);
        if (!is_string($raw) || $raw === '') return null;
        return bin2hex(hash('sha256', $raw, true));
    }

    /**
     * Deterministic byte representation of a certificate payload for signing/verification.
     *
     * Format: sorted "key=value" lines joined by "\n" (no trailing newline).
     * This intentionally avoids `json_encode` whose output varies across runtimes
     * (UTF-8 escaping, integer-key ordering, slash escaping). The payload is restricted
     * to a flat assoc of string-typed leaves; any non-scalar leaf is rejected.
     *
     * The signature field ('signature_b64') is excluded from the canonical form.
     */
    public static function canonicalCertBytes(array $cert): string
    {
        $payload = $cert;
        unset($payload['signature_b64']);
        ksort($payload, SORT_STRING);
        $lines = [];
        foreach ($payload as $k => $v) {
            if (!is_string($k) || $k === '') {
                throw new \InvalidArgumentException('cert payload key must be a non-empty string');
            }
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (is_int($v) || is_float($v)) {
                $v = (string) $v;
            }
            if (!is_string($v)) {
                throw new \InvalidArgumentException("cert payload value for '{$k}' must be scalar");
            }
            if (strpos($k, '=') !== false || strpos($k, "\n") !== false) {
                throw new \InvalidArgumentException("cert payload key '{$k}' contains a forbidden character");
            }
            if (strpos($v, "\n") !== false) {
                throw new \InvalidArgumentException("cert payload value for '{$k}' contains a newline");
            }
            $lines[] = $k . '=' . $v;
        }
        return implode("\n", $lines);
    }

    // ───────────────────────── Packaging ─────────────────────────

    /**
     * Build a MANIFEST.sha256 string for a directory. Lines: "<sha256>  <relative_path>\n"
     * sorted by path. Excludes MANIFEST/SIGNATURE themselves and the EXCLUDE list.
     */
    public function buildManifest(string $sourceDir): string
    {
        $sourceDir = rtrim(realpath($sourceDir) ?: $sourceDir, "/\\");
        if (!is_dir($sourceDir)) {
            throw new \InvalidArgumentException("Not a directory: {$sourceDir}");
        }
        $entries = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $abs = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($sourceDir))), '/');
            if ($this->isExcluded($rel)) continue;
            $entries[$rel] = hash_file('sha256', $abs);
        }
        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $rel => $h) {
            $lines[] = $h . '  ' . $rel;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Parse a MANIFEST.sha256 string into [relative_path => sha256_hex].
     */
    public function parseManifest(string $manifest): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $manifest) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^([0-9a-f]{64})\s{2,}(.+)$/i', $line, $m)) {
                throw new \RuntimeException("Bad MANIFEST line: {$line}");
            }
            $out[$m[2]] = strtolower($m[1]);
        }
        return $out;
    }

    /**
     * Sign a manifest (raw bytes) with an Ed25519 secret key.
     * Returns the SIGNATURE.json structure (associative array, ready to json_encode).
     *
     * @param string $manifestBytes  Raw MANIFEST.sha256 content.
     * @param string $secretKeyB64   Editor private key (base64, 64 bytes).
     * @param string $publisherId    Must match module.json publish.publisher_id.
     * @param array  $certChainB64   Editor cert chain (base64 DER or whatever your CA emits).
     *                               Innermost = editor cert at index 0.
     */
    public function signManifest(string $manifestBytes, string $secretKeyB64, string $publisherId, array $certChainB64 = []): array
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Invalid Ed25519 secret key');
        }
        $manifestHash = hash('sha256', $manifestBytes, true);
        $sig          = sodium_crypto_sign_detached($manifestHash, $sk);

        return [
            'alg'                 => self::SIG_ALG,
            'publisher_id'        => $publisherId,
            'manifest_sha256'     => bin2hex($manifestHash),
            'signature'           => base64_encode($sig),
            'certificate_chain'   => $certChainB64,
            'signed_at'           => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Build a .fmod archive from a source directory.
     *
     * @param string $sourceDir       Module source (must contain module.json).
     * @param string $outPath         Target .fmod path.
     * @param string $secretKeyB64    Editor Ed25519 secret key (base64).
     * @param string $publisherId     Publisher identifier (must match manifest).
     * @param array  $certChainB64    Editor cert chain (base64).
     */
    public function buildPackage(string $sourceDir, string $outPath, string $secretKeyB64, string $publisherId, array $certChainB64 = []): void
    {
        $modulePath = $sourceDir . '/module.json';
        if (!is_file($modulePath)) {
            throw new \RuntimeException("module.json missing in {$sourceDir}");
        }
        $manifestStr = $this->buildManifest($sourceDir);
        $sigStruct   = $this->signManifest($manifestStr, $secretKeyB64, $publisherId, $certChainB64);
        $sigStr      = json_encode($sigStruct, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // Refuse to clobber arbitrary files: we only ever overwrite an existing .fmod.
        if (file_exists($outPath)) {
            if (!str_ends_with(strtolower($outPath), '.fmod')) {
                throw new \RuntimeException("Refusing to overwrite non-.fmod target: {$outPath}");
            }
            if (!@unlink($outPath)) {
                throw new \RuntimeException("Cannot remove existing target: {$outPath}");
            }
        }

        $zip = new \ZipArchive();
        $rc = $zip->open($outPath, \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($rc !== true) {
            throw new \RuntimeException("Cannot open {$outPath} for writing (rc=" . (int) $rc . ')');
        }

        $sourceDir = rtrim(realpath($sourceDir), "/\\");
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $abs = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($sourceDir))), '/');
            if ($this->isExcluded($rel)) continue;
            $zip->addFile($abs, $rel);
        }
        $zip->addFromString(self::MANIFEST_FILE, $manifestStr);
        $zip->addFromString(self::SIGNATURE_FILE, $sigStr);
        $zip->close();
    }

    // ───────────────────────── Verification ─────────────────────────

    /**
     * Verify a .fmod against the embedded Root CA.
     *
     * Returns an array: ['ok' => bool, 'errors' => string[], 'manifest' => array, 'signature' => array]
     *
     * @param string                 $fmodPath       Path to the .fmod archive.
     * @param array                  $coreVersion    Current core version (string) for min/max_core check.
     * @param callable|null          $isRevoked      fn(string $certFingerprintHex): bool
     * @param callable|null          $isYanked       fn(string $moduleKey, string $version): bool
     */
    public function verifyPackage(
        string $fmodPath,
        string $coreVersion,
        ?callable $isRevoked = null,
        ?callable $isYanked = null
    ): array {
        return $this->verifyInternal($fmodPath, $coreVersion, $isRevoked, $isYanked, null);
    }

    /**
     * Verify + extract in a single ZipArchive open to eliminate the verify→extract
     * TOCTOU window. Per-file SHA-256 is computed via a streaming hash (no full read
     * into memory) and the file is written to disk only after the digest matches.
     *
     * @return array Same shape as verifyPackage(). On success the package has been
     *               extracted into $targetDir; on failure $targetDir is removed.
     */
    public function verifyAndExtract(
        string $fmodPath,
        string $targetDir,
        string $coreVersion,
        ?callable $isRevoked = null,
        ?callable $isYanked = null
    ): array {
        if (file_exists($targetDir) && !$this->isEmptyDir($targetDir)) {
            return ['ok' => false, 'errors' => ["target dir not empty: {$targetDir}"]];
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
            return ['ok' => false, 'errors' => ["cannot mkdir {$targetDir}"]];
        }
        $res = $this->verifyInternal($fmodPath, $coreVersion, $isRevoked, $isYanked, $targetDir);
        if (!$res['ok']) {
            // Clean up any partial extraction on failure.
            $this->rrmdir($targetDir);
        }
        return $res;
    }

    /**
     * Single open of the .fmod archive; performs every check + optionally streams files to disk.
     */
    private function verifyInternal(
        string $fmodPath,
        string $coreVersion,
        ?callable $isRevoked,
        ?callable $isYanked,
        ?string $extractTo
    ): array {
        $errors = [];

        if (empty($this->rootKeys)) {
            return ['ok' => false, 'errors' => ['no Root CA configured — refusing to install']];
        }

        $zip = new \ZipArchive();
        $openRc = $zip->open($fmodPath);
        if ($openRc !== true) {
            return ['ok' => false, 'errors' => ['cannot open package (ZipArchive::open rc=' . (int) $openRc . ')']];
        }

        try {
            $manifestStr = $zip->getFromName(self::MANIFEST_FILE);
            $signatureJs = $zip->getFromName(self::SIGNATURE_FILE);
            if ($manifestStr === false || $signatureJs === false) {
                return ['ok' => false, 'errors' => ['MANIFEST or SIGNATURE missing']];
            }
            $signature = json_decode($signatureJs, true);
            if (!is_array($signature) || ($signature['alg'] ?? '') !== self::SIG_ALG) {
                return ['ok' => false, 'errors' => ['bad SIGNATURE algorithm']];
            }

            // 1) Editor cert chain must terminate at a configured Root CA.
            $chain = $signature['certificate_chain'] ?? [];
            if (!is_array($chain) || empty($chain)) {
                return ['ok' => false, 'errors' => ['empty certificate chain']];
            }
            $editorCert = $this->parseCert($chain[0]);
            if ($editorCert === null) {
                return ['ok' => false, 'errors' => ['cannot parse editor certificate']];
            }
            if (!$this->verifyCertChain($chain)) {
                $errors[] = 'certificate chain does not lead to a trusted Root CA';
            }

            // 2) Revocation check (cert fingerprint of the editor cert, strict).
            $editorFp = self::certFingerprint($chain[0]);
            if ($editorFp === null) {
                $errors[] = 'editor certificate is not valid base64';
            } elseif ($isRevoked !== null && $isRevoked($editorFp)) {
                $errors[] = 'editor certificate is revoked';
            }

            // 3) Verify Ed25519 signature over sha256(MANIFEST).
            $manifestHash      = hash('sha256', $manifestStr, true);
            $manifestHashClaim = @hex2bin((string) ($signature['manifest_sha256'] ?? ''));
            if (!is_string($manifestHashClaim) || strlen($manifestHashClaim) !== 32
                || !hash_equals($manifestHash, $manifestHashClaim)) {
                $errors[] = 'MANIFEST hash mismatch with SIGNATURE';
            }
            $sig = base64_decode($signature['signature'] ?? '', true);
            if (!is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
                $errors[] = 'malformed signature bytes';
            } elseif ($editorCert['public_key'] === null) {
                $errors[] = 'editor public key missing in cert';
            } elseif (!sodium_crypto_sign_verify_detached($sig, $manifestHash, $editorCert['public_key'])) {
                $errors[] = 'invalid Ed25519 signature';
            }

            // 4) Per-file SHA-256 against MANIFEST (streaming) + optional extraction.
            $manifestMap = $this->parseManifest($manifestStr);
            $targetReal  = $extractTo !== null ? realpath($extractTo) : null;
            if ($extractTo !== null && $targetReal === false) {
                return ['ok' => false, 'errors' => array_merge($errors, ["cannot resolve target dir: {$extractTo}"])];
            }
            $seen = [];
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if ($name === '' || substr($name, -1) === '/') continue;
                if ($name === self::MANIFEST_FILE || $name === self::SIGNATURE_FILE) continue;

                $expected = $manifestMap[$name] ?? null;
                if ($expected === null) {
                    $errors[] = "extra file not in MANIFEST: {$name}";
                    continue;
                }

                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    $errors[] = "cannot read {$name}";
                    continue;
                }

                // Streaming hash + optional write to a temporary file under target.
                $hashCtx = hash_init('sha256');
                $outFh   = null;
                $outPath = null;
                if ($extractTo !== null) {
                    if (!$this->isSafeArchiveName($name)) {
                        fclose($stream);
                        $errors[] = "unsafe entry name: {$name}";
                        continue;
                    }
                    $outPath = $this->resolveSafeTarget($targetReal, $name);
                    if ($outPath === null) {
                        fclose($stream);
                        $errors[] = "path traversal blocked: {$name}";
                        continue;
                    }
                    $parent = dirname($outPath);
                    if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
                        fclose($stream);
                        $errors[] = "cannot mkdir for {$name}";
                        continue;
                    }
                    // Write to a sibling tmp file first; commit only when hash matches.
                    $outPath = $outPath . '.part';
                    $outFh   = fopen($outPath, 'wb');
                    if (!is_resource($outFh)) {
                        fclose($stream);
                        $errors[] = "cannot open {$outPath} for writing";
                        continue;
                    }
                }

                while (!feof($stream)) {
                    $chunk = fread($stream, 64 * 1024);
                    if ($chunk === false) break;
                    if ($chunk === '') break;
                    hash_update($hashCtx, $chunk);
                    if ($outFh !== null) {
                        if (fwrite($outFh, $chunk) === false) {
                            $errors[] = "write error for {$name}";
                            break;
                        }
                    }
                }
                fclose($stream);
                $actual = hash_final($hashCtx);
                if ($outFh !== null) fclose($outFh);

                if (!hash_equals($expected, $actual)) {
                    if ($outFh !== null && $outPath !== null) @unlink($outPath);
                    $errors[] = "integrity mismatch: {$name}";
                    continue;
                }

                if ($outFh !== null && $outPath !== null) {
                    // Atomic commit: drop the .part suffix once digest is confirmed.
                    $finalPath = substr($outPath, 0, -5);
                    if (!@rename($outPath, $finalPath)) {
                        @unlink($outPath);
                        $errors[] = "cannot finalize {$name}";
                        continue;
                    }
                }
                $seen[$name] = true;
            }
            if (count($seen) < count($manifestMap)) {
                $missing = array_diff(array_keys($manifestMap), array_keys($seen));
                $errors[] = 'manifest declares files missing from archive: ' . implode(', ', array_slice($missing, 0, 5));
            }

            // 5) module.json: publisher_id match + core compat.
            $moduleJson = $zip->getFromName('module.json');
            if ($moduleJson === false) {
                return ['ok' => false, 'errors' => array_merge($errors, ['module.json missing in package'])];
            }
            $manifest = json_decode($moduleJson, true);
            if (!is_array($manifest)) {
                return ['ok' => false, 'errors' => array_merge($errors, ['module.json is not valid JSON'])];
            }
            $declaredPub = $manifest['publish']['publisher_id'] ?? null;
            $sigPub      = $signature['publisher_id'] ?? null;
            $certPub     = $editorCert['publisher_id'] ?? null;
            // Strict: every layer must declare the publisher_id and they must all match.
            if (!is_string($declaredPub) || $declaredPub === ''
                || !is_string($sigPub)      || $sigPub !== $declaredPub
                || !is_string($certPub)     || $certPub !== $declaredPub) {
                $errors[] = 'publisher_id mismatch between manifest, signature and certificate';
            }

            $minCore = $manifest['publish']['min_core'] ?? null;
            $maxCore = $manifest['publish']['max_core'] ?? null;
            if (is_string($minCore) && $minCore !== '' && !$this->semverSatisfies($coreVersion, $minCore)) {
                $errors[] = "core {$coreVersion} below min_core {$minCore}";
            }
            if (is_string($maxCore) && $maxCore !== '' && !$this->semverSatisfies($coreVersion, $maxCore)) {
                $errors[] = "core {$coreVersion} above max_core {$maxCore}";
            }

            if ($isYanked !== null && !empty($manifest['key']) && !empty($manifest['version'])) {
                if ($isYanked($manifest['key'], $manifest['version'])) {
                    $errors[] = 'this version has been yanked';
                }
            }

            return [
                'ok'        => empty($errors),
                'errors'    => $errors,
                'manifest'  => $manifest,
                'signature' => $signature,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @deprecated Use verifyAndExtract() to remove the verify→extract TOCTOU window.
     * Retained for backward compatibility with code that pre-verified the package.
     */
    public function extract(string $fmodPath, string $targetDir): void
    {
        if (file_exists($targetDir) && !$this->isEmptyDir($targetDir)) {
            throw new \RuntimeException("target dir not empty: {$targetDir}");
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
            throw new \RuntimeException("cannot mkdir {$targetDir}");
        }
        $targetReal = realpath($targetDir);
        if ($targetReal === false) {
            throw new \RuntimeException("cannot resolve target dir: {$targetDir}");
        }
        $zip = new \ZipArchive();
        $openRc = $zip->open($fmodPath);
        if ($openRc !== true) {
            throw new \RuntimeException('cannot open package (rc=' . (int) $openRc . ')');
        }
        try {
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    throw new \RuntimeException("ZipArchive::getNameIndex({$i}) failed");
                }
                if ($name === self::MANIFEST_FILE || $name === self::SIGNATURE_FILE) continue;
                if (substr($name, -1) === '/') continue; // directory entries created lazily
                if (!$this->isSafeArchiveName($name)) {
                    throw new \RuntimeException("unsafe entry name: {$name}");
                }
                $dest = $this->resolveSafeTarget($targetReal, $name);
                if ($dest === null) {
                    throw new \RuntimeException("path traversal blocked: {$name}");
                }
                $parent = dirname($dest);
                if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
                    throw new \RuntimeException("cannot mkdir for {$name}");
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new \RuntimeException("cannot read {$name}");
                }
                $out = fopen($dest, 'wb');
                if (!is_resource($out)) {
                    fclose($stream);
                    throw new \RuntimeException("cannot write {$dest}");
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    // ───────────────────────── Helpers ─────────────────────────

    private function isExcluded(string $rel): bool
    {
        if ($rel === self::MANIFEST_FILE || $rel === self::SIGNATURE_FILE) return true;
        $parts = explode('/', $rel);
        foreach ($parts as $p) {
            if (in_array($p, self::EXCLUDE_NAMES, true)) return true;
        }
        return false;
    }

    /**
     * Minimal "cert" parser: a Fronote cert is a base64-encoded JSON document with the
     * keys { publisher_id, public_key_b64, issuer_fp, issued_at, expires_at, signature_b64 }.
     *
     * Returns null when the input is unusable (bad base64, bad JSON, missing fields).
     * The 'signed_payload' field is built via canonicalCertBytes() — the exact same
     * representation used by the issuer at signing time (sorted "k=v" lines).
     */
    private function parseCert(string $b64): ?array
    {
        $raw = base64_decode($b64, true);
        if (!is_string($raw) || $raw === '') return null;
        $j = json_decode($raw, true);
        if (!is_array($j)) return null;

        $pk = base64_decode((string) ($j['public_key_b64'] ?? ''), true);
        $sg = base64_decode((string) ($j['signature_b64']   ?? ''), true);

        try {
            $signedPayload = self::canonicalCertBytes($j);
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'publisher_id'   => isset($j['publisher_id'])  && is_string($j['publisher_id'])  && $j['publisher_id']  !== '' ? $j['publisher_id']  : null,
            'public_key'     => is_string($pk) && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $pk : null,
            'issuer_fp'      => isset($j['issuer_fp'])     && is_string($j['issuer_fp'])     && preg_match('/^[0-9a-f]{64}$/i', $j['issuer_fp']) ? strtolower($j['issuer_fp']) : null,
            'expires_at'     => isset($j['expires_at'])    && is_string($j['expires_at'])    ? $j['expires_at']    : null,
            'signature'      => is_string($sg) && strlen($sg) === SODIUM_CRYPTO_SIGN_BYTES ? $sg : null,
            'signed_payload' => $signedPayload,
        ];
    }

    /**
     * Reject names containing `..`, absolute prefixes, drive letters, NULs, or backslashes.
     * (Backslashes are not legal in ZIP entry names; some encoders use them — we forbid them.)
     */
    private function isSafeArchiveName(string $name): bool
    {
        if ($name === '') return false;
        if (strpos($name, "\0") !== false) return false;
        if (strpos($name, '\\') !== false) return false;
        if ($name[0] === '/') return false;
        // Windows drive letter prefix (e.g. C:foo)
        if (preg_match('#^[A-Za-z]:#', $name)) return false;
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') return false;
        }
        return true;
    }

    /**
     * Resolve a ZIP entry name to an absolute path under $targetReal and verify that
     * the resulting path stays strictly inside it (defeats traversal even via
     * normalized "a/b/../../etc"-like inputs that isSafeArchiveName accepts segment-wise).
     *
     * @param string $targetReal Absolute, realpath()-resolved target directory.
     * @param string $name       ZIP entry name (forward slashes).
     * @return string|null       Absolute destination path, or null if it would escape.
     */
    private function resolveSafeTarget(string $targetReal, string $name): ?string
    {
        $dest = $targetReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        // Normalize without requiring the path to exist (realpath fails on not-yet-written files).
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $dest) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        $absPrefix = ($dest !== '' && $dest[0] === DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        $normalized = $absPrefix . implode(DIRECTORY_SEPARATOR, $parts);
        // Final containment check.
        $normRoot = rtrim($targetReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($normalized . DIRECTORY_SEPARATOR, $normRoot, strlen($normRoot)) !== 0) {
            return null;
        }
        return $normalized;
    }

    /** Recursive rmdir used to clean up failed extractions. */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Walk the chain: each cert is signed by the next; the last must be signed by a configured Root CA.
     * Returns true iff the chain validates and is not expired.
     */
    private function verifyCertChain(array $chainB64): bool
    {
        $now = time();
        $certs = [];
        foreach ($chainB64 as $b) {
            $c = is_string($b) ? $this->parseCert($b) : null;
            if ($c === null) return false; // parseCert null = unusable cert anywhere in the chain
            $certs[] = $c;
        }
        foreach ($certs as $i => $cert) {
            if ($cert['public_key'] === null || $cert['signature'] === null || $cert['signed_payload'] === null) {
                return false;
            }
            if (!empty($cert['expires_at']) && strtotime((string) $cert['expires_at']) < $now) {
                return false;
            }
            $issuerFp = $cert['issuer_fp'] ?? null;
            if ($issuerFp === null) return false; // every cert must declare its issuer fingerprint
            $issuerPk = null;
            if (isset($certs[$i + 1])) {
                $issuerPk = $certs[$i + 1]['public_key'];
                if ($issuerPk === null) return false;
                $expectedFp = bin2hex(hash('sha256', $issuerPk, true));
                if (!hash_equals($expectedFp, $issuerFp)) return false;
            } else {
                // Last cert: issuer must be a configured Root CA.
                if (!isset($this->rootKeys[$issuerFp])) {
                    return false;
                }
                $rootPk = base64_decode($this->rootKeys[$issuerFp], true);
                if (!is_string($rootPk) || strlen($rootPk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    return false;
                }
                $issuerPk = $rootPk;
            }
            if (!sodium_crypto_sign_verify_detached($cert['signature'], $cert['signed_payload'], $issuerPk)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tiny semver satisfies — supports comparators "X", ">=X", ">X", "<=X", "<X", "X.Y.*".
     * For richer ranges, replace with a proper library later.
     */
    public function semverSatisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') return true;

        // Conjunctions: a whitespace-separated list must all be satisfied (e.g. ">=1.0 <2.0").
        if (preg_match('/\s/', $constraint)) {
            foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
                if ($part === '') continue;
                if (!$this->semverSatisfies($version, $part)) return false;
            }
            return true;
        }

        // Caret (^) — compatible with the leftmost non-zero element.
        // ^1.2.3 ≡ >=1.2.3 <2.0.0   ;   ^0.2.3 ≡ >=0.2.3 <0.3.0   ;   ^0.0.3 ≡ >=0.0.3 <0.0.4
        if (str_starts_with($constraint, '^')) {
            $target = substr($constraint, 1);
            $parts = array_pad(array_map('intval', explode('.', $target)), 3, 0);
            [$M, $m, $p] = $parts;
            if ($M > 0)      $upper = ($M + 1) . '.0.0';
            elseif ($m > 0)  $upper = "0." . ($m + 1) . ".0";
            else             $upper = "0.0." . ($p + 1);
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }

        // Tilde (~) — only patch (or only minor with two parts) is allowed to bump.
        // ~1.2.3 ≡ >=1.2.3 <1.3.0  ;   ~1.2 ≡ >=1.2 <1.3   ;   ~1 ≡ >=1 <2
        if (str_starts_with($constraint, '~')) {
            $target = substr($constraint, 1);
            $parts = explode('.', $target);
            if (count($parts) >= 3) {
                $upper = $parts[0] . '.' . ((int) $parts[1] + 1) . '.0';
            } elseif (count($parts) === 2) {
                $upper = $parts[0] . '.' . ((int) $parts[1] + 1);
            } else {
                $upper = (string) ((int) $parts[0] + 1);
            }
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }

        if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', $constraint, $m)) {
            $op = $m[1] ?: '=';
            $target = $m[2];
            if (str_ends_with($target, '.*')) {
                // Wildcards are tied to the equality semantics ("matches anything in that prefix").
                // Combining a wildcard with a comparator is ambiguous → reject.
                if ($op !== '=') return false;
                $prefix = substr($target, 0, -1); // e.g. "2."
                return str_starts_with($version, $prefix);
            }
            return version_compare($version, $target, $op);
        }
        return false;
    }

    private function isEmptyDir(string $dir): bool
    {
        if (!is_dir($dir)) return true;
        $h = opendir($dir);
        if (!$h) return false;
        while (($e = readdir($h)) !== false) {
            if ($e !== '.' && $e !== '..') { closedir($h); return false; }
        }
        closedir($h);
        return true;
    }
}
