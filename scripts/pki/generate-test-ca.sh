#!/bin/bash
# Infrastructure PKI de test pour Fronote Marketplace
# Génère : Root CA de test, Intermediate CA, certificat éditeur fronote-team
# Usage : ./scripts/pki/generate-test-ca.sh [output_dir]
# Prérequis : OpenSSL 3.x, PHP 8.0+ avec ext-sodium

set -euo pipefail

OUT="${1:-./pki-test}"
mkdir -p "$OUT"

echo "=== Fronote Test PKI Generator ==="
echo "Output: $OUT"
echo ""

# ── Root CA de test ──────────────────────────────────────────────────────
echo "[1/4] Generating Test Root CA..."
openssl genpkey -algorithm ed25519 -out "$OUT/test-root-ca.key" 2>/dev/null
openssl req -new -x509 -key "$OUT/test-root-ca.key" \
  -out "$OUT/test-root-ca.crt" -days 730 \
  -subj "/CN=Fronote Test Root CA/O=Fronote Dev/C=FR" 2>/dev/null

# Extraire la clé publique Ed25519 brute (32 bytes) en base64 → fronote-test-root.pub
# La clé publique DER a un préfixe de 12 bytes d'OID ; on prend les 32 derniers bytes.
openssl pkey -in "$OUT/test-root-ca.key" -pubout -outform DER 2>/dev/null \
  | tail -c 32 | base64 > "$OUT/fronote-test-root.pub"

echo "    Root CA fingerprint:"
php -r "
  \$pk = base64_decode(trim(file_get_contents('$OUT/fronote-test-root.pub')));
  echo '    ' . bin2hex(hash('sha256', \$pk, true)) . PHP_EOL;
"

# ── Intermediate CA ──────────────────────────────────────────────────────
echo "[2/4] Generating Intermediate CA..."
openssl genpkey -algorithm ed25519 -out "$OUT/test-intermediate.key" 2>/dev/null
openssl req -new -key "$OUT/test-intermediate.key" \
  -out "$OUT/test-intermediate.csr" \
  -subj "/CN=Fronote Test Intermediate CA/O=Fronote Dev/C=FR" 2>/dev/null
openssl x509 -req -in "$OUT/test-intermediate.csr" \
  -CA "$OUT/test-root-ca.crt" -CAkey "$OUT/test-root-ca.key" \
  -CAcreateserial -out "$OUT/test-intermediate.crt" -days 365 2>/dev/null

# ── Certificat éditeur fronote-team ─────────────────────────────────────
echo "[3/4] Generating publisher certificate (fronote-team)..."
openssl genpkey -algorithm ed25519 -out "$OUT/publisher.key" 2>/dev/null
openssl req -new -key "$OUT/publisher.key" \
  -out "$OUT/publisher.csr" \
  -subj "/CN=fronote-team/O=Fronote Team/C=FR" 2>/dev/null
openssl x509 -req -in "$OUT/publisher.csr" \
  -CA "$OUT/test-intermediate.crt" -CAkey "$OUT/test-intermediate.key" \
  -CAcreateserial -out "$OUT/publisher.crt" -days 180 2>/dev/null

# ── Générer le keypair PHP (Fronote format = Ed25519 via libsodium) ──────
echo "[4/4] Generating PHP/libsodium keypair for FmodService..."
php -r "
  \$kp = sodium_crypto_sign_keypair();
  \$sk = base64_encode(sodium_crypto_sign_secretkey(\$kp));
  \$pk = base64_encode(sodium_crypto_sign_publickey(\$kp));
  \$fp = bin2hex(hash('sha256', sodium_crypto_sign_publickey(\$kp), true));
  file_put_contents('$OUT/fmod-secret.key', \$sk);
  file_put_contents('$OUT/fmod-public.key', \$pk);
  echo '    Fronote keypair fingerprint: ' . \$fp . PHP_EOL;
  echo '    Secret key (KEEP SAFE): $OUT/fmod-secret.key' . PHP_EOL;
  echo '    Public key:             $OUT/fmod-public.key' . PHP_EOL;
"

# ── Copier fronote-test-root.pub vers config/ ────────────────────────────
ROOTS_DIR="./config/marketplace/roots"
mkdir -p "$ROOTS_DIR"
cp "$OUT/fronote-test-root.pub" "$ROOTS_DIR/fronote-test-root.pub"
echo ""
echo "=== Done ==="
echo "Root CA public key → $ROOTS_DIR/fronote-test-root.pub"
echo "Use fmod-public.key as the Root CA for FmodService on dev instances."
echo ""
echo "SECURITY: Never commit *.key files. Add pki-test/ to .gitignore."
