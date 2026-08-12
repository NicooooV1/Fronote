# Fronote — Serveur de distribution sous licence

Composant **isolé** (`dist/`, base SQLite propre, aucune dépendance à l'app multi-tenant)
qui distribue Fronote à des clients sous **licence à clé unique**, avec accès restreint par
**whitelist d'IP** (gérée par un bot Discord externe via l'API d'admin) et payloads **signés
Ed25519**.

## Modèle

1. Le distributeur **whiteliste l'IP publique** du client (via son bot Discord → API d'admin,
   ou `php dist/bin/allow.php <ip>`). Une IP non listée est **refusée** — pas de bannissement.
   Chaque refus est **journalisé** (`refused_log`, IP + type + date, jamais la clé soumise) et
   exposé au bot via `GET /admin/refused` : le bot peut notifier « telle IP a tenté d'installer »
   et proposer de l'autoriser en un clic.
2. Le distributeur **émet une clé** (48 car., usage unique) : `php dist/bin/issue-key.php`.
3. Il remet au client **`bootstrap.sh`** (configuré) + **la clé**.
4. Le client lance `sudo ./bootstrap.sh` → saisit la clé → le serveur valide (IP whitelistée +
   clé valide + non consommée) → renvoie un **jeton d'installation** + le **socle signé**
   (base + modules simples) → le bootstrapper **vérifie SHA-256 + Ed25519**, extrait, et lance
   l'installateur complet (`install.sh`).
5. Modules **premium** : téléchargés ensuite avec le jeton d'installation, si la licence y donne
   droit.

## Arborescence

```
dist/
  server.php            # point d'entrée HTTP (redeem, download, module, admin_*, health, pubkey)
  bootstrap.sh          # ← fichier remis au client (thin installer, vérifie la signature)
  config.example.php    # → copier en config.php (gitignoré)
  lib/  DistStore.php (SQLite: licences, allowlist, activations)
        Signer.php      (Ed25519, libsodium)
        PayloadBuilder.php (tar+gzip signés)
        loader.php      (chargeur autonome)
  bin/  init.php  issue-key.php  allow.php  disallow.php  status.php  revoke.php  build-payload.php
  data/                 # (gitignoré) dist.sqlite, signing.key/.pub, payloads/
```

## Mise en place (sur le serveur/LXC de distribution)

Prérequis : PHP 8.1+ **CLI + FPM**, `ext-sodium`, `ext-pdo_sqlite`, `tar`, un serveur web **HTTPS**.

```bash
cp dist/config.example.php dist/config.php
# éditer dist/config.php :
#   key_pepper   = openssl rand -hex 32
#   admin_token  = openssl rand -hex 32   (partagé avec le bot Discord)
#   dist_base_url= https://dist.mondomaine.fr
#   simple_modules = [...]

php dist/bin/init.php            # crée la base SQLite + la paire de clés Ed25519 ; AFFICHE LA CLÉ PUBLIQUE
php dist/bin/build-payload.php   # construit + signe base.tar.gz et chaque module premium

# Sécuriser les données (clé privée de signature !) :
chown -R www-data:www-data dist/data && chmod 700 dist/data && chmod 600 dist/data/signing.key
```

Servir `dist/server.php` en **HTTPS** (la clé de licence y transite). Exemple Apache — un vhost
dédié dont le DocumentRoot ne contient que `dist/`, `.../server.php` réécrit sur toutes les
requêtes ; **`dist/data/` NE DOIT JAMAIS être accessible en HTTP**.

## Opérations courantes (CLI)

```bash
# Émettre une clé (usage unique ; premium en plus des modules simples)
php dist/bin/issue-key.php --tier=base --modules=internat,stages --note="Collège X" [--max=1] [--expires=2027-01-01]

# Whitelist d'IP
php dist/bin/allow.php    203.0.113.5 "Collège X"
php dist/bin/disallow.php 203.0.113.5
php dist/bin/status.php   203.0.113.5      # ou : --list

# Révoquer une licence (clé complète, ou préfixe 6 car si la clé est perdue)
php dist/bin/revoke.php --key=<48car>
php dist/bin/revoke.php --prefix=Ab12Cd

# Reconstruire les payloads après une mise à jour du code
php dist/bin/build-payload.php
```

## API HTTP (pour le bot Discord)

Base : `POST`/`GET` sur `https://dist…/` avec `?action=` **ou** le chemin `/…`.
Les endpoints d'admin exigent l'en-tête **`X-Admin-Token: <admin_token>`**.

| Endpoint | Méthode | Corps / params | Rôle |
|---|---|---|---|
| `/admin/allow`    | POST | `ip`, `note?` | whiteliste une IP |
| `/admin/disallow` | POST | `ip`          | retire une IP |
| `/admin/list`     | GET  | `ip?`         | liste la whitelist, ou statut d'une IP |
| `/admin/refused`  | GET  | `since_id?`, `limit?` | tentatives refusées (IP non whitelistées) depuis un curseur |
| `/redeem`         | POST | `key`         | (client) valide+consomme la clé |
| `/download`       | GET  | `artifact`, `token` | (client) archive signée |
| `/module`         | POST | `token`, `module`   | (client) autorise un module premium |
| `/health` `/pubkey` | GET |             | statut / clé publique |

Exemples pour le bot (whitelist) :

```bash
curl -s -X POST "$DIST/admin/allow"    -H "X-Admin-Token: $ADMIN_TOKEN" -d "ip=203.0.113.5&note=Collège X"
curl -s -X POST "$DIST/admin/disallow" -H "X-Admin-Token: $ADMIN_TOKEN" -d "ip=203.0.113.5"
curl -s     "$DIST/admin/list"         -H "X-Admin-Token: $ADMIN_TOKEN"
curl -s     "$DIST/admin/list?ip=203.0.113.5" -H "X-Admin-Token: $ADMIN_TOKEN"
```

Réponses JSON : `{"status":"ok","allowed":"203.0.113.5"}`, `{"status":"ok","removed":true}`,
`{"status":"ok","allowlist":[…]}`. Token absent/mauvais → `401 {"status":"unauthorized"}`.

## Le bootstrapper remis au client

Avant distribution, personnaliser l'en-tête de `dist/bootstrap.sh` :

```bash
DIST_URL="https://dist.mondomaine.fr"          # HTTPS obligatoire
PUBKEY_HEX="<clé publique affichée par init.php>"
```

Le client :
```bash
sudo ./bootstrap.sh                 # demande la clé, puis installe
sudo FRONOTE_KEY=<clé> ./bootstrap.sh --yes APP_URL=https://ecole.fr ADMIN_EMAIL=a@ecole.fr
```
Il vérifie **SHA-256 + Ed25519** avant d'extraire : un payload altéré est **refusé avant
exécution**. Le jeton d'installation est stocké dans `<dir>/.fronote-license` (téléchargements
de modules ultérieurs).

## Sécurité

- **HTTPS** sur le serveur de distribution (la clé de licence y transite).
- **`dist/data/signing.key`** (clé privée Ed25519) : `chmod 600`, jamais exposée ; sa fuite
  permettrait de signer des payloads malveillants.
- `key_pepper` + hachage des clés → une fuite de la base SQLite ne révèle pas les clés.
- `admin_token` en temps constant (`hash_equals`) ; partagé uniquement avec le bot.
- Whitelist d'IP = surface d'attaque minimale (seuls les clients connus atteignent redeem).
- Clés de licence : 48 car. base62 (~285 bits) → brute-force infaisable.
