#!/usr/bin/env bash
# =============================================================================
#  Fronote — bootstrapper d'installation sous licence
#
#  C'est LE fichier à remettre au client (avec sa clé de licence à usage unique).
#  Il : demande la clé → l'échange auprès du serveur de distribution → télécharge le
#  socle (base + modules simples + core) → VÉRIFIE la signature Ed25519 + le SHA-256 →
#  télécharge de la même façon les modules PREMIUM de la licence → extrait le tout →
#  lance l'installateur complet embarqué (install.sh).
#
#  Usage :
#     sudo ./bootstrap.sh                          # demande la clé, puis installe
#     sudo FRONOTE_KEY=<48car> ./bootstrap.sh --yes APP_URL=https://ecole.fr ADMIN_EMAIL=a@ecole.fr
#     sudo ./bootstrap.sh --dir=/var/www/fronote --key=<48car>
#     sudo ./bootstrap.sh --resume                 # reprend après un échec (réutilise le jeton)
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
RESUME=0
PASSTHRU=()

_c(){ printf '\033[%sm' "$1"; }
log(){ printf '%s[fronote]%s %s\n' "$(_c '1;36')" "$(_c 0)" "$*"; }
ok(){ printf '  %s✓%s %s\n' "$(_c '1;32')" "$(_c 0)" "$*"; }
warn(){ printf '  %s⚠%s %s\n' "$(_c '1;33')" "$(_c 0)" "$*"; }
die(){ printf '%s[erreur]%s %s\n' "$(_c '1;31')" "$(_c 0)" "$*" >&2; exit 1; }

for a in "$@"; do
  case "$a" in
    --key=*) KEY="${a#--key=}" ;;
    --dir=*) TARGET_DIR="${a#--dir=}" ;;
    --dist-url=*) DIST_URL="${a#--dist-url=}" ;;
    --resume) RESUME=1 ;;
    *) PASSTHRU+=("$a") ;;   # transmis à install.sh
  esac
done

[ "$(id -u)" -eq 0 ] || die "Lancez en root : sudo ./bootstrap.sh"
command -v curl >/dev/null || die "curl est requis."
command -v tar  >/dev/null || die "tar est requis."
[ "$PUBKEY_HEX" != "REMPLACER_PAR_LA_CLE_PUBLIQUE_HEX" ] || die "Bootstrapper non configuré (PUBKEY_HEX manquant)."
case "$DIST_URL" in https://*) : ;; *) warn "DIST_URL n'est pas en https:// — la clé transitera en clair." ;; esac

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

# ── appel HTTP qui NE MASQUE PAS le corps des réponses non-2xx ─────────────────
# Le serveur signale les refus par un code HTTP non-2xx (403/401/409/410) AVEC un corps
# STATUS=/MESSAGE= exploitable. `curl -f` jetait ce corps → le client voyait toujours
# « Aucune réponse du serveur » et tout le routage de refus était du code mort (constat
# test e2e 2026-08-12). On capture donc SÉPARÉMENT le code HTTP et le corps.
HTTP_CODE=""
http_call(){ # http_call METHOD URL [--data-urlencode k=v ...] ; corps -> $WORK/resp, code -> $HTTP_CODE
  local method="$1" url="$2"; shift 2
  HTTP_CODE="$(curl -sS -X "$method" --max-time 120 -o "$WORK/resp" -w '%{http_code}' "$url" "$@" 2>"$WORK/curlerr" || true)"
  [ -n "$HTTP_CODE" ] || { warn "curl: $(tr -d '\n' <"$WORK/curlerr" 2>/dev/null)"; return 1; }
  return 0
}
field(){ grep -m1 "^$1=" "$WORK/resp" 2>/dev/null | cut -d= -f2- || true; }

# ── vérification d'intégrité + authenticité d'une archive ─────────────────────
hex2bin(){ printf '%b' "$(printf '%s' "$1" | sed 's/\(..\)/\\x\1/g')"; }
verify_artifact(){ # verify_artifact FILE SHA_HEX SIG_HEX LABEL
  local file="$1" sha="$2" sig="$3" label="$4" got
  if command -v sha256sum >/dev/null; then got="$(sha256sum "$file" | cut -d' ' -f1)"; else got="$(shasum -a 256 "$file" | cut -d' ' -f1)"; fi
  [ -n "$sha" ] && [ "$got" = "$sha" ] || die "SHA-256 de ${label} invalide (corrompu ou altéré). Attendu ${sha}, obtenu ${got}."
  # Signature Ed25519 (openssl). PEM = préfixe DER fixe + clé brute 32 octets.
  if command -v openssl >/dev/null; then
    local pem="$WORK/pub.pem" sigbin="$WORK/sig.bin" out
    {
      printf -- '-----BEGIN PUBLIC KEY-----\n'
      hex2bin "302a300506032b6570032100${PUBKEY_HEX}" | base64 | tr -d '\n'
      printf -- '\n-----END PUBLIC KEY-----\n'
    } > "$pem"
    if hex2bin "$sig" > "$sigbin" 2>/dev/null; then
      if out="$(openssl pkeyutl -verify -pubin -inkey "$pem" -rawin -in "$file" -sigfile "$sigbin" 2>&1)"; then
        ok "Signature Ed25519 vérifiée — ${label}"
        return 0
      fi
      printf '%s' "$out" | grep -qi "Signature Verification Failure" \
        && die "SIGNATURE INVALIDE — ${label} a été ALTÉRÉ. Installation refusée."
    fi
  fi
  warn "Vérification Ed25519 indisponible (openssl) — repli SHA-256 + HTTPS pour ${label}."
}

# ── reprise : réutilise le jeton d'une tentative précédente ───────────────────
# La clé est consommée au redeem. Si l'installation a échoué APRÈS (réseau, install.sh),
# relancer redeem donnerait « déjà utilisée ». --resume (ou détection auto) réutilise le
# jeton stocké dans .fronote-license : le serveur autorise le re-téléchargement.
if [ "$RESUME" = 1 ] && [ -f "$TARGET_DIR/.fronote-license" ]; then
  # shellcheck disable=SC1090
  . "$TARGET_DIR/.fronote-license"
  [ -n "${INSTALL_TOKEN:-}" ] || die "--resume : aucun jeton dans ${TARGET_DIR}/.fronote-license."
  log "Reprise : réutilisation du jeton d'installation existant"
else
  # ── clé de licence ──────────────────────────────────────────────────────────
  if [ -z "$KEY" ]; then read -r -p "  Clé de licence (48 caractères) : " KEY || true; fi
  [ -n "$KEY" ] || die "Clé de licence requise."

  # ── redemption ──────────────────────────────────────────────────────────────
  log "Validation de la licence auprès de ${DIST_URL}"
  http_call POST "${DIST_URL%/}/?action=redeem&format=sh" --data-urlencode "key=${KEY}" \
    || die "Serveur de distribution injoignable (réseau ? URL ?)."
  STATUS="$(field STATUS)"
  case "$STATUS" in
    ok) : ;;
    not_whitelisted) die "$(field MESSAGE) [IP non autorisée — HTTP ${HTTP_CODE}]" ;;
    invalid)   die "Clé invalide. [HTTP ${HTTP_CODE}]" ;;
    exhausted) die "Cette clé a déjà été utilisée (usage unique). Si une install a échoué avant, relancez avec --resume. [HTTP ${HTTP_CODE}]" ;;
    revoked)   die "Licence révoquée. [HTTP ${HTTP_CODE}]" ;;
    expired)   die "Licence expirée. [HTTP ${HTTP_CODE}]" ;;
    *)         die "Réponse inattendue du serveur (HTTP ${HTTP_CODE}, status='${STATUS:-?}'). Corps : $(tr '\n' ' ' <"$WORK/resp" | cut -c1-200)" ;;
  esac
  INSTALL_TOKEN="$(field INSTALL_TOKEN)"
  BASE_SIG="$(field BASE_SIG)"
  BASE_SHA="$(field BASE_SHA256)"
  MODULES="$(field MODULES)"
  PREMIUM_MODULES="$(field PREMIUM_MODULES)"
  [ -n "$INSTALL_TOKEN" ] || die "Réponse de licence incomplète (jeton manquant)."
  ok "Licence valide — modules : ${MODULES:-?}"
fi

# ── téléchargement + vérification du socle ────────────────────────────────────
ARCHIVE="$WORK/base.tar.gz"
log "Téléchargement du socle"
http_call GET "${DIST_URL%/}/?action=download&artifact=base&token=${INSTALL_TOKEN}" \
  || die "Serveur injoignable pendant le téléchargement du socle."
[ "$HTTP_CODE" = "200" ] || die "Échec du téléchargement du socle (HTTP ${HTTP_CODE}, status='$(field STATUS)')."
cp "$WORK/resp" "$ARCHIVE"
# En mode --resume on n'a plus le SHA/sig renvoyés au redeem : on récupère le SHA-256
# courant via /manifest (servi en HTTPS). La signature Ed25519 du socle n'a pas de route
# dédiée → en reprise seule, on se rabat sur SHA-256 + HTTPS pour le socle (Ed25519 reste
# appliqué aux modules premium, dont la signature vient de /module). Le cas nominal (avec
# clé) garde la vérification Ed25519 complète du socle.
if [ -z "${BASE_SHA:-}" ]; then
  http_call GET "${DIST_URL%/}/?action=manifest&format=sh" && BASE_SHA="$(field BASE_SHA256)" || true
fi
verify_artifact "$ARCHIVE" "${BASE_SHA:-}" "${BASE_SIG:-}" "socle"

# ── extraction du socle ───────────────────────────────────────────────────────
log "Installation dans ${TARGET_DIR}"
mkdir -p "$TARGET_DIR"
tar -xzf "$ARCHIVE" -C "$TARGET_DIR"

# ── modules PREMIUM de la licence (téléchargés + vérifiés comme le socle) ──────
# Correctif critique 2026-08-18 : AUCUN script n'allait chercher les modules premium
# vendus dans la licence — ils n'arrivaient jamais chez le client (invendables). On boucle
# ici sur la liste PREMIUM_MODULES renvoyée au redeem.
if [ -n "${PREMIUM_MODULES:-}" ]; then
  log "Téléchargement des modules premium : ${PREMIUM_MODULES}"
  IFS=',' read -r -a _mods <<< "$PREMIUM_MODULES"
  for m in "${_mods[@]}"; do
    m="$(printf '%s' "$m" | tr -d '[:space:]')"; [ -n "$m" ] || continue
    case "$m" in [a-z]*) : ;; *) warn "module ignoré (nom invalide) : $m"; continue ;; esac
    http_call POST "${DIST_URL%/}/?action=module&format=sh" \
      --data-urlencode "token=${INSTALL_TOKEN}" --data-urlencode "module=${m}" || { warn "module ${m} : serveur injoignable"; continue; }
    if [ "$(field STATUS)" != "ok" ]; then warn "module ${m} non autorisé (status=$(field STATUS), HTTP ${HTTP_CODE})"; continue; fi
    MSIG="$(field SIG)"; MSHA="$(field SHA256)"
    MARCH="$WORK/module-${m}.tar.gz"
    http_call GET "${DIST_URL%/}/?action=download&artifact=module-${m}&token=${INSTALL_TOKEN}" || { warn "module ${m} : DL injoignable"; continue; }
    [ "$HTTP_CODE" = "200" ] || { warn "module ${m} : DL HTTP ${HTTP_CODE}"; continue; }
    cp "$WORK/resp" "$MARCH"
    verify_artifact "$MARCH" "$MSHA" "$MSIG" "module ${m}"
    tar -xzf "$MARCH" -C "$TARGET_DIR"
    ok "Module ${m} installé"
  done
fi

# ── mémorise le contexte de licence (pour update.sh / phone_home.php / modules) ──
umask 077
cat > "$TARGET_DIR/.fronote-license" <<EOF
INSTALL_TOKEN=${INSTALL_TOKEN}
DIST_URL=${DIST_URL%/}
MODULES=${MODULES:-}
PREMIUM_MODULES=${PREMIUM_MODULES:-}
EOF
chmod 600 "$TARGET_DIR/.fronote-license"
ok "Socle + modules extraits ; contexte de licence enregistré (.fronote-license)"

[ -f "$TARGET_DIR/install.sh" ] || die "install.sh introuvable dans le socle — payload incomplet."
chmod +x "$TARGET_DIR/install.sh" 2>/dev/null || true
log "Lancement de l'installateur complet (OS, base, provisioning)…"
cd "$TARGET_DIR"
exec ./install.sh "${PASSTHRU[@]}"
