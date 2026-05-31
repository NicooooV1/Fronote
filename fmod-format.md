# Fronote Module Format — `.fmod` Specification v1

> Plateforme **3.2.4** · Module marketplace **1.5.2** · Format **.fmod v1** · 2026-05-31

## Overview

A `.fmod` file is a ZIP archive with a deterministic structure and a detached Ed25519 signature.
The `.fmod` extension is opaque: any ZIP tool can inspect the contents.

## Archive Structure

```
{module_key}-{version}.fmod   (ZIP)
├── MANIFEST.sha256            ← Per-file SHA-256 manifest (signed)
├── SIGNATURE.json             ← Detached Ed25519 signature + certificate chain
├── module.json                ← Module metadata
├── <module source files>      ← PHP, SQL, assets, etc.
└── ...
```

## MANIFEST.sha256

Line-per-file format, sorted by path:

```
<sha256_hex>  <relative_path>
```

Example:
```
a1b2c3...  Database/install.sql
d4e5f6...  Services/MyService.php
7g8h9i...  module.json
```

MANIFEST.sha256 and SIGNATURE.json are excluded from the manifest (they are meta-files).

## SIGNATURE.json

```json
{
  "alg": "Ed25519",
  "publisher_id": "fronote-team",
  "manifest_sha256": "<sha256-hex-of-MANIFEST.sha256>",
  "signature": "<base64-encoded-64-byte-Ed25519-signature>",
  "certificate_chain": ["<base64-cert-editor>", "<base64-cert-intermediate>"],
  "signed_at": "2026-01-15T10:00:00Z"
}
```

The signature covers `sha256(MANIFEST.sha256_content)` — the hash of the hash file.

## module.json (mandatory fields)

```json
{
  "key": "my_module",
  "version": "1.0.0",
  "name": { "fr": "...", "en": "..." },
  "icon": "fas fa-...",
  "category": "scolaire",
  "core": false,
  "channel": "stable",
  "test_only": false,
  "publish": {
    "publisher_id": "my-publisher",
    "required_permissions": ["db_read"],
    "optional_permissions": []
  }
}
```

`test_only: true` → module can only be installed when `ALLOW_TEST_MODULES=true` in `.env`.

## Certificate Format

Each certificate in `certificate_chain` is a **base64-encoded JSON document**:

```json
{
  "publisher_id": "fronote-team",
  "public_key_b64": "<base64-Ed25519-public-key-32-bytes>",
  "issuer_fp": "<sha256-hex-of-issuer-public-key>",
  "issued_at": "2026-01-01T00:00:00Z",
  "expires_at": "2027-01-01T00:00:00Z",
  "signature_b64": "<base64-Ed25519-signature-by-issuer>"
}
```

The signature covers the canonical form of all fields except `signature_b64`,
sorted alphabetically as `key=value` lines joined by `\n`.

## Trust Chain

```
Root CA (config/marketplace/roots/*.pub)
  └── Intermediate CA cert (in certificate_chain[1])
        └── Publisher cert (in certificate_chain[0])
              └── Signs MANIFEST.sha256
```

Root CA public keys are 32-byte Ed25519 keys stored in base64, one per `.pub` file.

## Verification Pipeline (install)

1. Verify ZIP is valid and ≤ 50 MB
2. Check MANIFEST.sha256 and SIGNATURE.json are present
3. Parse SIGNATURE.json; verify `alg = Ed25519`
4. Verify certificate chain terminates at a configured Root CA
5. Check certificate not revoked (CRL lookup)
6. Verify Ed25519 signature: `sign_verify(sig, sha256(MANIFEST), editor_pk)`
7. Verify per-file SHA-256 against MANIFEST for every file
8. Verify `publisher_id` matches across manifest, signature, and certificate
9. Check `test_only` vs `ALLOW_TEST_MODULES` env
10. Run ModuleScanner on extracted code
11. Request consent for `permissions_requested` (if any)
12. Atomic deploy: `rename(staging, modules/{key}/)`
13. Run `install.sql` via ModuleSDK
14. Record in `marketplace_installed`

## Creating a .fmod

Use `FmodService::buildPackage()` (PHP) or the `fmod-pack` CLI tool:

```bash
# PHP
$fmod = new \API\Services\FmodService([]);
$fmod->buildPackage('./my-module', './my-module-1.0.0.fmod', $secretKeyB64, 'my-publisher', [$certB64]);

# CLI (when available)
fmod-pack sign --module-dir ./my-module --publisher-key publisher.key \
  --publisher-cert publisher.crt --channel stable --output my-module-1.0.0.fmod
```

## Dev/Test Workflow

```bash
# 1. Generate test PKI
bash scripts/pki/generate-test-ca.sh

# 2. Build test .fmod (PHP)
php -r "
  require 'API/bootstrap.php';
  \$f = new \API\Services\FmodService([]);
  \$f->buildPackage('./modules/hello_world', '/tmp/hello_world-1.0.0.fmod',
    trim(file_get_contents('pki-test/fmod-secret.key')), 'fronote-team',
    [base64_encode(file_get_contents('pki-test/test-intermediate.crt'))]);
  echo 'Built OK\n';
"

# 3. Install
php scripts/install-module.php /tmp/hello_world-1.0.0.fmod --allow-test
```

## Security Properties

| Property | Guaranteed |
|----------|-----------|
| Integrity (no tampering) | ✓ Per-file SHA-256 + manifest signature |
| Authenticity (origin) | ✓ Certificate chain → Root CA |
| Non-repudiation | ✓ Ed25519 is deterministic |
| Freshness | Partial (signed_at field; no expiry on packages) |
| Revocation | ✓ CRL via marketplace_revocations DB table |
| Safe extraction | ✓ Path traversal blocked; atomic commit per file |

Ed25519 guarantees: integrity, authenticity, non-repudiation.
It does NOT guarantee: code quality, absence of malicious logic, RGPD compliance.
`ModuleScanner` provides a complementary static analysis layer.
