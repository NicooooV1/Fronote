# Guide d'installation — Fronote

> Ce document s'adresse aux **administrateurs système** et **responsables informatiques** qui déploient Fronote sur un serveur, ainsi qu'aux **développeurs** qui veulent comprendre ce que fait l'assistant d'installation.
>
> Version documentée : **Fronote 4.0.0** (build 2026-08-11). Source de vérité : `install.php`, `.env.example`, `pronote.sql`, `API/Services/UpdateService.php`, `API/Services/SchemaSyncService.php`, `API/Services/MigrationRunner.php`. Pour tout ce qui touche au schéma et aux mises à jour, le guide de référence est **[docs/UPDATING.md](docs/UPDATING.md)**.

---

## 1. Prérequis

| Logiciel / extension | Version minimale | Vérification |
|----------------------|-----------------|--------------|
| PHP | **8.0+** | `php -v` |
| MySQL | **8.0+** *(ou MariaDB 10.3+)* | `mysql --version` |
| Serveur web (Apache 2.4+ / Nginx) | — | `apache2 -v` |
| Composer | 2.x | `composer --version` |
| Git *(pour les mises à jour)* | 2.x | `git --version` |

### Extensions PHP

L'assistant **refuse de continuer** si l'une de ces extensions est absente (vérifiées à l'étape 1 *et* au POST) :

```
pdo · pdo_mysql · json · mbstring · session · sodium · zip · fileinfo
```

Extensions **recommandées** (leur absence n'empêche pas l'installation mais dégrade des fonctionnalités — un avertissement est affiché) :

```
intl  (i18n)   ·   gd  (avatars / images)   ·   curl  (marketplace, HTTP sortant)
```

> `composer.json` exige `php >=8.0`, `ext-sodium`, `ext-json`, `ext-zip`, `ext-pdo` et la dépendance `firebase/php-jwt ^7.0`.

### Apache : `mod_rewrite`

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 2. Déposer les fichiers et installer les dépendances

```bash
# Récupérer le code (recommandé : git, indispensable pour les mises à jour intégrées)
git clone https://github.com/VOTRE-ORG/fronote.git /var/www/fronote
cd /var/www/fronote

# Dépendances PHP (sans les paquets de dev, autoload optimisé)
composer install --no-dev --optimize-autoloader
```

> Le code peut aussi être déposé depuis une archive ZIP, mais le bouton de mise à jour intégré (chapitre 7) nécessite un dépôt Git fonctionnel (`git init` + remote configuré).

### Droits des dossiers

L'utilisateur du serveur web doit pouvoir **écrire** dans le `.env`, les logs, les uploads et le stockage. L'assistant **crée et teste** ces répertoires lui‑même, mais ils doivent être inscriptibles :

```bash
chown -R www-data:www-data /var/www/fronote
# 775 sur les dossiers d'écriture — l'assistant écrit .env, install.lock, logs, uploads, storage…
chmod -R 755 /var/www/fronote
chmod -R 775 /var/www/fronote/{uploads,temp,storage,API/logs}
```

Répertoires **créés et vérifiés** par l'assistant (étape 1) :

```
API/logs · API/config
uploads · uploads/messagerie · uploads/devoirs · uploads/justificatifs
temp
storage · storage/cache · storage/tmp · storage/pdf
storage/backups · storage/backups/modules
storage/email_queue · storage/quarantine
```

> Si un répertoire manque ou n'est pas inscriptible, l'étape 1 affiche directement les commandes correctives (`mkdir -p …`, `chmod 755 …`, `chown -R www-data …`) à coller sur le serveur.

---

## 3. L'assistant d'installation (`install.php`)

Ouvrez dans un navigateur :

```
http://votre-serveur/fronote/install.php
```

> **Accès restreint au réseau local.** `install.php` ne répond qu'aux IP privées (`127.0.0.1`, `::1`, `10.x`, `172.16–31.x`, `192.168.x`). Une IP supplémentaire peut être autorisée via la clé `ALLOWED_INSTALL_IP` du `.env` (liste séparée par des virgules). Toute autre adresse reçoit un **403**.
>
> **Anti‑réinstallation.** Si `install.lock` **et** un `.env` exploitable (> 10 octets) existent déjà, l'assistant renvoie un 403 « Installation déjà effectuée ». Si `install.lock` existe mais que `.env` est introuvable/illisible, l'assistant reste accessible en **mode récupération** pour réécrire la configuration.

L'assistant déroule **5 étapes**, chacune validée côté serveur avant de passer à la suivante (impossible de sauter en avant ; on peut revenir en arrière). Les saisies sont conservées en session.

| Étape | Contenu | Ce qui est vérifié / fait |
|-------|---------|---------------------------|
| **1. Pré‑requis** | — | Version PHP ≥ 8.0, extensions requises, répertoires inscriptibles, présence de `API/bootstrap.php`, `API/core.php`, `pronote.sql` |
| **2. Base de données** | Hôte, port, utilisateur, mot de passe, **nom de la base**, charset | **Connexion MySQL testée en direct** (aucune base créée ici). `localhost` est converti en `127.0.0.1` (TCP obligatoire). En cas d'erreur 1045/2002, un message d'aide ciblé est affiché (GRANT à exécuter, port à ouvrir…) |
| **3. Application** | Nom, environnement, mode debug, URL, chemin de base, paramètres session/CSRF/rate‑limit, **SMTP optionnel** | `APP_DEBUG` interdit si `APP_ENV=production`. Les valeurs de sécurité sont bornées (session ≥ 600 s, CSRF ≥ 300 s, tentatives login 3–10…) |
| **4. Administrateur** | Nom, prénom, email, mot de passe | Mot de passe **≥ 12 caractères** avec majuscule, minuscule, chiffre et caractère spécial. L'**identifiant de connexion sera `admin`** (fixe) |
| **5. Installation** | Récapitulatif → exécution | Voir détail ci‑dessous |

### Détail de l'étape 5 (exécution)

L'assistant, dans l'ordre :

1. **Écrit `.env`** (écriture atomique : fichier temporaire + `fsync` + `rename`). Génère automatiquement `APP_KEY` et `JWT_SECRET` (32 octets aléatoires hex chacun), positionne `SESSION_SECURE` selon HTTPS, renseigne `ALLOWED_INSTALL_IP` avec l'IP courante.
2. **Crée les fichiers de protection** : `.htaccess` racine (interdit `.env`, `install.lock`, `*.sql`, `*.ini`…), plus `uploads/.htaccess`, `temp/.htaccess`, `API/logs/.htaccess` (« Deny from all »).
3. **Se connecte à MySQL** puis **crée la base** (`CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`).
   - **Protection contre l'écrasement** : si la base existe déjà et contient des tables, l'assistant affiche le nombre de tables et **exige une case de confirmation** avant de la `DROP`/recréer. Sinon, revenez à l'étape 2 pour choisir un autre nom.
4. **Importe `pronote.sql`** (le socle : `administrateurs`, `eleves`, `professeurs`, `parents`, `classes`, `matieres`, `periodes`, `modules_config`, audit, SMTP…). L'import désactive `FOREIGN_KEY_CHECKS` (FK croisées), découpe proprement le dump, et **échoue dur** sur toute erreur SQL non bénigne (une erreur réelle ne doit pas être présentée comme un succès).
5. **Crée le compte administrateur** (`password_hash` BCRYPT cost 12, `identifiant = admin`, `role = administrateur`).
6. **Configure le SMTP** si renseigné à l'étape 3 (`UPDATE smtp_config`).
7. **Charge `API/bootstrap.php`** et lance des **tests de bout en bout** non bloquants : authentification de l'admin créé, RateLimiter, CSRF.
8. **Synchronise et provisionne les modules** *(voir ci‑dessous)*.
9. **Sécurise et vérifie `.env`** (`chmod 0640` en production), puis **crée `install.lock`** (écriture atomique également).
10. **Purge les secrets en clair** de la session (mots de passe admin/DB/SMTP).
11. **Neutralise l'installateur** : tente de renommer `install.php` en `install.php.disabled-AAAAMMJJ` (best‑effort ; sous Windows le rename peut échouer → repli `chmod 0400` + avertissement de le faire à la main).

### Provisionnement du schéma des modules & des rôles

Le schéma DDL est **déclaratif** (toutes les définitions en `CREATE TABLE IF NOT EXISTS`, schéma final complet), réparti sur trois sources :

- **`pronote.sql`** — le cœur (importé à l'étape 5.4 ci‑dessus) ;
- **`modules/<clé>/Database/install.sql`** — le schéma de chaque module ;
- **`rgpd/Database/*.sql`** — le schéma RGPD.

Ces sources sont réconciliées de façon **ADDITIVE** par `SchemaSyncService` (`CREATE TABLE` + `ADD COLUMN` uniquement — jamais de `DROP`, ni changement de type, ni index/FK sur table existante). L'assistant appelle `SchemaSyncService::sync()` pendant l'étape 5 pour rendre la base conforme aux `.sql` du dépôt.

> **Migrations.** L'ancien système de migrations **PAR MODULE** n'existe plus (ni table `module_migrations`/`core_migrations`, ni dossier `modules/*/Database/migrations`, ni clé `migrations` dans `module.json`, ni `scripts/migrate.php`). Un système de migrations **DE DONNÉES versionnées** subsiste pour les cas que le déclaratif ne couvre pas (rename, retype, index/FK sur base existante, backfill) : `database/migrations/<horodatage>_<nom>.php` (objet `up(\PDO)`/`down(\PDO)`, journal `schema_migrations`), joué par `MigrationRunner` lors des mises à jour. Détails dans **[docs/UPDATING.md](docs/UPDATING.md)**.

À l'étape 5.8, l'assistant appelle `ModuleSDK::syncAll()` (enregistre chaque module dans `modules_config`, ses widgets, permissions et routes) puis `provisionSql()` pour **tous les modules découverts** — leurs tables sont créées **même si le module reste désactivé** (l'activation gère la *visibilité*, pas le *schéma*). Les modules essentiels (`core`) sont activés ; les autres restent à activer dans l'admin. L'assistant synchronise enfin le catalogue de rôles (`PlatformRoleSync`, `TenantRoleSync`) et initialise la **cohérence 3-mondes** (rôles, établissement, miroir tenant, super-admin plateforme).

> `ModuleSDK::discover()` scanne `modules/*/module.json` **et** `racine/*/module.json` (les modules métier vivent sous `modules/<clé>/`, les essentiels restent à la racine).

À la fin, l'écran de succès affiche le **journal d'installation** complet et les informations de connexion.

---

## 4. Le fichier `.env`

`.env` est **généré automatiquement** par l'assistant — ne le créez pas à la main. `.env.example` documente toutes les clés disponibles. Principales clés écrites par l'installateur :

```env
# Base de données
DB_HOST=127.0.0.1      # localhost est réécrit en 127.0.0.1 (TCP)
DB_PORT=3306
DB_NAME=...
DB_USER=...
DB_PASS=...
DB_CHARSET=utf8mb4

# Application
APP_NAME=Fronote
APP_ENV=production
APP_DEBUG=false        # interdit à true si APP_ENV=production
APP_URL=https://votre-domaine.fr/fronote
APP_BASE_PATH=/var/www/fronote   # chemin disque absolu du projet
BASE_URL=/fronote                 # préfixe d'URL (vide si à la racine du domaine)

# Sécurité (générés aléatoirement à l'installation)
APP_KEY=<64 hex>       # HMAC des cookies signés, chiffrement at-rest, sauvegardes
JWT_SECRET=<64 hex>    # JWT (WebSocket) ; repli si APP_KEY absent
TRUST_PROXY_HEADERS=false   # true UNIQUEMENT derrière un reverse-proxy de confiance terminant TLS
SESSION_SECURE=true    # true si HTTPS détecté à l'installation
SESSION_NAME=pronote_session
SESSION_LIFETIME=7200
CSRF_LIFETIME=3600
MAX_LOGIN_ATTEMPTS=5
RATE_LIMIT_ATTEMPTS=5

# Chemins (déduits de APP_BASE_PATH)
LOGS_PATH=.../API/logs
UPLOADS_PATH=.../uploads
TEMP_PATH=.../temp

# Sécurité installateur
ALLOWED_INSTALL_IP=<IP du poste d'installation>

# Mises à jour
GITHUB_BRANCH=main     # branche Git suivie
# GIT_BINARY=          # chemin de git si absent du PATH (ex. Windows)

# i18n
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
```

> Pour générer manuellement une clé 64 hex : `php -r "echo bin2hex(random_bytes(32));"`.
>
> Le mail SMTP peut rester vide à l'installation et être configuré ensuite dans l'administration. Sans SMTP, Fronote retombe sur `mail()` de PHP (souvent bloqué par les hébergeurs).

---

## 5. Première connexion & onboarding

```
http://votre-serveur/fronote/login/index.php
```

Connectez‑vous avec **identifiant `admin`** et le mot de passe défini à l'étape 4.

> **Assistant de mise en route (onboarding) — obligatoire.** Tant que l'établissement courant porte le code `default`, tout accès d'un compte `administrateur`/`super_admin` est redirigé vers **`/modules/onboarding/index.php`** (gate dans `API/onboarding_gate.php`, appliqué depuis `API/module_boot.php` et `admin/includes/header.php`). Vous y définissez l'identité de l'établissement, les classes, les matières et les **périodes** (trimestres *ou* semestres, avec leurs dates). Seules les pages `/admin/etablissement/*` restent accessibles pendant ce blocage (ce sont elles qui font sortir de l'état `default`).
>
> **Fin d'année scolaire.** Une fois configuré, si aucune période ne couvre la date du jour, l'admin est automatiquement renvoyé vers `admin/etablissement/periodes.php?reconfigure=1` pour redéfinir les plages avant de continuer.

Chaque établissement a ses propres périodes et son propre découpage (un collège en trimestres, un lycée en semestres). Les **établissements supplémentaires** (multi‑établissement, géré par `super_admin`) et toute reconfiguration se font dans **Administration → Établissement**.

---

## 6. Tâches planifiées (cron)

Fronote regroupe la maintenance dans **un seul** script : `cron/daily_maintenance.php`.

```bash
crontab -e
```

```cron
# Maintenance quotidienne (backup DB + rotation, purge audit, file d'e-mails, cache, storage/tmp, quarantaine) — 2h du matin
0 2 * * * php /var/www/fronote/cron/daily_maintenance.php >> /var/www/fronote/API/logs/cron.log 2>&1
```

Ce que fait `cron/daily_maintenance.php` (chaque tâche est *best-effort* : un échec n'interrompt pas les suivantes, et la sortie est journalisée) :

1. **Backup de la base** via `app('backup')->createDatabaseBackup()` (dump SQL gzippé, chiffré at-rest si `APP_KEY` présent — voir chapitre 10) ;
2. **Rotation des backups** : conserve les `BACKUP_RETENTION` plus récents par type (défaut **5**) ;
3. **Purge de l'audit** : supprime les entrées de plus de `AUDIT_RETENTION_DAYS` jours (défaut **180**) ;
4. **Purge de la file d'e-mails** traitée (`email_log` + corps `storage/email_queue/`, > 30 j) ;
5. **GC du cache** applicatif expiré ;
6. **Nettoyage de `storage/tmp`** (fichiers > 24h) ;
7. **Nettoyage de `storage/quarantine`** (reliquats > 30 j).

> **Pas de worker requis.** Il n'existe plus de `scripts/worker.php` : Fronote ne dépend d'aucune file de jobs asynchrone côté cron. De même, il n'y a **plus** de cron de vérification de mise à jour — les mises à jour se déclenchent depuis l'interface (chapitre 7).

### Filet de sécurité sans cron

Si vous **ne pouvez pas** configurer de cron (hébergement mutualisé), Fronote déclenche un *tick* de maintenance **minimal** depuis `API/bootstrap.php` : au plus **une fois par 24h** (fichier sentinelle `storage/.last_maintenance`), de façon **non bloquante et silencieuse**, il exécute la purge d'audit et le nettoyage de `storage/tmp`. Ce filet **ne remplace pas** le cron : il **ne fait ni backup ni rotation**. Pour des sauvegardes fiables, configurez le cron ci-dessus (ou la sauvegarde externe du chapitre 10).

---

## 7. Mises à jour (un seul bouton)

Fronote se met à jour depuis l'interface d'administration : **Administration → Système → Mises à jour** (`admin/systeme/update.php`, réservé au rôle `administrateur`).

### Ce que fait « Mettre à jour maintenant »

`app('updates')->applyUpdate()` (classe `API\Services\UpdateService`) exécute, dans l'ordre :

1. **Garde-fous** : refus si la branche servie ≠ `GITHUB_BRANCH`, si l'arbre de travail est *dirty* (`git reset --hard` détruirait des modifs non commitées) ou si le HEAD git est illisible (rollback impossible) ;
2. **Filet de sécurité** : passage en **mode maintenance** (refus si indisponible), **sauvegarde de la base** et capture du HEAD courant ;
3. `git fetch origin <branche>` ;
4. `git reset --hard origin/<branche>` — le serveur reflète **exactement** le dépôt distant (le `.env` est sauvegardé puis restauré s'il venait à disparaître) ;
5. **`SchemaSyncService::sync()`** — réconciliation **déclarative et ADDITIVE** du schéma (`CREATE TABLE` + `ADD COLUMN` uniquement ; jamais de `DROP`, de changement de type ni d'index/FK sur table existante), lue depuis `pronote.sql` + `modules/*/Database/install.sql` + `rgpd/Database/*.sql` ;
6. **`MigrationRunner::migrate()`** — joue les migrations **versionnées** en attente (`database/migrations/`, journal `schema_migrations`) pour les cas non additifs ;
7. **Toute erreur schéma/migration ⇒ ROLLBACK COMPLET** : base restaurée depuis la sauvegarde **et** code remis au HEAD précédent, puis sortie de maintenance ;
8. `module_sdk->syncAll()` — re‑synchronise les manifestes (widgets, routes ; le bloc `permissions` reste déclaratif) ;
9. **vide le cache** applicatif, puis **sort du mode maintenance**.

> Le catalogue de rôles n'est **plus** synchronisé en base par la mise à jour : il vit en code (`API\Security\RoleCatalog`) et les déviations de permissions sont des lignes de la table globale `rbac_grants` (éditées côté plateforme). Rien à resynchroniser.

Le bouton **Vérifier** liste les commits en attente sur `origin/<branche>` sans rien appliquer. Le déroulé complet et le workflow d'évolution de schéma sont détaillés dans **[docs/UPDATING.md](docs/UPDATING.md)**.

### Configuration

Dans l'onglet de la page Mises à jour :

| Clé `.env` | Rôle | Défaut |
|------------|------|--------|
| `GITHUB_BRANCH` | Branche Git suivie | `main` |
| `GIT_BINARY` | Chemin de l'exécutable `git` s'il n'est pas dans le PATH du serveur web (utile sous Windows) | `git` |

> **Prérequis : Git.** Le serveur doit avoir `git` installé et le projet doit être un dépôt Git valide avec un remote configuré. Le badge « git OK / git introuvable » de la page le confirme. Si Fronote a été déposé depuis un ZIP :
> ```bash
> cd /var/www/fronote
> git init && git remote add origin https://github.com/VOTRE-ORG/fronote.git
> git fetch origin && git reset --hard origin/main
> ```
>
> Le mécanisme historique (webhook GitHub, releases ZIP, `webhook_update.php`, `scripts/update.php`) a été **supprimé**. La mise à jour est désormais 100 % « pull » déclenché depuis l'admin.

---

## 8. WebSocket temps réel (optionnel)

Le serveur WebSocket (Socket.IO) alimente les **notifications temps réel** de la messagerie (nouveau message, indicateur de frappe…). Code dans **`websocket/`** (`server.js` + `package.json`).

> **Sans WebSocket, tout fonctionne** : l'application interroge périodiquement le serveur en arrière‑plan. `WEBSOCKET_ENABLED=false` par défaut dans le `.env` généré.

```bash
# Node.js 16+
sudo apt install nodejs npm && node -v

cd /var/www/fronote/websocket
npm install                      # installe les dépendances (socket.io, jsonwebtoken, dotenv…)

# Démarrage persistant avec PM2
sudo npm install -g pm2
pm2 start server.js --name fronote-ws
pm2 save && pm2 startup
```

Le serveur lit le `.env` **à la racine du projet** (`../.env`). Variables utiles : `WEBSOCKET_PORT` (défaut 3000), `WEBSOCKET_API_SECRET`, `JWT_SECRET` (ou `APP_KEY`), `WEBSOCKET_ALLOWED_ORIGINS`, et `WSS_CERT_PATH`/`WSS_KEY_PATH` pour le TLS. Activez‑le côté Fronote :

```env
WEBSOCKET_ENABLED=true
WEBSOCKET_URL=http://localhost:3000
WEBSOCKET_CLIENT_URL=ws://localhost:3000
```

Vérification : `curl http://localhost:3000/health`.

---

## 9. Checklist post‑installation

- [ ] Connexion administrateur (`admin`) fonctionnelle
- [ ] **Onboarding terminé** : identité de l'établissement, classes, matières, périodes définies
- [ ] Création des comptes enseignants / élèves / parents (**Administration → Utilisateurs**) — possible aussi par **import en masse** (voir ci‑dessous)
- [ ] Activation et **permissions des modules** souhaités (**Administration → Modules**) — rappel : *installé ≠ activé*
- [ ] **Messagerie** activée si nécessaire (désactivée par défaut pour des raisons de sécurité)
- [ ] Tâche cron configurée (`cron/daily_maintenance.php`)
- [ ] `APP_DEBUG=false` et `APP_ENV=production` dans `.env`
- [ ] **HTTPS** configuré si accès depuis Internet (`SESSION_SECURE=true`)
- [ ] Branche de mise à jour vérifiée (**Administration → Système → Mises à jour**)
- [ ] Sauvegardes automatiques planifiées (chapitre 10)

> **Import en masse.** Listes d'élèves, professeurs, parents, classes, matières, notes et devoirs importables (CSV ou copier‑coller, en‑têtes au format Pronote FR) via **Administration → Système → Import/Export** (`admin/systeme/import_export.php`).

---

## 10. Sauvegardes

La maintenance quotidienne (chapitre 6) effectue déjà un **backup automatique de la base** dans `storage/backups/` avec rotation (`BACKUP_RETENTION`, défaut 5). Ces dumps internes sont chiffrés at-rest via `APP_KEY` (`BACKUP_ENCRYPT=true` par défaut) — ils ne sont donc **restaurables que tant que le `APP_KEY` correspondant existe** (voir la rotation de clé ci-dessous).

### Sauvegarde externe (mysqldump) — recommandée

Les backups internes vivent **sur le même serveur** que l'application : ils ne protègent pas d'une perte disque ni d'un effacement complet. Mettez en place une **sauvegarde externe** vers un autre support / une autre machine :

```bash
# Base de données (dump compressé, déposé hors du serveur applicatif idéalement)
mysqldump --single-transaction --quick --routines --triggers \
  -h 127.0.0.1 -u fronote_user -p nom_base | gzip > fronote_$(date +%Y%m%d).sql.gz

# Fichiers uploadés
tar -czf uploads_$(date +%Y%m%d).tar.gz /var/www/fronote/uploads/

# Restauration
gunzip < fronote_AAAAMMJJ.sql.gz | mysql -h 127.0.0.1 -u fronote_user -p nom_base
```

```cron
# Sauvegarde externe quotidienne à 3h (après la maintenance interne de 2h)
0 3 * * * mysqldump --single-transaction --quick -u fronote_user -pMOT_DE_PASSE nom_base | gzip > /sauvegardes/fronote_$(date +\%Y\%m\%d).sql.gz
```

> Les dumps `mysqldump` ci-dessus sont **en clair** : ils contiennent des données personnelles (dont des mineurs) et des hashes de mots de passe. Stockez-les sur un support chiffré / à accès restreint, ou chiffrez-les (`… | gpg -c > …`).

### Gestion et rotation de `APP_KEY`

`APP_KEY` (généré à l'installation, 64 hex) est la **clé maître** : il dérive (HKDF) les clés de chiffrement at-rest, signe les cookies, et chiffre les **backups internes**. Sa perte rend **irrécupérables** toutes les données chiffrées (backups internes inclus).

- **Copie hors-ligne obligatoire.** Conservez une copie de `APP_KEY` (et de `JWT_SECRET`) dans un coffre de secrets / hors du serveur. Sans elle, un disque mort = backups internes illisibles. Pour la générer manuellement : `php -r "echo bin2hex(random_bytes(32));"`.
- **`KEY_VERSION`.** Chaque valeur chiffrée est préfixée d'une version de schéma de clé (`API\Core\Encryption::KEY_VERSION`, actuellement `1`, format `version:nonce:ciphertext:tag`). Elle identifie l'algorithme/format de chiffrement, **pas** la valeur de `APP_KEY` : Fronote n'effectue **aucune** ré-encryption automatique lors d'un changement de `APP_KEY`.
- **Rotation de `APP_KEY` (procédure manuelle).** Changer `APP_KEY` invalide tout ce qui était chiffré avec l'ancienne clé. Avant de modifier la valeur :
  1. Faites une **sauvegarde externe en clair** (mysqldump ci-dessus) ou avec `BACKUP_ENCRYPT=false`, pour disposer d'un dump indépendant de la clé ;
  2. remplacez `APP_KEY` dans `.env` (conservez l'**ancienne** clé hors-ligne tant que d'anciens backups chiffrés doivent rester restaurables) ;
  3. videz le cache et invalidez les sessions (les cookies signés avec l'ancienne clé deviennent invalides → reconnexion).
- Une sauvegarde **interne chiffrée** ne se restaure qu'avec le `APP_KEY` (ou, en repli, le `JWT_SECRET`) **qui l'a produite** : archivez la clé en même temps que vos backups.

---

## 11. Problèmes courants

### L'assistant ne s'ouvre pas (403 « Accès refusé »)
→ Vous accédez depuis une IP publique. Connectez‑vous depuis le réseau local, ou ajoutez votre IP à `ALLOWED_INSTALL_IP` dans le `.env`.

### 403 « Installation déjà effectuée »
→ `install.lock` et un `.env` valide existent. Pour réinstaller, supprimez `install.lock` (⚠️ la base sera recréée). Si vous vouliez seulement réparer la config, sachez que l'installateur reste accessible uniquement si `.env` est introuvable/illisible.

### Erreur de connexion MySQL (étape 2)
→ Erreur **1045 / Access denied** : l'utilisateur MySQL n'a pas le droit de se connecter **depuis l'IP du serveur web**. L'assistant affiche le `CREATE USER … GRANT … FLUSH PRIVILEGES` exact à exécuter.
→ Erreur **2002 / 2006** : MySQL injoignable — service arrêté ou port filtré.

### « install.php.disabled‑… » après installation
→ Normal : l'installateur s'auto‑neutralise en fin de course. Sous Windows, si le rename a échoué, supprimez/renommez `install.php` manuellement.

### Pages blanches / erreurs 500
→ Activez temporairement `APP_DEBUG=true` (uniquement hors production), consultez `API/logs/` et les logs du serveur web, puis repassez à `false`.

### Un module affiche « table … doesn't exist » ou n'apparaît pas
→ Son schéma n'a pas été provisionné ou le module n'est pas synchronisé. **Administration → Modules → Synchroniser** ré‑exécute `provisionSql()` (les `install.sql` manquants). Vérifiez ensuite que le module est **activé** (les modules non essentiels sont désactivés par défaut).

### Notifications pas en temps réel
→ Le serveur WebSocket n'est pas démarré (les notifications fonctionnent quand même, avec un léger délai). `pm2 status` puis `pm2 logs fronote-ws`.

### Une mise à jour a échoué
→ **Administration → Système → Mises à jour** affiche le détail des étapes (git, schéma SQL, erreurs). Vérifiez que `git` est disponible (`GIT_BINARY`) et que la branche `GITHUB_BRANCH` est correcte. Le `.env` est préservé automatiquement.

### Je dois tout réinstaller
1. Supprimez `install.lock` (et restaurez `install.php` s'il a été renommé en `.disabled-…`).
2. Retournez sur `install.php`.
3. ⚠️ Si vous réutilisez le même nom de base, **toutes les données seront perdues** (l'assistant demande confirmation explicite).

---

## Annexe — version & dépendances

- **Version** : `version.json` → `4.0.0` (build 2026-08-11, codename *Étanche*).
- **Architecture** : PHP sans framework, conteneur DI maison (`API/bootstrap.php`, services via `app('clé')`), autoload PSR‑4 (`API\ → API/`, `Pronote\ → API/`, `Modules\ → modules/`).
- **Dépendance Composer** : `firebase/php-jwt ^7.0` ; extensions `sodium`, `json`, `zip`, `pdo`.
- **Schéma** : DDL déclaratif (`pronote.sql` + `modules/<m>/Database/install.sql` + `rgpd/Database/*.sql`) réconcilié de façon additive par `SchemaSyncService` ; migrations **de données versionnées** (`database/migrations/` + `MigrationRunner`) pour les cas non additifs. L'ancien système de migrations **par module** n'existe plus. Voir [docs/UPDATING.md](docs/UPDATING.md).
