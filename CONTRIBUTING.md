# Contribuer à Fronote (v3.2.4)

Merci de votre intérêt pour Fronote ! Ce guide explique comment contribuer au code,
quelles conventions respecter, et comment ajouter un module, une table ou une chaîne
traduite sans rien casser.

> **Architecture** : les modules **métier** vivent sous `modules/<clé>/` avec leurs propres
> `Services/`, `Events/`, `Providers/`, `Jobs/`. **N'ajoutez PAS** de code de module dans `API/`.
> Voir [docs/module-sdk.md](docs/module-sdk.md) et [docs/marketplace.md](docs/marketplace.md).

---

## Règles d'architecture

**Un module PEUT :**
- Définir ses services sous `modules/<clé>/Services/`
- Définir ses événements sous `modules/<clé>/Events/`
- Déclarer un `ServiceProvider` sous `modules/<clé>/Providers/` (chargé au boot par
  `ModuleSDK::bootActiveModuleProviders()` pour les modules **actifs** uniquement)
- Émettre des événements inter-modules via `app('hooks')->dispatch()`
- Lire les services core : `app('cache')`, `app('log')`, `app('db')`, `app('config')`…
- Déclarer ses permissions dans `module.json`
- Déclarer son schéma SQL dans `modules/<clé>/Database/install.sql`

**Un module NE PEUT PAS :**
- Modifier un fichier de `API/` (cela exige une PR core relue)
- Ajouter un singleton dans `API/bootstrap.php`
- Modifier `RBAC::PERMISSIONS` directement
- Ajouter une méthode métier à `API/Core/WebSocket.php`
- Interroger la table d'un autre module sans passer par son service public

**Règle d'or** : s'il faut éditer un fichier de `API/` pour ajouter une fonctionnalité de
module, l'architecture est violée. Seule exception : ajouter une interface dans
`API/Contracts/`.

---

## Démarrage

1. Forkez le dépôt
2. Clonez votre fork : `git clone https://github.com/VOTRE_USER/Pronote.git`
3. Créez une branche : `git checkout -b feature/ma-fonctionnalite`
4. Faites vos changements
5. Poussez et ouvrez une Pull Request

---

## Environnement de développement

### Prérequis

- **PHP ≥ 8.0** (cf. `composer.json` et `version.json`) avec les extensions :
  `sodium`, `json`, `zip`, `pdo` (+ `pdo_mysql`). `sodium` et `zip` sont surtout requises
  par la marketplace (signature/vérification `.fmod`) ; leur absence est seulement loggée
  au boot, pas bloquante au démarrage.
- **MySQL 8.0+** ou **MariaDB 10.3+** (PDO)
- **Composer** (autoload PSR-4). Une installation sans `vendor/` reste possible : un
  autoloader PSR-4 de secours est enregistré dans `API/bootstrap.php`.
- **Node.js 18+** uniquement si vous lancez le serveur temps réel (`websocket/server.js`)
- Apache ou Nginx avec réécriture d'URL

Dépendance Composer : `firebase/php-jwt ^7.0`.

### Installation

```bash
# 1. Importer le schéma cœur
mysql -u root -p < pronote.sql

# 2. Configurer l'environnement
cp .env.example .env
# Éditez .env (BDD, APP_KEY, GITHUB_BRANCH, GIT_BINARY…)

# 3. (optionnel) Serveur WebSocket temps réel
cd websocket && npm install && node server.js
```

L'application gère aussi un **onboarding obligatoire** (`API/onboarding_gate.php`) : tant
qu'un établissement porte le code `default`, l'interface force sa configuration.

### Autoload PSR-4 (`composer.json`)

| Préfixe       | Dossier      |
|---------------|--------------|
| `API\`        | `API/`       |
| `Pronote\`    | `API/`       |
| `Modules\`    | `modules/`   |

> ⚠️ L'espace de noms d'un module est en **PascalCase**, le dossier en **snake_case**.
> Un autoloader dédié dans `bootstrap.php` convertit `Modules\EmploiDuTemps\Services\Foo`
> → `modules/emploi_du_temps/Services/Foo.php` (indispensable sous Linux, sensible à la casse).

---

## Structure du dépôt

```
API/              # Cœur : services, core, sécurité, endpoints, providers, contrats
accueil/          # Tableau de bord (essentiel, racine)
admin/            # Panneau d'administration (essentiel, racine)
login/            # Connexion (essentiel, racine)
parametres/       # Réglages utilisateur (essentiel, racine)
rgpd/             # Conformité RGPD (essentiel, racine)
securite/         # Pages sécurité (essentiel, racine)
tutorat/          # Tutorat (essentiel, racine)
modules/<clé>/    # Modules métier (notes, agenda, messagerie, …) — ~61 modules
assets/           # CSS, JS, images (CSS unifié, cf. plus bas)
lang/             # Traductions cœur (8 locales : ar, de, en, es, fr, nl, ru, th)
templates/        # Templates PHP partagés (shared_header, shared_topbar, …)
cron/             # Tâches planifiées (daily/hourly/weekly + rappels modules)
scripts/          # Scripts CLI (worker.php, build/sign .fmod, install-module.php)
websocket/        # Serveur Socket.IO (Node.js)
```

> Les **essentiels** (`accueil/`, `admin/`, `login/`, `parametres/`, `rgpd/`, `securite/`,
> `tutorat/`) restent à la racine et possèdent eux aussi un `module.json`.
> `ModuleSDK::discover()` scanne **`modules/*/module.json` ET `racine/*/module.json`**.

---

## Schéma de base de données — **AUCUNE MIGRATION**

> ⚠️ **Il n'y a plus de système de migrations** (supprimé le 2026-06-17). N'écrivez
> **jamais** de migration, de table `module_migrations`/`core_migrations`, de dossier
> `Database/migrations/`, ni de clé `migrations` dans `module.json`. Tout cela a disparu.

Le schéma est **déclaratif et final** :

- **Cœur** : `pronote.sql` (importé à l'installation).
- **Par module** : `modules/<clé>/Database/install.sql` — le **schéma final complet**,
  idempotent (`CREATE TABLE IF NOT EXISTS`, colonnes avec valeurs par défaut).

À l'**activation** d'un module, `ModuleSDK::provisionSql($clé)` exécute uniquement son
`install.sql` (chemin par défaut, surchargeable via `module.json` → `database.install`).
L'exécution désactive temporairement `FOREIGN_KEY_CHECKS`, joue chaque instruction
séparément et **ignore** les erreurs « table déjà présente » (1050) / « colonne en double »
(1060), ce qui rend la ré-activation sûre.

### Modifier le schéma d'un module

1. Éditez directement `modules/<clé>/Database/install.sql` pour refléter l'**état final**
   voulu (ajoutez la table ou la colonne avec sa valeur par défaut).
2. **Ne** créez **pas** de fichier de migration séparé.
3. En production, la mise à jour réconcilie le schéma automatiquement (voir ci-dessous).

### Mise à jour applicative = un seul bouton

`admin/systeme/update.php` appelle `app('updates')->applyUpdate()`
(`API/Services/UpdateService.php`), qui enchaîne :

1. `git fetch origin <GITHUB_BRANCH>`
2. `git reset --hard origin/<GITHUB_BRANCH>` (le serveur reflète exactement le dépôt ;
   le `.env` est sauvegardé/restauré au cas où)
3. **`API\Services\SchemaSyncService::sync()`** — réconciliation **déclarative et
   idempotente** : lit les `install.sql` + `pronote.sql`, `CREATE` les tables manquantes
   et `ADD COLUMN` les colonnes manquantes. **Jamais de migration, jamais de `DROP`.**
4. `app('module_sdk')->syncAll()` — resynchronise les manifestes (permissions, widgets, routes)
5. Vidage du cache applicatif

Config `.env` : `GITHUB_BRANCH` (défaut `main`), `GIT_BINARY` (chemin de `git` s'il est
hors du `PATH` d'Apache, ex. Windows).

> Concrètement : **votre seule obligation côté schéma est de garder `install.sql` à jour.**
> `SchemaSyncService` se charge d'appliquer le delta sur les installations existantes.

---

## Conventions de code

### PHP
- Norme PSR-12, `declare(strict_types=1)` comme dans le cœur
- Espaces de noms : `API\…` (cœur), `Modules\<Module>\…` (modules)
- Types sur toutes les signatures de méthodes
- PHPDoc sur les méthodes publiques
- Injection de dépendances via le conteneur : `app('clé')`

#### Services du conteneur (`app('…')`)

Les services core sont enregistrés dans `API/bootstrap.php` et les `Providers`. Exemples
fréquents : `db`, `config`, `auth`, `rbac`, `csrf`, `cache`, `client_cache`, `log`,
`audit`, `translator`, `validator`, `features`, `hooks`, `queue`, `module_sdk`,
`marketplace`, `etablissement`, `user`, `email`, `pdf`, `encryption`, `backup`, `updates`,
`maintenance`, `health`, `themes`, `firewall`, `rate_limiter`, `password_policy`,
`super_admin`, `admin_dashboard`, `classes`, `quarantine`.

### JavaScript
- Compatible ES5 (pas de transpileur)
- `var` plutôt que `let`/`const` pour la compatibilité navigateur
- Suivre les patterns existants de `assets/js/`

### CSS — feuille unifiée + topbar
- **Pas de sidebar** : la navigation est une **topbar horizontale**
  (`templates/shared_topbar.php` + `shared_topbar_nav.php`).
- CSS centralisé dans `assets/css/` : `base.css`, `tokens.css`, `components.css`,
  `theme-classic.css`, `theme-glass.css`, `topbar.css`. Ces feuilles sont injectées par
  `templates/shared_header.php` via `$rootPrefix` (calculé automatiquement depuis l'URI).
- Nommage **BEM** : `.ui-card__header--collapsed`
- Couleurs et espacements via les **design tokens** de `assets/css/tokens.css`
- **Pas de styles inline** dans les fichiers PHP — utilisez les classes utilitaires de `base.css`

#### Squelette d'une page de module

```php
<?php
// modules/<clé>/includes/header.php
$pageBack = 'index.php'; // optionnel : bouton « Retour » dans la top-header
include __DIR__ . '/../../../templates/shared_header.php';   // <head>, CSS, CSP
include __DIR__ . '/../../../templates/shared_topbar.php';   // topbar + ouvre .content-container
?>
```

```php
<?php
// modules/<clé>/includes/footer.php
?>
</div><!-- Fin content-container -->
<?php include __DIR__ . '/../../../templates/shared_footer.php'; ?>
```

Le bouton de retour optionnel se déclare en posant `$pageBack` (chaîne relative ou
tableau `['url' => …, 'label' => …]`) **avant** l'inclusion de `shared_topbar.php`.

---

## Internationalisation (i18n)

- Service : `app('translator')` ; helper global `__('domaine.clé', $params)` (et `_n()`
  pour la pluralisation, variantes séparées par `|`).
- Fichiers : `lang/<locale>/<domaine>.json` (cœur) et `modules/<clé>/lang/<locale>.json`
  (modules). Locales disponibles : `ar, de, en, es, fr, nl, ru, th`.

> ⚠️ **Une clé absente RENVOIE LA CLÉ telle quelle** — il n'y a pas de valeur par défaut.
> Le **2ᵉ argument `$params` sert à l'interpolation** (`:nom` → valeur), **pas** à fournir
> un repli. Toute nouvelle chaîne visible DOIT donc avoir sa clé déclarée dans **toutes**
> les locales (au minimum `fr` et `en`).

```php
// lang/fr/notes.json : { "notes.title": "Notes de :eleve" }
echo __('notes.title', ['eleve' => $nom]); // "Notes de Dupont"
echo __('notes.absent');                   // clé inconnue → affiche "notes.absent"
```

---

## Ajouter un module

Un module comprend :
- Un dossier `modules/<clé>/`
- Un manifeste `module.json` : `key`, `name`/`description` (objets `{fr, en}`), `icon`,
  `category`, `core`, `requires_php`, `dependencies`, `permissions`, `routes`, `widgets`,
  `establishment_types`, `topbar`, `settings_schema`… (**pas** de clé `migrations`)
- Un schéma `Database/install.sql` (idempotent, schéma final)
- Des traductions `modules/<clé>/lang/<locale>.json`
- `includes/header.php` + `includes/footer.php` (cf. squelette ci-dessus)
- Optionnel : `Services/`, `Events/`, `Providers/<X>ServiceProvider.php`, `Jobs/`, widgets

Voir [docs/module-sdk.md](docs/module-sdk.md) pour le guide complet.

---

## Multi-établissement (à respecter dans tout nouveau code)

- Contexte courant : `\API\Core\EstablishmentContext::id()`.
- **Toute** requête sur une table scopée doit filtrer `etablissement_id`. Les nouvelles
  tables d'`install.sql` doivent porter une colonne `etablissement_id` (cf. `notes`).
- L'authentification est scopée (`UserProvider`). Le rôle `super_admin` peut gérer
  plusieurs établissements.

## Authentification & rôles

Rôles : `administrateur`, `professeur`, `vie_scolaire`, `eleve`, `parent` (+ `super_admin`).
Mots de passe en `bcrypt` (coût 12), rate-limit IP+identifiant, anti-énumération.
L'**identifiant de connexion = login `nom.prenom`**. Protégez toute action mutante par
CSRF : `csrf_verify()` ou `app('csrf')->validate(...)`. Les en-têtes de sécurité et la CSP
sont posés par `shared_header.php`.

---

## Tests & vérifications avant PR

1. Le code suit le style ci-dessus (PSR-12, BEM, ES5).
2. Toute nouvelle chaîne visible a sa clé i18n dans `fr` **et** `en` (sinon la clé brute
   s'affiche).
3. Les nouvelles fonctionnalités basculables passent par un *feature flag*
   (`app('features')->isEnabled('module.feature')`).
4. Les requêtes sur tables scopées filtrent bien `etablissement_id`.
5. Le schéma modifié est reflété dans `install.sql` (pas de migration).
6. Testez l'écran concerné en `fr` et `en` ; vérifiez que la topbar et le CSS s'affichent
   (page de module = `header.php` → `footer.php`).
7. Activez/désactivez le module concerné pour valider l'idempotence de `install.sql`.

---

## Messages de commit

Conventional commits :
- `feat: ajout de l'import en masse des notes`
- `fix: correction de la validation de date d'absence`
- `refactor: extraction de la logique de modale partagée`
- `docs: mise à jour du guide module SDK`
- `i18n: traductions allemandes du module notes`

---

## Signaler un problème

Utilisez les modèles d'issue GitHub :
- **Bug** : étapes de reproduction, comportement attendu vs constaté
- **Fonctionnalité** : cas d'usage et solution proposée

## Licence

En contribuant, vous acceptez que vos contributions soient publiées sous licence **MIT**.
