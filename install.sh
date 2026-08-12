#!/usr/bin/env bash
# =============================================================================
#  Fronote — installateur tout-en-un (Debian/Ubuntu)
#
#  Un seul fichier pour tout installer depuis une machine neuve :
#    - paquets système : PHP 8.2 + extensions, MariaDB, Node.js, Apache, composer
#    - base de données + utilisateur applicatif à PRIVILÈGE MINIMAL
#    - provisioning applicatif (schéma, 61 modules, admin, cohérence 3-mondes)
#    - serveur web (Apache mpm_event + PHP-FPM) + service WebSocket (systemd)
#    - permissions et secrets sécurisés au maximum
#
#  Usage :
#    sudo ./install.sh                         # prompts minimaux (URL, email admin)
#    sudo APP_URL=https://ecole.fr ADMIN_EMAIL=admin@ecole.fr ./install.sh --yes
#    # ou renseigner install.conf (voir install.conf.example) puis : sudo ./install.sh --yes
#
#  Options : --yes (non interactif) · --force (réinstalle malgré install.lock)
#            --no-websocket · --no-packages (suppose la stack déjà installée)
#            --no-webserver (ne configure pas Apache/WS)
# =============================================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_VER="${PHP_VER:-8.2}"
RUN_USER="${RUN_USER:-www-data}"

# ── journalisation ───────────────────────────────────────────────────────────
_c() { printf '\033[%sm' "$1"; }
log()  { printf '%s[install]%s %s\n' "$(_c '1;36')" "$(_c 0)" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$(_c '1;32')" "$(_c 0)" "$*"; }
warn() { printf '  %s⚠%s %s\n' "$(_c '1;33')" "$(_c 0)" "$*"; }
die()  { printf '%s[erreur]%s %s\n' "$(_c '1;31')" "$(_c 0)" "$*" >&2; exit 1; }

# secrets : hex pour les clés, mot de passe sans caractères gênants pour shell/DSN
gen_hex() { openssl rand -hex "${1:-32}"; }
gen_pw()  { openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-28; }

# ── options ──────────────────────────────────────────────────────────────────
NON_INTERACTIVE=0; FORCE=0; WITH_WEBSOCKET=1; DO_PACKAGES=1; DO_WEBSERVER=1; WIPE=0
for arg in "$@"; do
  case "$arg" in
    --yes|-y)        NON_INTERACTIVE=1 ;;
    --force)         FORCE=1 ;;
    --reset)         FORCE=1; WIPE=1 ;;   # réinstallation DESTRUCTIVE (efface les données)
    --no-websocket)  WITH_WEBSOCKET=0 ;;
    --no-packages)   DO_PACKAGES=0 ;;
    --no-webserver)  DO_WEBSERVER=0 ;;
    -h|--help)       sed -n '2,25p' "$0"; exit 0 ;;
    *) die "Option inconnue : $arg" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "Lancez ce script en root : sudo ./install.sh"

# ── configuration : install.conf → variables d'env → prompts ────────────────
# shellcheck disable=SC1091
[ -f "$APP_DIR/install.conf" ] && { log "Chargement de install.conf"; source "$APP_DIR/install.conf"; }

DB_NAME="${DB_NAME:-fronote}"
DB_USER="${DB_USER:-fronote}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
HTTP_PORT="${HTTP_PORT:-8081}"
APP_NAME="${APP_NAME:-Fronote}"
APP_ENV="${APP_ENV:-production}"
ADMIN_NOM="${ADMIN_NOM:-Admin}"
ADMIN_PRENOM="${ADMIN_PRENOM:-Fronote}"

prompt() { # prompt VAR "question" "défaut"
  local __var="$1" __q="$2" __def="${3:-}" __ans=""
  if [ -n "${!__var:-}" ]; then return; fi
  if [ "$NON_INTERACTIVE" = 1 ]; then
    [ -n "$__def" ] && { printf -v "$__var" '%s' "$__def"; return; }
    die "Variable requise manquante en mode non interactif : $__var"
  fi
  read -r -p "  $__q${__def:+ [$__def]} : " __ans || true
  printf -v "$__var" '%s' "${__ans:-$__def}"
}

log "Configuration"
prompt APP_URL     "URL publique de l'application (ex: https://ecole.exemple.fr)" "http://localhost:${HTTP_PORT}"
prompt ADMIN_EMAIL "Email de l'administrateur"                                    ""
[ -n "${ADMIN_EMAIL:-}" ] || die "Email administrateur requis (ADMIN_EMAIL)."
[[ "$APP_URL" == https* ]] && SECURE_HINT="https" || SECURE_HINT="http"
ok "URL=${APP_URL} · admin=${ADMIN_EMAIL} · DB=${DB_NAME} · port web=${HTTP_PORT} · WebSocket=$([ $WITH_WEBSOCKET = 1 ] && echo oui || echo non)"

FRESH_INSTALL=1; [ -f "$APP_DIR/install.lock" ] && FRESH_INSTALL=0
if [ "$FRESH_INSTALL" = 0 ] && [ "$FORCE" = 0 ]; then
  die "install.lock présent (déjà installé). Relancez avec --force pour réinstaller."
fi

# ── 1. paquets système ──────────────────────────────────────────────────────
if [ "$DO_PACKAGES" = 1 ]; then
  command -v apt-get >/dev/null || die "Distribution non supportée (apt introuvable). Relancez avec --no-packages sur une stack déjà installée."
  log "Installation des paquets système (PHP ${PHP_VER}, MariaDB, Node.js, Apache)…"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
    "php${PHP_VER}-xml" "php${PHP_VER}-zip" "php${PHP_VER}-gd" "php${PHP_VER}-curl" \
    "php${PHP_VER}-intl" "php${PHP_VER}-bcmath" \
    mariadb-server apache2 nodejs npm unzip curl openssl ca-certificates git >/dev/null
  ok "Paquets système installés"
  # composer (vérifié par signature officielle)
  if ! command -v composer >/dev/null; then
    log "Installation de composer"
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');")"
    [ "$EXPECTED" = "$ACTUAL" ] || die "Signature de l'installeur composer invalide — abandon."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
    ok "composer installé"
  fi
else
  log "Étape paquets ignorée (--no-packages) — vérification des prérequis"
  command -v php >/dev/null || die "php introuvable."
  command -v composer >/dev/null || die "composer introuvable."
  command -v mariadb >/dev/null || command -v mysql >/dev/null || die "client MariaDB/MySQL introuvable."
fi

# extensions PHP indispensables
for ext in pdo_mysql mbstring json openssl; do
  php -m 2>/dev/null | grep -qi "^${ext}$" || die "Extension PHP manquante : ${ext}"
done
ok "Extensions PHP requises présentes"

# ── 2. dépendances PHP ──────────────────────────────────────────────────────
log "Installation des dépendances PHP (composer, sans dev)"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction \
  --working-dir="$APP_DIR" >/dev/null 2>&1 || die "composer install a échoué."
ok "Dépendances PHP installées (vendor/)"

# ── 3. secrets ──────────────────────────────────────────────────────────────
FORCE_FLAG=""; [ "$FORCE" = 1 ] && FORCE_FLAG="--force"
DB_PASS="$(gen_pw)"
if [ "$FRESH_INSTALL" = 1 ]; then ADMIN_PW="$(gen_pw)"; else ADMIN_PW=""; fi

# ── 4. base de données + utilisateur à privilège minimal ────────────────────
log "Base de données MariaDB"
[ "$DO_PACKAGES" = 1 ] && systemctl enable --now mariadb >/dev/null 2>&1 || true
MARIADB_BIN="$(command -v mariadb || command -v mysql)"
# root via socket unix (défaut Debian/MariaDB) — aucun mot de passe root en clair.
"$MARIADB_BIN" <<SQL || die "Impossible de créer la base (accès root MariaDB par socket requis)."
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL
ok "Base « ${DB_NAME} » + utilisateur « ${DB_USER} »@${DB_HOST} (droits limités à cette base uniquement)"

# ── 5. provisioning applicatif (headless, en tant que www-data) ─────────────
log "Provisioning applicatif (schéma, modules, admin, cohérence 3-mondes)"
id "$RUN_USER" >/dev/null 2>&1 || die "Utilisateur système « $RUN_USER » introuvable."
# Droits d'écriture pour que www-data puisse écrire .env / install.lock / caches.
chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
sudo -u "$RUN_USER" env \
  FRONOTE_DB_HOST="$DB_HOST" FRONOTE_DB_PORT="$DB_PORT" FRONOTE_DB_NAME="$DB_NAME" \
  FRONOTE_DB_USER="$DB_USER" FRONOTE_DB_PASS="$DB_PASS" \
  FRONOTE_APP_NAME="$APP_NAME" FRONOTE_APP_ENV="$APP_ENV" FRONOTE_APP_URL="$APP_URL" \
  FRONOTE_ADMIN_MAIL="$ADMIN_EMAIL" FRONOTE_ADMIN_NOM="$ADMIN_NOM" FRONOTE_ADMIN_PRENOM="$ADMIN_PRENOM" \
  FRONOTE_ADMIN_PW="${ADMIN_PW:-unused_existing_admin}" \
  FRONOTE_WEBSOCKET_ENABLED="$([ "$WITH_WEBSOCKET" = 1 ] && echo true || echo false)" \
  FRONOTE_WEBSOCKET_URL="http://127.0.0.1:3000" \
  FRONOTE_WEBSOCKET_CLIENT_URL="${APP_URL%/}" \
  FRONOTE_WIPE="$([ "$WIPE" = 1 ] && echo 1 || echo 0)" \
  php "$APP_DIR/bin/install.php" ${FORCE_FLAG:-} \
  || die "Le provisioning applicatif a échoué (voir messages ci-dessus)."
[ "$WIPE" = 1 ] && warn "Mode --reset : import destructif (données précédentes écrasées)."
ok "Application provisionnée"

# ── 6. permissions ──────────────────────────────────────────────────────────
log "Permissions"
for d in uploads temp "API/logs" storage; do mkdir -p "$APP_DIR/$d"; done
# Propriété à l'utilisateur applicatif (nécessaire : écriture .env/caches/uploads).
chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
# Durcissement ciblé (on évite un chmod récursif global : lent + casserait vendor/bin).
chmod 640 "$APP_DIR/.env"
chmod 750 "$APP_DIR"/uploads "$APP_DIR"/temp "$APP_DIR"/API/logs "$APP_DIR"/storage 2>/dev/null || true
[ -f "$APP_DIR/install.conf" ] && chmod 600 "$APP_DIR/install.conf" 2>/dev/null || true
chmod +x "$APP_DIR/install.sh" 2>/dev/null || true
ok ".env en 0640, dossiers d'écriture en 0750, propriété ${RUN_USER}"

# ── 7. serveur web (Apache mpm_event + PHP-FPM) ─────────────────────────────
if [ "$DO_WEBSERVER" = 1 ]; then
  log "Serveur web (Apache + PHP-FPM ${PHP_VER})"
  systemctl enable --now "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
  a2dismod "php${PHP_VER}" mpm_prefork >/dev/null 2>&1 || true
  a2enmod mpm_event proxy_fcgi setenvif rewrite headers >/dev/null 2>&1 || true
  [ "$WITH_WEBSOCKET" = 1 ] && a2enmod proxy proxy_http proxy_wstunnel >/dev/null 2>&1 || true
  a2enconf "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
  grep -q "^Listen ${HTTP_PORT}\b" /etc/apache2/ports.conf 2>/dev/null || echo "Listen ${HTTP_PORT}" >> /etc/apache2/ports.conf

  # Bloc proxy WebSocket (browser → APP_URL/socket.io → 127.0.0.1:3000) pour un fonctionnement mono-machine.
  WS_PROXY=""
  if [ "$WITH_WEBSOCKET" = 1 ]; then
    WS_PROXY=$'    ProxyPreserveHost On\n    ProxyPass /socket.io/ ws://127.0.0.1:3000/socket.io/\n    ProxyPassReverse /socket.io/ ws://127.0.0.1:3000/socket.io/'
  fi

  cat > /etc/apache2/sites-available/fronote.conf <<VHOST
<VirtualHost *:${HTTP_PORT}>
    DocumentRoot ${APP_DIR}
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:/run/php/php${PHP_VER}-fpm.sock|fcgi://localhost"
    </FilesMatch>
    # Défense en profondeur : bloquer les fichiers sensibles même si .htaccess absent.
    <FilesMatch "^(\\.env.*|\\.git.*|composer\\.(json|lock)|install\\.lock|install\\.sh|install\\.conf)\$">
        Require all denied
    </FilesMatch>
${WS_PROXY}
    ErrorLog \${APACHE_LOG_DIR}/fronote-error.log
    CustomLog \${APACHE_LOG_DIR}/fronote-access.log combined
</VirtualHost>
VHOST
  a2ensite fronote.conf >/dev/null 2>&1 || true
  if apache2ctl configtest >/dev/null 2>&1; then
    systemctl reload apache2 || systemctl restart apache2 || true
    ok "vhost Apache actif sur le port ${HTTP_PORT} (PHP-FPM, indexes off, fichiers sensibles bloqués)"
  else
    warn "La configuration Apache ne valide pas — vhost écrit mais non rechargé. Vérifiez : apache2ctl configtest"
  fi

  # ── 8. WebSocket (systemd) ─────────────────────────────────────────────────
  if [ "$WITH_WEBSOCKET" = 1 ] && [ -d "$APP_DIR/websocket" ]; then
    log "Service WebSocket (systemd)"
    ( cd "$APP_DIR/websocket" && sudo -u "$RUN_USER" npm install --omit=dev >/dev/null 2>&1 ) || warn "npm install (websocket) a échoué — service non démarré."
    cat > /etc/systemd/system/fronote-ws.service <<UNIT
[Unit]
Description=Fronote WebSocket (Socket.IO)
After=network.target mariadb.service

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${APP_DIR}/websocket
# Le serveur charge lui-même ../.env via dotenv — pas d'EnvironmentFile (évite les
# erreurs de parsing systemd sur des valeurs contenant des caractères spéciaux).
ExecStart=$(command -v node) server.js
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now fronote-ws >/dev/null 2>&1 && ok "Service fronote-ws actif" || warn "fronote-ws non démarré (voir : journalctl -u fronote-ws)"
  fi
else
  log "Configuration serveur web ignorée (--no-webserver)"
fi

# ── 9. récapitulatif ────────────────────────────────────────────────────────
echo
printf '%s══════════════════════════════════════════════════════════════%s\n' "$(_c '1;32')" "$(_c 0)"
printf '%s  Fronote est installé.%s\n' "$(_c '1;32')" "$(_c 0)"
printf '%s══════════════════════════════════════════════════════════════%s\n' "$(_c '1;32')" "$(_c 0)"
echo "  URL           : ${APP_URL}"
[ "$DO_WEBSERVER" = 1 ] && echo "  Accès local   : http://127.0.0.1:${HTTP_PORT}/"
echo "  Identifiant   : admin"
if [ "$FRESH_INSTALL" = 1 ]; then
  echo "  Mot de passe  : ${ADMIN_PW}"
  echo "  (à changer OBLIGATOIREMENT à la première connexion)"
else
  echo "  Mot de passe  : inchangé (admin déjà existant — réinstallation)"
fi
echo "  Super-admin plateforme : superadmin (mêmes identifiants) — portail /platform/"
echo
echo "  Le mot de passe de la base est stocké UNIQUEMENT dans ${APP_DIR}/.env (0640, ${RUN_USER})."
[ "$SECURE_HINT" = http ] && warn "URL en http:// — pour la production, mettez un certificat TLS puis APP_URL=https://… (SESSION_SECURE passera à true)."
echo
