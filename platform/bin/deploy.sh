#!/usr/bin/env bash
# Déploie la PLATEFORME depuis le dépôt Fronote (CT102, source de vérité) vers CT129.
#   - code : platform/ + assets/css/platform.css + Font Awesome (aucune ressource externe)
#   - préserve /etc/fronote-platform/config.php (hors dépôt)
#   - applique le schéma (idempotent), vérifie la syntaxe, recharge PHP-FPM
# Usage (sur l'hyperviseur) : platform/bin/deploy.sh   — retour arrière : --rollback
set -euo pipefail
SRC_CT=102; SRC_REPO=/var/www/html/Pronote; CT=129; APP=/opt/fronote-platform
log(){ printf '\033[1;34m==>\033[0m %s\n' "$*"; }
run(){ pct exec "$CT" -- bash -euo pipefail -c "$1"; }
if [ "${1:-}" = "--rollback" ]; then
  run "[ -d $APP.prev ] && mv $APP $APP.failed.\$(date +%s) && mv $APP.prev $APP && systemctl reload php8.4-fpm"
  log "Version précédente restaurée."; exit 0
fi
SHA=$(pct exec $SRC_CT -- git -C $SRC_REPO rev-parse --short HEAD)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
pct exec $SRC_CT -- git -C $SRC_REPO archive --format=tar HEAD platform assets/css/platform.css assets/lib/fontawesome > "$TMP/p.tar"
pct push $CT "$TMP/p.tar" /tmp/platform.tar
log "Préparation ($SHA)"
run "rm -rf $APP.next && mkdir -p $APP.next/x && tar -xf /tmp/platform.tar -C $APP.next/x && rm -f /tmp/platform.tar
     mv $APP.next/x/platform/* $APP.next/ && mkdir -p $APP.next/public/assets
     cp $APP.next/x/assets/css/platform.css $APP.next/public/assets/platform.css
     cp -r $APP.next/x/assets/lib/fontawesome $APP.next/public/assets/fontawesome
     rm -rf $APP.next/x; echo '$SHA' > $APP.next/REVISION
     chown -R root:root $APP.next; find $APP.next -type d -exec chmod 755 {} +; find $APP.next -type f -exec chmod 644 {} +; chmod 755 $APP.next/bin/*.sh
     for f in \$(find $APP.next -name '*.php'); do php -l \$f >/dev/null; done"
log "Bascule"
run "rm -rf $APP.prev; [ -d $APP ] && mv $APP $APP.prev || true; mv $APP.next $APP
     php $APP/bin/migrate.php; systemctl reload php8.4-fpm"
log "Vérification"
run "curl -s -o /dev/null -w 'console sans SSO : %{http_code} (403 attendu)\n' http://127.0.0.1/; curl -s -o /dev/null -w 'API sans jeton : %{http_code} (401 attendu)\n' http://127.0.0.1/api/instance/ping"
log "Plateforme $SHA déployée."
