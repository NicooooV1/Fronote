# Guide de déploiement

Ce guide couvre l'installation initiale de Fronote puis sa mise à jour **en un seul
bouton** (pull Git + réconciliation du schéma). Le schéma DDL est **déclaratif** (les
fichiers `*.sql` du dépôt), réconcilié de façon **additive** par `SchemaSyncService` ;
un système de migrations **de données versionnées** (`database/migrations/` +
`MigrationRunner`) couvre les cas non additifs. Guide de référence :
**[UPDATING.md](UPDATING.md)**.

> Version documentée : **4.0.0** (`version.json`). Tous les chemins sont relatifs à la
> racine du projet (ex. `/var/www/fronote`).

---

## 1. Pré-requis

| Logiciel | Version | Requis |
|----------|---------|--------|
| PHP | **8.0+** (`min_php` dans `version.json`) | Oui |
| MySQL / MariaDB | **MySQL 8.0+ / MariaDB 10.3+** | Oui |
| Apache | 2.4+ avec `mod_rewrite` | Oui |
| `git` | n'importe quelle version récente | Oui (pour la MAJ en un bouton) |
| Composer | 2.x | Pour `vendor/` (`firebase/php-jwt`) |
| Node.js | 18+ | Optionnel (serveur WebSocket) |

> **Stack de production de référence** (validée) : **Apache `mpm_event` + PHP-FPM 8.2** et
> **MariaDB 10.11 native**. La configuration générique décrite ci-dessous (Apache + MySQL 8 **ou**
> MariaDB ≥ 10.3) reste pleinement supportée ; PHP-FPM est recommandé pour la performance.

### Extensions PHP

Liste **exigée** par l'installateur (`install.php`, étape « Pré-requis ») et par
`composer.json` :

```
pdo  pdo_mysql  json  mbstring  session  sodium  zip  fileinfo
```

Extensions **recommandées** (l'installateur avertit mais ne bloque pas) :

```
intl  gd  curl
```

Vérification :

```bash
php -m | grep -iE "pdo_mysql|mbstring|json|sodium|zip|fileinfo|session"
```

> `ext-sodium` est **obligatoire** : il sert au chiffrement (`API/Core/Encryption.php`).
> Ce n'est pas `openssl`.

---

## 2. Installation

Deux chemins. L'assistant web est recommandé : il crée le cœur **et** le schéma de tous
les modules en une passe.

### Option A — Assistant web (recommandé)

1. Déployez les fichiers du projet (clone Git de préférence — voir § Mise à jour).
2. Installez les dépendances : `composer install --no-dev` à la racine.
3. Ouvrez `http://votre-domaine/install.php`.
4. Suivez l'assistant (5 étapes) :
   - **Pré-requis** : PHP, extensions, répertoires inscriptibles, fichiers présents.
   - **Base de données** : connexion testée en direct, création de la base si besoin.
   - **Application** : nom, environnement, paramètres de sécurité.
   - **Administrateur** : compte principal (rôle `administrateur`).
   - **Récapitulatif → exécution** : import de `pronote.sql` (cœur), puis
     `ModuleSDK::syncAll()` (manifestes) **et** `provisionSql()` pour **chaque** module
     (exécute son `modules/<m>/Database/install.sql`), écriture du `.env`, création
     atomique de `install.lock`.
5. À la première connexion admin, l'**onboarding obligatoire** se déclenche
   (`API/onboarding_gate.php` → `/modules/onboarding/index.php`) tant que
   l'établissement courant porte le code `'default'` : identité de l'établissement,
   classes, matières, périodes.

> ⚠️ L'installateur n'est accessible que depuis une **IP locale** et se bloque dès que
> `install.lock` existe **et** que `.env` est lisible. Pour relancer l'assistant,
> supprimez `install.lock`.

### Option B — Manuelle

```bash
# 1. Cloner le dépôt (le clone Git est requis pour la MAJ en un bouton)
git clone https://github.com/votre-org/fronote.git /var/www/fronote
cd /var/www/fronote
composer install --no-dev

# 2. Importer UNIQUEMENT le schéma du cœur
mysql -u root -p fronote < pronote.sql

# 3. Configurer l'environnement
cp .env.example .env
# Éditez .env (identifiants base, secrets, GITHUB_BRANCH…)

# 4. Permissions
chmod -R 755 /var/www/fronote
chmod -R 775 storage/ uploads/ logs/
chown -R www-data:www-data storage/ uploads/ logs/
```

> ⚠️ `pronote.sql` ne crée que les tables du **cœur**. Chaque module métier embarque son
> propre `modules/<m>/Database/install.sql`, provisionné par le SDK — **pas** par
> l'import brut de `pronote.sql`. Après un import manuel, ouvrez l'assistant web une fois,
> **ou** connectez-vous en admin et utilisez **Administration → Modules → Synchroniser**
> pour provisionner le schéma de tous les modules. Vous pouvez aussi déclencher la
> réconciliation déclarative via **Administration → Système → Mises à jour** (le pull Git
> appelle `SchemaSyncService`, qui crée les tables/colonnes manquantes). Ensuite seulement,
> créez le verrou :

```bash
date +%Y-%m-%d > install.lock
```

---

## 3. Configuration `.env`

Les clés ci-dessous sont celles **réellement lues** par
`API/Providers/ConfigServiceProvider.php`. N'inventez pas de variantes
(`DB_DATABASE`, `DB_USERNAME`, `WS_JWT_SECRET`… ne sont pas lues).

### Base de données

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=fronote
DB_USER=fronote_user
DB_PASS=mot_de_passe_securise
DB_CHARSET=utf8mb4
```

### Application

```env
APP_ENV=production          # production | staging | development
APP_DEBUG=false             # true affiche les traces — JAMAIS en production
APP_URL=https://fronote.example.com
APP_TIMEZONE=Europe/Paris
```

### Sécurité

```env
SESSION_LIFETIME=7200       # 2 h
CSRF_LIFETIME=3600          # 1 h
CSRF_MAX_TOKENS=10
MAX_LOGIN_ATTEMPTS=5
LOGIN_LOCKOUT_TIME=900
RATE_LIMIT_ATTEMPTS=5
RATE_LIMIT_DECAY=1
AUDIT_RETENTION_DAYS=180    # purge des audit logs (cron quotidien)
```

### Mise à jour (Git)

```env
GITHUB_BRANCH=main          # branche distante suivie par le bouton de MAJ
GIT_BINARY=git              # chemin de git s'il est absent du PATH d'Apache (ex. Windows)
```

> `GITHUB_BRANCH` et `GIT_BINARY` sont les **seules** clés lues par `UpdateService`.
> Si `git` est dans le PATH du process Apache/PHP, laissez `GIT_BINARY` vide.
> Les anciennes clés `GITHUB_WEBHOOK_SECRET` / `GITHUB_REPO` présentes dans
> `.env.example` ne sont **plus utilisées** (le webhook a été supprimé) et peuvent être
> ignorées/retirées.

### WebSocket (optionnel)

```env
JWT_SECRET=clé-secrète       # signé par PHP, vérifié par websocket/server.js
API_SECRET=secret-partagé-php-vers-ws
WS_URL=https://fronote.example.com:3000
```

### Sauvegardes

```env
BACKUP_RETENTION=5           # nombre de sauvegardes conservées par type (cron)
```

---

## 4. Configuration Apache

```apache
<VirtualHost *:443>
    ServerName fronote.example.com
    DocumentRoot /var/www/fronote

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/fronote.pem
    SSLCertificateKeyFile /etc/ssl/private/fronote.key

    <Directory /var/www/fronote>
        AllowOverride All
        Require all granted
    </Directory>

    # Bloquer les répertoires sensibles — mais garder API/endpoints/ accessible
    # (endpoints AJAX et health check y vivent).
    <DirectoryMatch "^/var/www/fronote/(storage|logs|cron|temp|vendor)">
        Require all denied
    </DirectoryMatch>
    <DirectoryMatch "^/var/www/fronote/API/(?!endpoints/)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

> ⚠️ Ne bloquez **pas** tout `/API/` : les endpoints AJAX et le health check
> (`/API/endpoints/health.php`) vivent sous `API/endpoints/` et doivent rester
> joignables. La règle ci-dessus n'autorise que ce sous-dossier. (Il n'y a **plus** de
> webhook de mise à jour ni de dossier `migrations/` à exposer.)

Activez `mod_rewrite` et `ssl` :

```bash
sudo a2enmod rewrite ssl
sudo systemctl restart apache2
```

> Le process Apache/PHP doit pouvoir exécuter `git` dans la racine du projet pour la
> mise à jour en un bouton. En cas de PATH restreint (FPM, Windows…), renseignez
> `GIT_BINARY` dans `.env`. Le dépôt cloné (`.git/`) doit appartenir à l'utilisateur
> Apache pour que `git reset --hard` réussisse.

---

## 5. Serveur WebSocket (optionnel)

### Avec PM2

```bash
cd websocket/
npm install
pm2 start server.js --name fronote-ws
pm2 save
pm2 startup
```

### Avec systemd

`/etc/systemd/system/fronote-ws.service` :

```ini
[Unit]
Description=Fronote WebSocket Server
After=network.target

[Service]
ExecStart=/usr/bin/node /var/www/fronote/websocket/server.js
WorkingDirectory=/var/www/fronote/websocket
Restart=always
User=www-data
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable fronote-ws
sudo systemctl start fronote-ws
```

> Le serveur Node lit `JWT_SECRET` et `API_SECRET` (cf. `websocket/server.js`).

---

## 6. Tâches planifiées (cron)

Fronote regroupe **toute** la maintenance dans **un seul** script :
`cron/daily_maintenance.php`. Il n'existe **aucun** autre cron (pas de
`hourly_maintenance.php`, pas de crons métier par module).

```cron
# Maintenance quotidienne à 02:00
0 2 * * * php /var/www/fronote/cron/daily_maintenance.php >> /var/www/fronote/API/logs/cron.log 2>&1
```

### Ce que fait `daily_maintenance.php` (02:00)

Chaque tâche est *best-effort* : un échec n'interrompt jamais les suivantes, et la
sortie est journalisée.

- **Sauvegarde complète** (`app('backup')->createFullBackup()` = base + uploads),
  avec copie hors-hôte optionnelle si `BACKUP_OFFSITE_DIR` est configuré ;
- **Rotation des sauvegardes** : conserve les `BACKUP_RETENTION` plus récentes par
  type (défaut 5) ;
- **Purge de l'audit** : entrées plus vieilles que `AUDIT_RETENTION_DAYS` (défaut 180) ;
- **File d'e-mails** : envoi des e-mails en attente puis purge des entrées traitées
  (`email_log` + corps `storage/email_queue/`) ;
- **GC du cache** applicatif expiré ;
- **Nettoyage de `storage/tmp`** (fichiers > 24 h) ;
- **Nettoyage de `storage/quarantine`** (reliquats > 30 jours).

---

## 7. Mise à jour de Fronote — **un seul bouton**

La mise à jour ne se fait **pas** par téléchargement d'archive : il n'y a **aucune** des
choses suivantes : `scripts/update.php`, `scripts/check_update.php`,
`API/endpoints/webhook_update.php`, cron de vérification, GitHub Releases / zip. Le
**dépôt Git est la source**. La réconciliation du schéma (déclaratif additif +
migrations versionnées) est jouée automatiquement par le bouton — voir ci-dessous.

### Interface

**Administration → Système → Mises à jour** (`admin/systeme/update.php`, rôle
`administrateur`). La page affiche la version courante, la branche suivie et l'état de
`git`. Deux actions :

- **Vérifier** → `app('updates')->checkForUpdate()` : `git fetch` puis compare
  `HEAD` à `origin/<branche>` et liste les commits en attente. Aucun changement appliqué.
- **Mettre à jour maintenant** → `app('updates')->applyUpdate()`.

### Ce que fait `applyUpdate()` (`API/Services/UpdateService.php`)

Flux synchrone, dans l'ordre :

1. **Garde-fous** : `git` disponible ; la branche servie **doit** être `GITHUB_BRANCH` ;
   l'arbre de travail doit être **propre** (un `reset --hard` écraserait les modifs non
   commitées) ; le HEAD git doit être lisible (sinon rollback impossible → refus).
2. **Filet de sécurité** : passage en **mode maintenance** (refus si indisponible),
   **sauvegarde de la base** (`app('backup')->createDatabaseBackup()`), capture du HEAD
   courant ; le contenu de `.env` est aussi gardé en mémoire.
3. `git fetch origin <branche>`.
4. **`git reset --hard origin/<branche>`** — le serveur reflète **exactement** le dépôt
   distant. ⚠️ Toute modification locale non commitée est **écrasée**. `.env` est restauré
   s'il avait disparu.
5. **`SchemaSyncService::sync()`** — réconciliation déclarative additive du schéma (voir
   ci-dessous).
6. **`MigrationRunner::migrate()`** — joue les migrations **versionnées** en attente
   (`database/migrations/`, journal `schema_migrations`).
7. **Toute erreur de schéma OU de migration ⇒ ROLLBACK COMPLET** : base restaurée depuis
   la sauvegarde **et** code remis au HEAD précédent (`git reset --hard <ancien HEAD>`),
   puis sortie de maintenance.
8. `app('module_sdk')->syncAll()` — re-synchronise les manifestes des modules
   (permissions, widgets, routes…).
9. (Plus d'étape de synchro de rôles : le catalogue vit en code `RoleCatalog` ; les déviations de permissions sont dans la table globale `rbac_grants`, éditées côté plateforme.)
10. `app('cache')->flush()` — vide le cache applicatif.
11. **Sortie du mode maintenance** et relecture de `version.json`.

La page affiche le détail des étapes et `ancienne_version → nouvelle_version`. Le
succès est conditionné à l'absence d'erreur de schéma **ou** de migration (sinon la mise
à jour est intégralement annulée et restaurée).

### `SchemaSyncService` — réconciliation déclarative additive

`API/Services/SchemaSyncService.php` rend la base conforme aux fichiers `*.sql` du
dépôt, de façon **non destructive et idempotente** :

- **Source du schéma désiré** : `pronote.sql` (cœur) + tous les
  `modules/*/Database/install.sql` + `rgpd/Database/*.sql`. Les définitions de colonnes
  sont fusionnées en cas de table déclarée à plusieurs endroits.
- **Table absente** → `CREATE TABLE` (le statement complet du `.sql`).
- **Table présente** → `ADD COLUMN` pour chaque colonne **manquante** uniquement.
- **Jamais** de `DROP`, jamais de changement de type, jamais de suppression de
  colonne/table, **ni d'index/FK sur une table existante** — c'est de l'« ajout
  seulement ». Les `FOREIGN_KEY_CHECKS` sont désactivés le temps de la passe (ordre
  d'activation des modules arbitraire).

Conséquence : après un commit qui **ajoute une table ou une colonne**, **aucune action
manuelle** sur la base n'est nécessaire — le bouton suffit.

### `MigrationRunner` — migrations versionnées (cas non additifs)

Pour ce que `SchemaSyncService` ne sait **pas** exprimer (renommage, changement de type,
index/FK sur une base existante, backfill de données, suppression contrôlée), on écrit
une **migration versionnée** : `database/migrations/<horodatage>_<nom>.php` (objet
`up(\PDO)`/`down(\PDO)`, idempotent). `MigrationRunner` les joue dans l'ordre
lexicographique juste après `SchemaSyncService`, en tenant le journal `schema_migrations`
(une migration ne rejoue jamais). L'ancien système de migrations **par module** a
disparu. Le workflow complet est décrit dans **[UPDATING.md](UPDATING.md)**.

### Procédure recommandée pour une montée de version

1. (Optionnel) Activer le **mode maintenance** (§ 8).
2. **Sauvegarder** la base et les fichiers (§ 9).
3. **Administration → Système → Mises à jour → Mettre à jour maintenant**.
4. Vérifier le détail des étapes (schéma, modules, cache) et la nouvelle version.
5. Vérifier le health check : `GET /API/endpoints/health.php`.
6. Désactiver le mode maintenance.

### Configuration

- `GITHUB_BRANCH` (défaut `main`) : branche distante suivie. Modifiable depuis la page
  (formulaire « Branche à suivre ») ou dans `.env`.
- `GIT_BINARY` : chemin de `git` si absent du PATH du process serveur.

---

## 8. Mode maintenance

### Via l'admin

`admin/systeme/maintenance.php` : active/désactive le mode, définit un message
personnalisé et liste blanche d'IP.

### Via CLI

Créez `storage/maintenance.json` :

```json
{
    "active": true,
    "message": "Mise a jour en cours. Retour prevu dans 30 minutes.",
    "allowed_ips": ["192.168.1.100"],
    "eta_minutes": 30
}
```

Supprimez le fichier ou passez `"active": false` pour désactiver.

---

## 9. Sauvegardes

### Automatique (cron quotidien)

`BackupService::createDatabaseBackup()` (`API/Services/BackupService.php`) génère un
dump SQL dans `storage/backups/` (fichier `backup_db_<timestamp>.sql`). **Le dump est
produit en PHP pur** via PDO (`SHOW CREATE TABLE` + `SELECT` par curseur, écriture par
lots pour ne pas charger toute la table en RAM) — il **n'appelle pas** `mysqldump` et ne
dépend donc d'aucun binaire externe. La rotation (`cleanup()`) conserve les
`BACKUP_RETENTION` plus récents par type.

Méthodes utiles : `createUploadsBackup()`, `createFullBackup()`, `listBackups()`,
`restoreDatabase()`, `deleteBackup()`.

### Manuelle

```bash
# Base de données (équivalent externe, nécessite le client mysqldump)
mysqldump -u fronote_user -p fronote > backup_$(date +%Y%m%d).sql

# Fichiers
tar -czf fronote_files_$(date +%Y%m%d).tar.gz \
    --exclude='storage/backups' \
    --exclude='storage/tmp' \
    --exclude='uploads/tmp' \
    /var/www/fronote
```

> Avant une mise à jour (`git reset --hard`), une sauvegarde de la base est la sécurité
> minimale. `.env` est protégé par `.gitignore` **et** restauré par `applyUpdate()`,
> mais sauvegardez-le aussi par prudence.

---

## 10. Supervision

### Endpoint de santé

```
GET /API/endpoints/health.php
```

Renvoie un JSON sur l'état des sous-systèmes (base, disque, cache, etc.).

### Tableau de bord admin

`admin/systeme/monitoring.php` :
- État de santé global
- Sessions actives
- Taille de la base et nombre de tables
- Usage disque
- Statut des extensions PHP
- Vue des feature flags

### Fichiers de logs

```
API/logs/
├── cron.log            ← sortie de la maintenance quotidienne
├── error.log           ← erreurs PHP (production)
└── audit.log           ← événements d'audit de sécurité
```

---

## 11. HTTPS

En production, Fronote applique :
- En-tête HSTS (`Strict-Transport-Security`) et CSP (`templates/shared_header.php`)
- Cookies de session `Secure`
- WSS pour le WebSocket

Assurez un certificat valide et auto-renouvelé (Let's Encrypt / certbot).

---

## 12. Optimisation des performances

### PHP

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0    ; 1 en développement
memory_limit=256M
upload_max_filesize=10M
post_max_size=12M
```

> Si `opcache.validate_timestamps=0`, **rechargez PHP-FPM / Apache après une mise à
> jour** (le `git reset --hard` change les fichiers, mais OPcache garde l'ancienne
> version compilée).

### MySQL

```ini
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
max_connections=100
```

### Apache (compression / cache des assets)

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/css application/javascript
</IfModule>

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
</IfModule>
```
