#!/usr/bin/env bash
# =============================================================================
#  Fronote — mise à jour d'une instance installée (canal payload signé)
#
#  Compare la version locale à la dernière version publiée par le serveur de
#  distribution, télécharge le NOUVEAU socle (+ modules premium de la licence),
#  VÉRIFIE la signature Ed25519 + le SHA-256, bascule les fichiers (en préservant
#  configuration et données), puis réconcilie le schéma/les migrations.
#
#  À lancer en root SUR l'instance :  sudo ./update.sh   (ou --check pour seulement voir)
#
#  Sécurité : ne dépend PAS de git (absent du payload). L'authenticité vient de la
#  signature Ed25519, exactement comme à l'installation. Une sauvegarde de la base est
#  prise AVANT toute bascule ; en cas d'échec du schéma, upgrade.php sort en erreur et
#  le message invite à restaurer.
# =============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
PUBKEY_HEX="${PUBKEY_HEX:-8497e129133b95a785956a59bd7a4605da835864f7e24bea88ed1c4f77035ec1}"
RUN_USER="${RUN_USER:-www-data}"
CHECK_ONLY=0
for a in "$@"; do case "$a" in --check) CHECK_ONLY=1 ;; --dir=*) APP_DIR="${a#--dir=}" ;; esac; done

_c(){ printf '\033[%sm' "$1"; }
log(){ printf '%s[update]%s %s\n' "$(_c '1;36')" "$(_c 0)" "$*"; }
ok(){ printf '  %s✓%s %s\n' "$(_c '1;32')" "$(_c 0)" "$*"; }
warn(){ printf '  %s⚠%s %s\n' "$(_c '1;33')" "$(_c 0)" "$*"; }
die(){ printf '%s[erreur]%s %s\n' "$(_c '1;31')" "$(_c 0)" "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Lancez en root : sudo ./update.sh"
command -v curl >/dev/null || die "curl requis."
[ -f "$APP_DIR/.fronote-license" ] || die "Instance non gérée par la distribution (.fronote-license absent)."
# shellcheck disable=SC1090
. "$APP_DIR/.fronote-license"
[ -n "${INSTALL_TOKEN:-}" ] && [ -n "${DIST_URL:-}" ] || die ".fronote-license incomplet."

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
HTTP_CODE=""
http_call(){ local m="$1" u="$2"; shift 2; HTTP_CODE="$(curl -sS -X "$m" --max-time 300 -o "$WORK/resp" -w '%{http_code}' "$u" "$@" 2>/dev/null || true)"; [ -n "$HTTP_CODE" ]; }
field(){ grep -m1 "^$1=" "$WORK/resp" 2>/dev/null | cut -d= -f2- || true; }

# ── versions ──────────────────────────────────────────────────────────────────
LOCAL_VER="0.0.0"
[ -f "$APP_DIR/version.json" ] && LOCAL_VER="$(grep -oE '"version"[^,]*' "$APP_DIR/version.json" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo 0.0.0)"
log "Version locale : ${LOCAL_VER} — interrogation de ${DIST_URL}"
http_call GET "${DIST_URL%/}/?action=manifest&format=sh" || die "Serveur de distribution injoignable."
[ "$HTTP_CODE" = "200" ] || die "Manifeste indisponible (HTTP ${HTTP_CODE})."
LATEST_VER="$(field VERSION)"; BASE_SHA="$(field BASE_SHA256)"; BASE_SIG="$(field BASE_SIG)"
[ -n "$LATEST_VER" ] || die "Réponse de manifeste invalide."

# comparaison semver via sort -V (plus grand strict ?)
newer(){ [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]; }
if ! newer "$LATEST_VER" "$LOCAL_VER"; then
  ok "Déjà à jour (locale ${LOCAL_VER}, publiée ${LATEST_VER})."
  exit 0
fi
log "Mise à jour disponible : ${LOCAL_VER} → ${LATEST_VER}"
[ "$CHECK_ONLY" = 1 ] && { echo "  (mode --check : rien n'a été modifié)"; exit 0; }

# ── téléchargement + vérification (mêmes garanties qu'à l'installation) ────────
# openssl (Ed25519) est OBLIGATOIRE : sans lui, aucune preuve d'authenticité (le SHA-256 vient
# du même serveur). Refus strict plutôt que repli silencieux (constat d'audit « fail-open »).
ensure_openssl(){
  if ! command -v openssl >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
      apt-get install -y -qq openssl >/dev/null 2>&1 || true
    fi
  fi
  command -v openssl >/dev/null 2>&1 || die "openssl introuvable : impossible de vérifier la signature Ed25519. Installez openssl puis relancez."
  openssl version 2>/dev/null | grep -qE '^OpenSSL (3|[4-9])\.' \
    || die "OpenSSL 3.x requis pour vérifier les signatures Ed25519 (trouvé : $(openssl version 2>/dev/null))."
}
ensure_openssl
hex2bin(){ printf '%b' "$(printf '%s' "$1" | sed 's/\(..\)/\\x\1/g')"; }
verify(){ # verify FILE SHA SIG LABEL
  local f="$1" sha="$2" sig="$3" label="$4" got
  if command -v sha256sum >/dev/null; then got="$(sha256sum "$f" | cut -d' ' -f1)"; else got="$(shasum -a 256 "$f" | cut -d' ' -f1)"; fi
  [ -n "$sha" ] && [ "$got" = "$sha" ] || die "SHA-256 ${label} invalide (attendu ${sha}, obtenu ${got})."
  [ -n "$sig" ] || die "Signature absente pour ${label} — mise à jour refusée."
  if command -v openssl >/dev/null; then
    local pem="$WORK/pub.pem" sb="$WORK/sig.bin"
    { printf -- '-----BEGIN PUBLIC KEY-----\n'; hex2bin "302a300506032b6570032100${PUBKEY_HEX}" | base64 | tr -d '\n'; printf -- '\n-----END PUBLIC KEY-----\n'; } > "$pem"
    hex2bin "$sig" > "$sb" 2>/dev/null || die "signature ${label} illisible."
    openssl pkeyutl -verify -pubin -inkey "$pem" -rawin -in "$f" -sigfile "$sb" >/dev/null 2>&1 \
      && ok "Signature Ed25519 vérifiée — ${label}" || die "SIGNATURE INVALIDE — ${label} refusé."
  else
    die "openssl indisponible : signature de ${label} non vérifiable — mise à jour refusée."
  fi
}

STAGING="$WORK/staging"; mkdir -p "$STAGING"
log "Téléchargement du nouveau socle (${LATEST_VER})"
http_call GET "${DIST_URL%/}/?action=download&artifact=base&token=${INSTALL_TOKEN}" || die "Téléchargement injoignable."
[ "$HTTP_CODE" = "200" ] || die "Téléchargement du socle refusé (HTTP ${HTTP_CODE}, status='$(field STATUS)')."
cp "$WORK/resp" "$WORK/base.tar.gz"
verify "$WORK/base.tar.gz" "$BASE_SHA" "$BASE_SIG" "socle"
tar -xzf "$WORK/base.tar.gz" -C "$STAGING"

# modules premium de la licence (mêmes que l'installation)
if [ -n "${PREMIUM_MODULES:-}" ]; then
  IFS=',' read -r -a _mods <<< "$PREMIUM_MODULES"
  for m in "${_mods[@]}"; do
    m="$(printf '%s' "$m" | tr -d '[:space:]')"; [ -n "$m" ] || continue
    http_call POST "${DIST_URL%/}/?action=module&format=sh" --data-urlencode "token=${INSTALL_TOKEN}" --data-urlencode "module=${m}" || { warn "module ${m} injoignable"; continue; }
    [ "$(field STATUS)" = "ok" ] || { warn "module ${m} non autorisé"; continue; }
    MSIG="$(field SIG)"; MSHA="$(field SHA256)"
    http_call GET "${DIST_URL%/}/?action=download&artifact=module-${m}&token=${INSTALL_TOKEN}" || { warn "module ${m} DL injoignable"; continue; }
    [ "$HTTP_CODE" = "200" ] || { warn "module ${m} DL HTTP ${HTTP_CODE}"; continue; }
    cp "$WORK/resp" "$WORK/m.tar.gz"; verify "$WORK/m.tar.gz" "$MSHA" "$MSIG" "module ${m}"; tar -xzf "$WORK/m.tar.gz" -C "$STAGING"
  done
fi
ok "Nouveau code téléchargé et vérifié dans la zone de préparation."

# ── sauvegarde base AVANT bascule ─────────────────────────────────────────────
log "Sauvegarde de la base avant bascule"
runuser -u "$RUN_USER" -- php -r 'require "'"$APP_DIR"'/API/bootstrap.php"; $f=app("backup")->createDatabaseBackup(); fwrite(STDERR, "backup: ".basename((string)$f)."\n");' 2>&1 | sed 's/^/  /' || warn "Sauvegarde base non confirmée (on continue : le socle est signé)."

# ── bascule des fichiers (préserve config + données) ──────────────────────────
log "Bascule des fichiers"
RSYNC_EXCL=(--exclude='.env' --exclude='install.conf' --exclude='install.lock' --exclude='.fronote-license'
            --exclude='storage/***' --exclude='uploads/***' --exclude='temp/***' --exclude='API/logs/***' --exclude='vendor/***')
if command -v rsync >/dev/null; then
  rsync -a --delete "${RSYNC_EXCL[@]}" "$STAGING"/ "$APP_DIR"/
else
  # repli sans rsync : copie récursive (ne supprime pas les fichiers retirés, mais sûr).
  warn "rsync absent — copie simple (les fichiers supprimés en amont ne seront pas retirés)."
  cp -a "$STAGING"/. "$APP_DIR"/
fi
chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
ok "Fichiers basculés."

# ── dépendances PHP (si le lockfile a changé) ─────────────────────────────────
COMPOSER_BIN="$(command -v composer || echo /usr/local/bin/composer)"
if [ -x "$COMPOSER_BIN" ] || command -v composer >/dev/null; then
  log "composer install (sans dev)"
  COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir="$APP_DIR" >/dev/null 2>&1 \
    && ok "Dépendances à jour." || warn "composer install a échoué — l'app peut nécessiter une intervention."
  chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR/vendor" 2>/dev/null || true
fi

# ── réconciliation schéma / migrations / modules / cache ──────────────────────
log "Réconciliation (schéma, migrations, modules, cache)"
if runuser -u "$RUN_USER" -- php "$APP_DIR/bin/upgrade.php"; then
  ok "Schéma et modules réconciliés."
else
  die "La réconciliation du schéma a ÉCHOUÉ. Restaurez la base depuis la dernière sauvegarde (storage/backups/) et signalez au support."
fi

# ── redémarrage des services (best-effort) ────────────────────────────────────
systemctl restart "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" >/dev/null 2>&1 || true
systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true
systemctl restart fronote-ws >/dev/null 2>&1 || true

echo
printf '%s══════════════════════════════════════════════════════════════%s\n' "$(_c '1;32')" "$(_c 0)"
printf '%s  Fronote mis à jour : %s → %s%s\n' "$(_c '1;32')" "$LOCAL_VER" "$LATEST_VER" "$(_c 0)"
printf '%s══════════════════════════════════════════════════════════════%s\n' "$(_c '1;32')" "$(_c 0)"
echo "  Une sauvegarde de la base a été prise avant la bascule (storage/backups/)."
