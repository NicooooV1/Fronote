#!/usr/bin/env bash
# =============================================================================
#  Fronote — bootstrapper d'installation sous licence
#
#  C'est LE fichier à remettre au client (avec sa clé de licence à usage unique).
#  Il : demande la clé → l'échange auprès du serveur de distribution → télécharge le
#  socle (base + modules simples) → VÉRIFIE la signature Ed25519 + le SHA-256 →
#  l'extrait → lance l'installateur complet embarqué (install.sh).
#
#  Usage :
#     sudo ./bootstrap.sh                          # demande la clé, puis installe
#     sudo FRONOTE_KEY=<48car> ./bootstrap.sh --yes APP_URL=https://ecole.fr ADMIN_EMAIL=a@ecole.fr
#     sudo ./bootstrap.sh --dir=/var/www/fronote --key=<48car>
#
#  Tout argument non reconnu par le bootstrapper est TRANSMIS à install.sh
#  (ex: --yes, --no-websocket, APP_URL=…, ADMIN_EMAIL=…).
# =============================================================================
set -euo pipefail

# ── À PERSONNALISER par le distributeur (valeurs injectées à la livraison) ────
DIST_URL="${DIST_URL:-https://dist.exemple.fr}"        # URL du serveur de distribution (HTTPS)
PUBKEY_HEX="${PUBKEY_HEX:-REMPLACER_PAR_LA_CLE_PUBLIQUE_HEX}"  # sortie de: php dist/bin/init.php
# ──────────────────────────────────────────────────────────────────────────────

TARGET_DIR="/var/www/fronote"
KEY="${FRONOTE_KEY:-}"
PASSTHRU=()

_c(){ printf '\033[%sm' "$1"; }
log(){ printf '%s[fronote]%s %s\n' "$(_c '1;36')" "$(_c 0)" "$*"; }
ok(){ printf '  %s✓%s %s\n' "$(_c '1;32')" "$(_c 0)" "$*"; }
die(){ printf '%s[erreur]%s %s\n' "$(_c '1;31')" "$(_c 0)" "$*" >&2; exit 1; }

for a in "$@"; do
  case "$a" in
    --key=*) KEY="${a#--key=}" ;;
    --dir=*) TARGET_DIR="${a#--dir=}" ;;
    --dist-url=*) DIST_URL="${a#--dist-url=}" ;;
    *) PASSTHRU+=("$a") ;;   # transmis à install.sh
  esac
done

[ "$(id -u)" -eq 0 ] || die "Lancez en root : sudo ./bootstrap.sh"
command -v curl >/dev/null || die "curl est requis."
command -v tar  >/dev/null || die "tar est requis."
[ "$PUBKEY_HEX" != "REMPLACER_PAR_LA_CLE_PUBLIQUE_HEX" ] || die "Bootstrapper non configuré (PUBKEY_HEX manquant)."
case "$DIST_URL" in https://*) : ;; *) log "AVERTISSEMENT : DIST_URL n'est pas en https:// — la clé transitera en clair." ;; esac

# ── clé de licence ────────────────────────────────────────────────────────────
if [ -z "$KEY" ]; then
  read -r -p "  Clé de licence (48 caractères) : " KEY || true
fi
[ -n "$KEY" ] || die "Clé de licence requise."

# ── redemption ────────────────────────────────────────────────────────────────
log "Validation de la licence auprès de ${DIST_URL}"
RESP="$(curl -fsS --max-time 60 -X POST "${DIST_URL%/}/?action=redeem&format=sh" --data-urlencode "key=${KEY}" 2>/dev/null || true)"
[ -n "$RESP" ] || die "Aucune réponse du serveur de distribution (réseau ? URL ?)."

field(){ printf '%s\n' "$RESP" | grep -m1 "^$1=" | cut -d= -f2- || true; }
STATUS="$(field STATUS)"
case "$STATUS" in
  ok) : ;;
  not_whitelisted) die "$(field MESSAGE) [IP non autorisée]" ;;
  invalid)   die "Clé invalide." ;;
  exhausted) die "Cette clé a déjà été utilisée (usage unique)." ;;
  revoked)   die "Licence révoquée." ;;
  expired)   die "Licence expirée." ;;
  *)         die "Réponse inattendue du serveur (status='${STATUS:-?}')." ;;
esac

INSTALL_TOKEN="$(field INSTALL_TOKEN)"
BASE_URL="$(field BASE_URL)"
BASE_SIG="$(field BASE_SIG)"
BASE_SHA="$(field BASE_SHA256)"
MODULES="$(field MODULES)"
[ -n "$INSTALL_TOKEN" ] && [ -n "$BASE_URL" ] || die "Réponse de licence incomplète."
ok "Licence valide — modules : ${MODULES}"

# ── téléchargement du socle ───────────────────────────────────────────────────
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
ARCHIVE="$WORK/base.tar.gz"
log "Téléchargement du socle"
# On télécharge depuis NOTRE DIST_URL (pas une URL dictée par le serveur) : la source reste
# celle validée par le client. La signature/le SHA-256 renvoyés authentifient le contenu.
DL_URL="${DIST_URL%/}/?action=download&artifact=base&token=${INSTALL_TOKEN}"
curl -fsS --max-time 600 "$DL_URL" -o "$ARCHIVE" || die "Échec du téléchargement du socle."

# ── vérification d'intégrité (SHA-256, obligatoire) ───────────────────────────
if command -v sha256sum >/dev/null; then GOT="$(sha256sum "$ARCHIVE" | cut -d' ' -f1)"; else GOT="$(shasum -a 256 "$ARCHIVE" | cut -d' ' -f1)"; fi
[ -n "$BASE_SHA" ] && [ "$GOT" = "$BASE_SHA" ] || die "SHA-256 du socle invalide (téléchargement corrompu ou altéré). Attendu ${BASE_SHA}, obtenu ${GOT}."
ok "Intégrité SHA-256 vérifiée"

# ── vérification d'authenticité (signature Ed25519) ───────────────────────────
# Décodage hex → binaire en bash pur (sans dépendance à xxd).
hex2bin(){ printf '%b' "$(printf '%s' "$1" | sed 's/\(..\)/\\x\1/g')"; }
verify_ed25519(){
  command -v openssl >/dev/null || return 2
  local pem="$WORK/pub.pem" sig="$WORK/base.sig.bin"
  # PEM Ed25519 = préfixe DER fixe (302a300506032b6570032100) + clé brute 32 octets.
  {
    printf -- '-----BEGIN PUBLIC KEY-----\n'
    hex2bin "302a300506032b6570032100${PUBKEY_HEX}" | base64 | tr -d '\n'
    printf -- '\n-----END PUBLIC KEY-----\n'
  } > "$pem"
  hex2bin "$BASE_SIG" > "$sig" 2>/dev/null || return 2
  local out
  out="$(openssl pkeyutl -verify -pubin -inkey "$pem" -rawin -in "$ARCHIVE" -sigfile "$sig" 2>&1)" && return 0
  # Échec : distinguer signature invalide (ABANDON) d'un openssl trop ancien (repli).
  printf '%s' "$out" | grep -qi "Signature Verification Failure" && return 1
  return 2
}
set +e; verify_ed25519; VR=$?; set -e
case "$VR" in
  0) ok "Signature Ed25519 vérifiée (authenticité confirmée)" ;;
  1) die "SIGNATURE INVALIDE — le socle a été ALTÉRÉ. Installation refusée." ;;
  2) log "AVERTISSEMENT : vérification Ed25519 indisponible (openssl/xxd) — repli sur SHA-256 + HTTPS." ;;
esac

# ── extraction + lancement de l'installateur complet ──────────────────────────
log "Installation dans ${TARGET_DIR}"
mkdir -p "$TARGET_DIR"
tar -xzf "$ARCHIVE" -C "$TARGET_DIR"
# Mémorise le jeton d'installation + l'URL de distribution pour les modules ultérieurs.
umask 077
cat > "$TARGET_DIR/.fronote-license" <<EOF
INSTALL_TOKEN=${INSTALL_TOKEN}
DIST_URL=${DIST_URL%/}
MODULES=${MODULES}
EOF
chmod 600 "$TARGET_DIR/.fronote-license"
ok "Socle extrait ; jeton d'installation enregistré (.fronote-license)"

[ -f "$TARGET_DIR/install.sh" ] || die "install.sh introuvable dans le socle — payload incomplet."
chmod +x "$TARGET_DIR/install.sh" 2>/dev/null || true
log "Lancement de l'installateur complet (OS, base, provisioning)…"
cd "$TARGET_DIR"
exec ./install.sh "${PASSTHRU[@]}"
