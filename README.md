# Fronote — Système de gestion scolaire

![PHP 8+](https://img.shields.io/badge/PHP-8%2B-blue) ![MariaDB / MySQL](https://img.shields.io/badge/MariaDB_10.11_%2F_MySQL_8-orange) ![Version](https://img.shields.io/badge/version-4.0.0_%C3%89tanche-green) ![Licence](https://img.shields.io/badge/licence-proprietary-lightgrey) ![i18n](https://img.shields.io/badge/i18n-8%20locales-blueviolet) ![Modules](https://img.shields.io/badge/modules-63-brightgreen)

> Fronote est une application **PHP pure, sans framework**, de gestion d'établissement scolaire (notes, absences, emploi du temps, messagerie, vie scolaire, facturation, etc.). Architecture modulaire (63 modules découverts dynamiquement), conteneur d'injection de dépendances maison, multi-établissement, design system à thèmes et internationalisation (8 langues).
>
> Version courante : **4.0.0** (« Étanche », build 2026-08-11). Voir `version.json`.

---

## Sommaire

- [Aperçu](#aperçu)
- [Pile technique](#pile-technique)
- [Architecture](#architecture)
  - [Conteneur de services `app()`](#conteneur-de-services-app)
  - [Structure des dossiers](#structure-des-dossiers)
  - [Layout : topbar horizontale](#layout--topbar-horizontale)
  - [Multi-établissement](#multi-établissement)
- [Démarrage rapide](#démarrage-rapide)
- [Mise à jour : un seul bouton](#mise-à-jour--un-seul-bouton)
- [Base de données : schéma déclaratif](#base-de-données--schéma-déclaratif)
- [Rôles & authentification](#rôles--authentification)
- [Internationalisation (i18n)](#internationalisation-i18n)
- [Modules](#modules)
- [Fonctionnalités par rôle](#fonctionnalités-par-rôle)
- [Configuration `.env`](#configuration-env)
- [Documentation détaillée](#documentation-détaillée)

---

## Aperçu

Fronote couvre la gestion quotidienne d'un établissement (collège, lycée, école) :

- **Pédagogie** : notes, bulletins, compétences, cahier de textes & devoirs, examens, emploi du temps.
- **Vie scolaire** : absences, retards, appel, discipline, infirmerie, signalements, besoins particuliers.
- **Communication** : messagerie temps réel, annonces, réunions parents-profs, notifications.
- **Établissement & logistique** : trombinoscope, bibliothèque, orientation, inscriptions, cantine, internat, transports, facturation, stages, clubs.
- **Administration & système** : gestion des utilisateurs, modules, permissions (RBAC), audit/RGPD, import en masse, marketplace de modules, mises à jour, sauvegardes.

Tout est livré dans un seul dépôt, installé via un assistant web (`install.php`), et mis à jour d'un seul bouton depuis l'interface d'administration.

---

## Pile technique

| Élément | Détail |
|---------|--------|
| Langage | **PHP ≥ 8.0** (`composer.json` : `"php": ">=8.0"`) |
| Base de données | **MySQL ≥ 8.0** ou **MariaDB ≥ 10.3**, via **PDO** (`ERRMODE_EXCEPTION`, `utf8mb4`) |
| Framework | **Aucun** — conteneur DI maison (`API/Core/Application`), providers, facades |
| Autoload | PSR-4 (`composer.json`) : `API\` → `API/`, `Pronote\` → `API/`, `Modules\` → `modules/` |
| Dépendance Composer | `firebase/php-jwt ^7.0` (JWT WebSocket) |
| Extensions PHP requises | `ext-sodium` (signature `.fmod` marketplace), `ext-json`, `ext-zip`, `ext-pdo` |
| Temps réel | Serveur Node.js (`websocket/server.js`, Socket.IO) — optionnel, fallback HTTP |
| Front | HTML/CSS/JS sans build — design tokens CSS + thèmes (classic / glass) |
| i18n | 8 locales : `fr en es de ru nl ar th` (`lang/<locale>/<domaine>.json`) |

L'autoloader `Modules\` (dans `API/bootstrap.php`) convertit le PascalCase du namespace en snake_case de répertoire : `Modules\EmploiDuTemps\Services\Foo` → `modules/emploi_du_temps/Services/Foo.php` (indispensable sous Linux, sensible à la casse).

---

## Architecture

```
Navigateur ── HTTP/HTTPS ──► Pages PHP (modules + essentiels racine)
     │                            │ require API/bootstrap.php
     │ WebSocket (Socket.IO)       ▼
     │                       ┌───────────────────────────────────────┐
     └────────────────────► │  Application (conteneur DI maison)     │
       websocket/    │  providers → services via app('clé')  │
       server.js (Node)     │  facades : Auth, DB, CSRF, Log…        │
                            └────────────────┬──────────────────────┘
                                             │ PDO (utf8mb4)
                                             ▼
                                   MySQL 8 / MariaDB 10.3
                                   pronote.sql (cœur)
                                   + modules/<m>/Database/install.sql
```

Le bootstrap (`API/bootstrap.php`) : charge l'autoloader Composer (ou un fallback PSR-4 manuel), lit le `.env` (`EnvLoader`), force `display_errors=0` en production, démarre une session sécurisée scopée par instance, instancie l'`Application` et enregistre les providers, puis amorce les modules actifs et le contexte d'établissement.

### Conteneur de services `app()`

Les services sont résolus par clé via le helper global `app('clé')`. Ils sont enregistrés dans `API/bootstrap.php` et les `API/Providers/*ServiceProvider.php`. Une trentaine de services sont disponibles :

| Clé | Rôle |
|-----|------|
| `db` | Connexion PDO (`getConnection()`) |
| `config` | Accès configuration |
| `auth` | Authentification (login/logout/session) |
| `rbac` | Permissions par rôle/module |
| `csrf` | Jetons CSRF (token bucket) |
| `rate_limiter` | Limitation de débit (IP + identifiant) |
| `validator` / `password_policy` | Validation entrée / politique de mot de passe |
| `firewall` | Pare-feu IP applicatif |
| `encryption` | Chiffrement AES-256-GCM (si `APP_KEY` configuré) |
| `translator` | i18n (`__('domaine.clé')`) |
| `etablissement` / `super_admin` | Établissement courant / gestion multi-établissement |
| `user` | Service utilisateurs |
| `email` | Envoi de mails (SMTP) |
| `pdf` | Génération PDF (bulletins, exports) |
| `modules` / `module_sdk` | Service module + SDK (découverte, sync, provisioning) |
| `marketplace` | Marketplace de modules (`.fmod`) |
| `features` | Feature flags |
| `themes` | Thèmes applicatifs |
| `hooks` | Système d'événements/hooks pour modules |
| `queue` | File de tâches générique |
| `cache` / `client_cache` | Cache fichier/redis / cache client (session + cookies signés) |
| `log` | Logger structuré avec rotation |
| `audit` | Journal d'audit (RGPD) |
| `backup` | Sauvegardes |
| `updates` | Mise à jour Git (un bouton) |
| `maintenance` | Mode maintenance (fichier) |
| `health` | Health checks |
| `quarantine` | Quarantaine sécurité marketplace |
| `admin_dashboard` / `classes` | Services scolaires (tableau de bord admin, classes) |

```php
$pdo     = app('db')->getConnection();
$user    = app('auth')->user();
$modules = app('modules');
app('audit')->log('note.created', ...);
__('notes.title');           // i18n
```

> **Facades** disponibles dans `API/Core/Facades/` (`Auth`, `DB`, `CSRF`, `Log`…) en alternative statique au helper `app()`.

### Structure des dossiers

Les **modules métier** vivent sous `modules/<clé>/` ; les **composants essentiels** restent à la racine. `ModuleSDK::discover()` scanne **les deux** emplacements (`modules/*/module.json` **et** `*/module.json` à la racine).

```
Pronote/
├── API/                 ← Cœur : bootstrap, conteneur, services, sécurité, endpoints
│   ├── bootstrap.php    ← Point d'entrée du cœur (require par toute page)
│   ├── core.php         ← Bootstrap + helpers
│   ├── module_boot.php  ← Raccourci page module (core + requireAuth + gates)
│   ├── onboarding_gate.php
│   ├── Core/  Auth/  Security/  Services/  Providers/  Middleware/  endpoints/  Legacy/
│
├── modules/             ← 63 modules métier (un dossier par module, module.json)
│   ├── notes/  absences/  agenda/  messagerie/  bulletins/  emploi_du_temps/ …
│   └── <clé>/
│       ├── module.json          ← Manifeste (clé, nom, icône, catégorie, routes, permissions, widgets)
│       ├── <clé>.php            ← Page principale (routes.main)
│       ├── Database/install.sql ← Schéma final complet (CREATE TABLE IF NOT EXISTS)
│       ├── includes/  assets/  lang/
│
├── templates/           ← shared_header / shared_topbar / shared_topbar_nav / shared_footer
├── assets/              ← CSS/JS globaux (base, tokens, components, themes, topbar)
├── lang/<locale>/       ← Traductions JSON (fr en es de ru nl ar th)
│
│   ── Essentiels à la racine (avec leur propre module.json) ──
├── accueil/             ← Tableau de bord d'accueil (core)
├── admin/               ← Panneau d'administration (core)
├── parametres/          ← Préférences utilisateur (core)
├── rgpd/  securite/  tutorat/
│
├── login/  cron/  database/  websocket/
├── install.php          ← Assistant d'installation
├── pronote.sql          ← Schéma cœur (utilisateurs, classes, périodes, modules_config…)
├── version.json  composer.json  .env.example
```

> **`$rootPrefix`.** `templates/shared_header.php` le calcule automatiquement depuis la profondeur réelle du script demandé. Inutile de le coder en dur : les chemins CSS/JS/liens racine sont corrects à n'importe quelle profondeur (`modules/<m>/…` comme racine).

### Layout : topbar horizontale

L'interface utilise une **barre de navigation horizontale (topbar)** — il **n'y a plus de sidebar**.

Une page assemble trois templates partagés :

```php
$pageTitle  = 'Mon module';
$activePage = 'mon_module';
require_once __DIR__ . '/../../API/module_boot.php';   // core + requireAuth + $user/$pdo/$rootPrefix + gates

include __DIR__ . '/../../templates/shared_header.php';       // <head>, CSP+nonce, CSRF, thème, CSS unifié
include __DIR__ . '/../../templates/shared_topbar.php';       // ouvre .content-container, avatar, $pageBack
include __DIR__ . '/../../templates/shared_topbar_nav.php';   // navigation (modules activés filtrés par rôle)
?>
<!-- contenu HTML du module -->
<?php include __DIR__ . '/../../templates/shared_footer.php'; // ferme .content-container + scripts globaux ?>
```

Le CSS est unifié dans `assets/css/` (`base`, `tokens`, `components`, `theme-classic`, `theme-glass`, `topbar`) et injecté par `shared_header` via `$rootPrefix`. Deux thèmes : **classic** (défaut) et **glass** (surcouche glassmorphism). Le header émet aussi le **nonce CSP**, le **token CSRF** et les **headers de sécurité**. Un bouton de retour est disponible via `$pageBack` dans la topbar.

### Multi-établissement

Une même installation peut héberger plusieurs établissements.

- L'établissement courant est résolu par `\API\Core\EstablishmentContext::id()`.
- Les tables métier portent une colonne `etablissement_id` ; les services filtrent leurs requêtes dessus — un établissement ne voit jamais les données d'un autre. L'authentification est elle-même scopée (`UserProvider`).
- Le rôle **`super_admin`** peut gérer plusieurs établissements (`admin/etablissement/`).
- Un **onboarding obligatoire** (`API/onboarding_gate.php`, inclus par `module_boot.php`) force la configuration tant que l'établissement courant porte encore le code `'default'`. Une fois configuré, une seconde garde force la redéfinition des périodes si l'année scolaire est écoulée.

---

## Démarrage rapide

### Prérequis

- PHP ≥ 8.0 avec extensions `pdo`, `json`, `zip`, `sodium`
- MySQL ≥ 8.0 / MariaDB ≥ 10.3
- Composer
- (optionnel) Node.js pour le serveur WebSocket temps réel

### Installation

```bash
git clone <repo> Pronote
cd Pronote
composer install --optimize-autoloader
# Ouvrir http://localhost/Pronote/install.php dans le navigateur
```

L'assistant `install.php` est un wizard en **5 étapes** (l'accès est restreint au réseau local par défaut ; `ALLOWED_INSTALL_IP` autorise une IP externe) :

1. **Pré-requis** — version PHP, extensions, répertoires inscriptibles, fichiers présents.
2. **Base de données** — connexion testée en temps réel.
3. **Application** — nom, environnement, paramètres de sécurité.
4. **Administrateur** — compte principal (mot de passe : ≥ 12 caractères, majuscule, minuscule, chiffre, caractère spécial).
5. **Récapitulatif → exécution** — crée la base, écrit le `.env`, **importe `pronote.sql`** (schéma cœur), crée le compte admin (`identifiant: admin`, bcrypt cost 12), puis **synchronise et provisionne tous les modules** (`ModuleSDK::syncAll()` + `provisionSql()` qui exécute chaque `install.sql`), et écrit `install.lock`.

> La configuration de l'établissement (identité, classes, matières, périodes) n'est **pas** faite par l'installateur : elle se déroule au **premier login admin** via le wizard d'onboarding tant que l'établissement porte le code `'default'`.

> **Réinstaller :** supprimer `install.lock`. L'installateur reste accessible si `install.lock` existe mais que `.env` est manquant/illisible (mode réparation).

---

## Mise à jour : un seul bouton

La mise à jour se fait depuis **`admin/systeme/update.php`** — un unique bouton. Aucun zip, aucune release : **le dépôt Git EST la source**. Propriété de sûreté maîtresse : **toute erreur de schéma ou de migration annule intégralement la mise à jour** (base **et** code restaurés).

`app('updates')->applyUpdate()` (`API\Services\UpdateService`) enchaîne :

1. **Garde-fous** : branche servie = `GITHUB_BRANCH`, arbre de travail propre, HEAD git lisible — sinon la mise à jour est refusée.
2. **Filet de sécurité** : passage en **mode maintenance**, **sauvegarde de la base**, capture du HEAD courant.
3. `git fetch origin <branche>` puis `git reset --hard origin/<branche>` — le serveur reflète exactement le dépôt (le `.env` est sauvegardé/restauré par précaution).
4. **`SchemaSyncService::sync()`** — réconciliation **déclarative et additive** : lit `pronote.sql` + `modules/*/Database/install.sql` + `rgpd/Database/*.sql`, **crée les tables manquantes** (`CREATE TABLE`) et **ajoute les colonnes manquantes** (`ADD COLUMN`). Jamais de `DROP`, ni de changement de type, ni d'index/FK sur table existante.
5. **`MigrationRunner::migrate()`** — joue les migrations **versionnées** en attente (`database/migrations/`, journal `schema_migrations`) pour les cas non additifs. En cas d'erreur (schéma **ou** migration) ⇒ **ROLLBACK COMPLET**.
6. `app('module_sdk')->syncAll()` (manifestes) → `RoleSync::sync()` (catalogue RBAC) → vidage du cache → **sortie de maintenance**.

Détails et workflow d'évolution de schéma : **[docs/UPDATING.md](docs/UPDATING.md)**.

Configuration `.env` :

| Variable | Défaut | Rôle |
|----------|--------|------|
| `GITHUB_BRANCH` | `main` | Branche Git suivie |
| `GIT_BINARY` | `git` | Chemin de `git` si absent du `PATH` (ex. Windows) |

---

## Base de données : schéma déclaratif

Le **DDL est déclaratif** et réconcilié de façon **additive** par `SchemaSyncService` ; un système de **migrations de données versionnées** couvre les cas non additifs. Guide de référence : **[docs/UPDATING.md](docs/UPDATING.md)**.

> ⚠️ **L'ancien système de migrations PAR MODULE a été retiré** : plus de `ModuleSDK::migrate`, plus de tables `module_migrations`/`core_migrations`, plus de dossiers `modules/*/Database/migrations`, plus de `CoreMigrator` ni `scripts/migrate.php`, plus de clé `migrations` dans `module.json`. En revanche, un système de migrations **versionnées au niveau du dépôt** (`database/migrations/` + `MigrationRunner`, journal `schema_migrations`) subsiste pour ce que le déclaratif ne sait pas exprimer.

Le schéma désiré est **déclaratif** :

- **Cœur** : `pronote.sql` crée le socle (`administrateurs`, `eleves`, `professeurs`, `parents`, `classes`, `matieres`, `periodes`, `etablissements`, `modules_config`, sécurité, file de tâches…).
- **Par module** : `modules/<clé>/Database/install.sql` — schéma **final complet**, idempotent (`CREATE TABLE IF NOT EXISTS`), avec la colonne `etablissement_id`.
- **RGPD** : `rgpd/Database/*.sql`.
- **Provisionnement** : `ModuleSDK::provisionSql($clé)` exécute **uniquement** `install.sql` (FK désactivées le temps de l'exécution) — à l'installation et à chaque activation de module.
- **Réconciliation** : à chaque mise à jour, `SchemaSyncService` rend la base conforme aux `.sql` (**`CREATE TABLE` + `ADD COLUMN` uniquement** — jamais de `DROP`, de changement de type, ni d'index/FK sur table existante), puis `MigrationRunner` joue les migrations versionnées en attente.

> **Ajouter** une table/colonne = éditer le `install.sql` du module (ou `pronote.sql` pour le socle) ; `SchemaSyncService` applique le delta. Un changement **non additif** (rename, retype, index/FK sur base existante, backfill) exige une **migration versionnée** dans `database/migrations/` (`up()/down()`, idempotente). Penser à bumper `version.json`.

> **Installé ≠ activé.** Tous les modules découverts sont enregistrés dans `modules_config`. Seuls les modules `core: true` sont activés d'office ; les autres restent désactivés (`enabled = 0`) et invisibles en navigation jusqu'à activation par l'admin (`admin/modules/`). L'activation appelle `provisionSql()` puis bascule `enabled = 1`. Modules core actuels : `accueil`, `admin`, `parametres`, `notifications`, `profil`, `support`, `onboarding`.

---

## Rôles & authentification

| Rôle | Accès type |
|------|-----------|
| `administrateur` | Administration complète (utilisateurs, modules, permissions, audit, système) |
| `professeur` | Notes (saisie), cahier de textes, agenda, appel, absences, messagerie |
| `vie_scolaire` | Absences, discipline, reporting, infirmerie, internat |
| `eleve` | Consultation notes, cahier de textes & rendus, agenda, messagerie, ressources |
| `parent` | Notes/absences des enfants, justificatifs, réunions, messagerie |
| `super_admin` | Gestion multi-établissement (transverse) |

- **Identifiant utilisateur** : login au format `nom.prenom`.
- **Mots de passe** : `bcrypt` cost 12.
- **Anti-bruteforce** : rate-limit sur IP **et** identifiant.
- **CSRF** : `csrf_verify()` / `app('csrf')->validate($token)` ; champ caché et meta générés par `shared_header`.
- **Headers de sécurité + CSP** (avec nonce) injectés par `shared_header`. `display_errors` forcé à `0` en production.

La visibilité des modules par rôle est éditable sans redéploiement via la colonne `roles_autorises` (JSON) de `modules_config` (`admin/modules/configure.php`), prioritaire sur les défauts du manifeste.

---

## Internationalisation (i18n)

```php
echo __('notes.titre');                       // "Notes"
echo __('messagerie.bonjour', ['nom' => $n]); // interpolation de {nom}
```

- Service : `app('translator')`. Fichiers : `lang/<locale>/<domaine>.json`. 8 locales : `fr en es de ru nl ar th`.
- ⚠️ **Une clé absente renvoie la clé elle-même.** Le 2ᵉ argument `$params` est l'**interpolation**, **pas** une valeur par défaut. Toute nouvelle chaîne affichée doit donc avoir sa clé déclarée dans `lang/`.

---

## Modules

63 modules métier sous `modules/<clé>/`, plus les essentiels à la racine. Catégories valides (`ModuleSDK::VALID_CATEGORIES`) : `navigation`, `scolaire`, `vie_scolaire`, `communication`, `etablissement`, `logistique`, `outils`, `administration`, `systeme`, `sante`, `custom`.

| Domaine | Modules (extrait) |
|---------|-------------------|
| **Scolaire** | `notes`, `bulletins`, `competences`, `cahierdetextes`, `devoirs`, `emploi_du_temps`, `examens`, `evaluations`, `agenda`, `orientation`, `conseil_classe`, `parcours_educatifs`, `projets_pedagogiques`, `intelligence` |
| **Vie scolaire** | `absences`, `appel`, `discipline`, `vie_scolaire`, `besoins`, `signalements` |
| **Communication** | `messagerie`, `annonces`, `reunions`, `notifications`, `echanges`, `enquetes` |
| **Établissement** | `trombinoscope`, `inscriptions`, `clubs`, `vie_associative`, `formations`, `bourses`, `diplomes` |
| **Logistique / Services** | `cantine`, `garderie`, `periscolaire`, `internat`, `transports`, `stages`, `salles`, `personnel`, `facturation`, `inventaire`, `mediatheque`, `bibliotheque` |
| **Santé** | `infirmerie` |
| **Outils / Système** | `reporting`, `tableau_de_bord`, `dashboard`, `documents`, `ressources`, `archivage`, `support`, `marketplace`, `accessibilite`, `hello_world` |
| **Essentiels (racine, core)** | `accueil`, `admin`, `parametres`, `rgpd`, `securite`, `tutorat`, `onboarding`, `profil`, `notifications` |

> Créer un module : ajouter un dossier `modules/<clé>/` avec son `module.json` (clé, nom multilingue, icône, `category` valide, `core`, `routes.main`, `database.install`, `permissions`), puis **Admin → Modules → Synchroniser**. Détails dans [docs/module-sdk.md](docs/module-sdk.md).

---

## Fonctionnalités par rôle

- **Administrateur** — gestion des utilisateurs, **import en masse** (`admin/systeme/import_export.php` → `API/Services/Import/BulkImporter` : CSV ou copier-coller, en-têtes Pronote FR, entités élèves/profs/parents/classes/matières/notes/devoirs), modules & permissions (RBAC), audit/RGPD, sauvegardes, maintenance, marketplace, mises à jour, support/tickets.
- **Professeur** — saisie des notes et appréciations, cahier de textes & devoirs (création, correction des rendus), appel, agenda, absences, messagerie.
- **Vie scolaire** — suivi des absences/retards, justificatifs, discipline, infirmerie, internat, reporting.
- **Élève** — consultation des notes, devoirs et rendus en ligne, emploi du temps, agenda, messagerie, ressources.
- **Parent** — suivi des notes et absences des enfants, dépôt de justificatifs, réunions parents-profs, messagerie.

> **Récents (juin 2026)** : import en masse ; refonte des tickets support (fil multi-messages `support_ticket_messages` + notifications + SLA) ; périodes auto-septembre (`PeriodeService::defaultPeriodes`) ; RGPD par identifiant ; bouton retour (`$pageBack`).

---

## Configuration `.env`

Copier `.env.example` vers `.env` (l'installateur le génère). Variables clés :

```env
# Base de données
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fronote
DB_USER=fronote_user
DB_PASS=secret
DB_CHARSET=utf8mb4

# Application
APP_ENV=production            # production | development
APP_DEBUG=false               # en prod, display_errors est forcé à 0

# Sécurité
SESSION_NAME=fronote_session  # sinon scopé par instance (fronote_<INSTANCE_ID>)
TRUST_PROXY_HEADERS=false     # honore X-Forwarded-Proto/SSL uniquement si true
ALLOWED_INSTALL_IP=           # IP externe autorisée pour install.php (vide = réseau local)
APP_KEY=                      # active le service de chiffrement (encryption)

# Mise à jour (un bouton)
GITHUB_BRANCH=main            # branche Git suivie par UpdateService
GIT_BINARY=                   # chemin de git si hors PATH (ex. Windows)

# WebSocket / JWT (temps réel optionnel)
JWT_SECRET=changez-moi
WEBSOCKET_URL=http://localhost:3000
WEBSOCKET_API_SECRET=secret-partage-node

# Chemins
LOGS_PATH=
UPLOADS_PATH=
```

---

## Documentation détaillée

| Document | Sujet |
|----------|-------|
| [INSTALL.md](INSTALL.md) | Guide d'installation pas à pas |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Contribution & style de code |
| [SECURITY.md](SECURITY.md) | Politique de sécurité |
| [CHANGELOG.md](CHANGELOG.md) | Historique des versions |
| [docs/module-sdk.md](docs/module-sdk.md) | SDK & création de modules |
| [docs/marketplace.md](docs/marketplace.md) | Marketplace & format `.fmod` |
| [fmod-format.md](fmod-format.md) | Spécification du format `.fmod` |
| [docs/database.md](docs/database.md) | Schéma & conventions BDD |
| [docs/security.md](docs/security.md) | Sécurité côté développeur |
| [docs/api-reference.md](docs/api-reference.md) | Référence des endpoints |
| [docs/translation-guide.md](docs/translation-guide.md) | Guide de traduction (8 langues) |
| [docs/theme-development.md](docs/theme-development.md) | Création de thèmes |
| [docs/hook-reference.md](docs/hook-reference.md) | Événements / hooks |
| [docs/widget-api.md](docs/widget-api.md) | Widgets du tableau de bord |
| [docs/deployment-guide.md](docs/deployment-guide.md) | Déploiement en production |

---

*Fronote 4.0.0 « Étanche » — PHP pur · PSR-4 · conteneur DI maison · 63 modules · multi-établissement · topbar · 8 locales · schéma déclaratif additif + migrations versionnées · mise à jour Git un-bouton.*
