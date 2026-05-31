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

```bash
# 1. On an air-gapped machine, generate the Root CA keypair:
php scripts/fmod_keygen.php fronote-root /secure/offline/keys/

# 2. Copy /secure/offline/keys/fronote-root.pub into THIS directory.
#    KEEP /secure/offline/keys/fronote-root.sk OFFLINE — it never leaves the medium.

# 3. Issue the registry intermediate cert (online key, can be rotated):
php scripts/fmod_keygen.php registry-intermediate
php scripts/fmod_cert.php config/marketplace/keys/registry-intermediate.pub \
                         /secure/offline/keys/fronote-root.sk \
                         fronote-intermediate 730 \
                         config/marketplace/certs/registry-intermediate.cert
```

⚠️ Until at least one `.pub` is present here, `MarketplaceService::installFromFmod()`
refuses every sideload — by design.

## Test PKI (development/staging)

Generate a test Root CA for dev instances:
```bash
bash scripts/pki/generate-test-ca.sh
# → places fronote-test-root.pub here automatically
```

Test modules require `ALLOW_TEST_MODULES=true` in `.env` in addition to the test Root CA.
The test Root CA is NOT distributed with production instances.
