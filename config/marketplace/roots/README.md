# Marketplace Root CA public keys

This directory holds the **public keys** of every Root CA trusted by this
Fronote installation for verifying `.fmod` packages.

Each file:

- has the extension `.pub`
- contains a **base64-encoded Ed25519 public key** (32 bytes once decoded), single line
- is **public** and meant to be committed

## How verification works

1. The client (`API\Services\FmodService`) loads every `*.pub` in this directory.
2. Each `.fmod` package ships a certificate chain. The chain is accepted only if its
   topmost certificate is signed by a Root CA whose fingerprint matches one of
   these keys.
3. The Root CA's **private key** never lives in the repository, the server, or
   anywhere online — it is kept on an offline medium (HSM or air-gapped storage)
   and only used during a planned signing ceremony.

## Provisioning the first Root CA

> The former `scripts/fmod_*.php` / `scripts/pki/*` CLI helpers were removed with the
> `scripts/` directory. The cryptographic primitives now live in `\API\Services\FmodService`
> (`generateKeypair()`, `canonicalCertBytes()`, `certFingerprint()`); run the ceremony from a
> short PHP snippet on the air-gapped machine.

```php
// 1. On an air-gapped machine, generate the Root CA keypair:
[$secretKeyB64, $publicKeyB64, $fingerprint] = \API\Services\FmodService::generateKeypair();
// Write $publicKeyB64 to /secure/offline/keys/fronote-root.pub, $secretKeyB64 to fronote-root.sk

// 2. Copy fronote-root.pub into THIS directory.
//    KEEP fronote-root.sk OFFLINE — it never leaves the medium.

// 3. Issue the registry intermediate cert (online key, can be rotated) by signing the
//    canonical cert bytes with the Root CA secret key:
[$intSk, $intPk, ] = \API\Services\FmodService::generateKeypair();
$cert  = ['subject' => 'fronote-intermediate', 'public_key' => $intPk, 'expires' => /* +730j */];
$bytes = \API\Services\FmodService::canonicalCertBytes($cert);
$cert['signature_b64'] = base64_encode(sodium_crypto_sign_detached($bytes, base64_decode($rootSk)));
// Persist $cert as config/marketplace/certs/registry-intermediate.cert (JSON)
```

⚠️ Until at least one `.pub` is present here, `MarketplaceService::installFromFmod()`
refuses every sideload — by design.

## Test PKI (development/staging)

Generate a test Root CA for dev instances the same way — `FmodService::generateKeypair()` from a
PHP snippet — and drop the resulting `fronote-test-root.pub` into this directory. (The old
`scripts/pki/generate-test-ca.sh` generator no longer ships.)

Test modules require `ALLOW_TEST_MODULES=true` in `.env` in addition to the test Root CA.
The test Root CA is NOT distributed with production instances.
