# Module SDK — Guide du Développeur (v3.2.4)

## Introduction

Fronote utilise une architecture modulaire. Chaque module métier est un dossier autonome **sous `modules/<clé>/`**, déclaré via un fichier `module.json`. (Quelques composants essentiels — `accueil/`, `admin/`, `parametres/`, `rgpd/`, `securite/`, `tutorat/`… — restent à la racine et exposent eux aussi un `module.json`.) Ce guide explique comment créer, configurer et publier un module.

Le SDK est implémenté dans `API/Services/ModuleSDK.php` et accessible via `app('module_sdk')`. La gestion d'état (activation, favoris, sidebar/topbar) est dans `API/Services/ModuleService.php`, accessible via `app('modules')`.

> ⚠️ **Pas de migrations.** Depuis le 2026-06-17, Fronote n'utilise **plus** de fichiers de migration ni de table de suivi. Le schéma d'un module est décrit en entier dans **un seul** fichier `Database/install.sql` (CREATE TABLE IF NOT EXISTS, schéma final complet). Voir [Schéma & provisionnement SQL](#schéma--provisionnement-sql).

---

## ServiceProviders de modules

Chaque module peut exposer un **ServiceProvider** chargé automatiquement après le boot du core. C'est le mécanisme recommandé pour enregistrer les services, bindings et listeners d'événements d'un module.

### Structure

```
modules/mon_module/
├── Providers/
│   └── MonModuleServiceProvider.php   ← naming : PascalCase(key) + "ServiceProvider"
├── Services/
│   └── MonModuleService.php
└── Events/
    └── MonEvenement.php
```

### Créer un ServiceProvider

```php
<?php
// modules/mon_module/Providers/MonModuleServiceProvider.php
declare(strict_types=1);

namespace Modules\MonModule\Providers;

use API\Core\ServiceProvider;

class MonModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Enregistrer les services dans le container (lazy — pas instanciés ici)
        $this->app->singleton('mon_module', function ($app) {
            return new \Modules\MonModule\Services\MonModuleService(
                $app->make('db')->getConnection()
            );
        });
    }

    public function boot(): void
    {
        // Enregistrer les listeners d'événements via HookManager
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();

        $hooks->register(\Modules\MonModule\Events\MonEvenement::class, [$audit, 'handle']);
    }
}
```

### Convention de nommage

La classe et le namespace sont **dérivés mécaniquement** de la clé (snake_case → PascalCase). C'est exactement ce calcul que fait `ModuleSDK::bootActiveModuleProviders()` :

```php
$pascal = implode('', array_map('ucfirst', explode('_', $key)));
// fichier : modules/{key}/Providers/{Pascal}ServiceProvider.php
// classe  : Modules\{Pascal}\Providers\{Pascal}ServiceProvider
```

| Clé du module | Nom de classe | Fichier |
|---------------|--------------|---------|
| `notes` | `NotesServiceProvider` | `modules/notes/Providers/NotesServiceProvider.php` |
| `agenda` | `AgendaServiceProvider` | `modules/agenda/Providers/AgendaServiceProvider.php` |
| `emploi_du_temps` | `EmploiDuTempsServiceProvider` | `modules/emploi_du_temps/Providers/EmploiDuTempsServiceProvider.php` |
| `mon_module` | `MonModuleServiceProvider` | `modules/mon_module/Providers/MonModuleServiceProvider.php` |

### Chargement automatique

`ModuleSDK::bootActiveModuleProviders($app)` est appelé dans `API/bootstrap.php` après `$app->boot()`. Il parcourt les modules **actifs** (`enabled = 1` en base), résout le fichier `Providers/{Pascal}ServiceProvider.php` à partir du chemin réel du module, et l'enregistre s'il existe. Un module dont le ServiceProvider n'existe pas est ignoré silencieusement ; une erreur de chargement est loggée sans interrompre le boot.

### Namespace `Modules\`

Le namespace `Modules\` est mappé en PSR-4 sur `modules/` dans `composer.json` :

```php
// Fonctionne automatiquement via autoloader
use Modules\Notes\Services\NoteService;
use Modules\Absences\Events\AbsenceCreated;
```

---

## Événements de modules

Le système d'événements de Fronote est **orienté objet** : on dispatche une instance d'une classe d'événement, et les listeners sont enregistrés sur le **nom de classe**. C'est le mécanisme réellement utilisé par les modules (Notes, Absences, Agenda, Devoirs, Emploi du temps…).

### Définir un événement

Les événements domaine sont des objets immuables définis dans le namespace du module :

```php
<?php
// modules/mon_module/Events/MonEvenement.php
declare(strict_types=1);

namespace Modules\MonModule\Events;

class MonEvenement
{
    public function __construct(
        public readonly int $id,
        public readonly array $data,
    ) {}
}
```

### Dispatcher depuis un service

```php
// L'opérateur ?-> tolère l'absence du service hooks (CLI, tests…)
app('hooks')?->dispatch(new \Modules\MonModule\Events\MonEvenement($id, $data));
```

Exemple réel (`modules/absences/Services/AbsenceService.php`) :

```php
app('hooks')?->dispatch(new \Modules\Absences\Events\AbsenceCreated($id, $data));
```

### Écouter un événement

L'enregistrement se fait typiquement dans le `boot()` du ServiceProvider du module, en utilisant le **FQCN de la classe d'événement** comme nom d'événement :

```php
$hooks = app('hooks');
$hooks->register(\Modules\MonModule\Events\MonEvenement::class, function ($event) {
    // $event est l'instance dispatchée
    // $event->id, $event->data ...
}, priority: 10);
```

`HookManager::dispatch($event)` déclenche les listeners enregistrés sur la classe **et** sur ses parents/interfaces (`class_parents` + `class_implements`), ce qui permet d'écouter une classe de base ou un marqueur d'interface commun.

**Rétrocompatibilité** : certains anciens namespaces `API\Events\*` (ex. `NoteCreated`) sont des `class_alias` pointant vers leurs équivalents `Modules\*`. L'ancien code continue de fonctionner.

### API `HookManager` (`app('hooks')`)

Le `HookManager` (`API/Core/HookManager.php`) expose aussi une API « nommée » à base de chaînes, utile pour les points d'extension génériques :

| Méthode | Rôle |
|---|---|
| `register(string $event, callable $cb, int $priority = 10)` | Abonne un callback. Priorité croissante = exécuté en premier. `$event` peut être un FQCN ou une chaîne arbitraire. |
| `dispatch(object $event)` | Dispatche un objet événement (vers sa classe + parents + interfaces). **Mécanisme principal.** |
| `fire(string $event, mixed ...$args)` | Déclenche un événement nommé avec des arguments positionnels. |
| `filter(string $event, mixed $value, mixed ...$args): mixed` | Passe `$value` à travers les callbacks (chacun retourne la valeur modifiée). |
| `has(string $event): bool` | Vrai si au moins un listener est abonné. |
| `clear(string $event)` / `clearAll()` | Désabonne. |

> Note : les callbacks sont enveloppés dans un `try/catch` — une exception d'un listener est loggée (`error_log`) sans interrompre les autres ni l'appelant. Ne comptez donc pas sur un événement pour faire échouer une opération métier.

---

## Structure d'un module

```
modules/mon_module/
├── module.json              # Manifeste obligatoire
├── mon_module.php           # Page principale (routes.main)
├── api/
│   └── actions.php          # Endpoints API du module (optionnel ; routes.api)
├── Database/
│   └── install.sql          # Schéma COMPLET du module (idempotent, provisionné par le SDK)
├── Providers/
│   └── MonModuleServiceProvider.php
├── Services/
│   └── MonModuleService.php
├── Events/
│   └── MonEvenement.php
├── includes/
│   ├── header.php           # Inclut shared_header + shared_topbar
│   ├── footer.php           # Ferme .content-container + shared_footer
│   └── MonWidgetProvider.php # Fournisseur de données widget
├── widgets/
│   └── mon_widget.php       # Template de rendu du widget
├── assets/
│   ├── css/mon_module.css
│   └── js/mon_module.js
└── lang/
    ├── fr.json
    └── en.json
```

## Le manifeste `module.json`

Chaque module **doit** contenir un fichier `module.json` à sa racine. Exemple complet (basé sur `modules/notes/module.json`) :

```json
{
  "key": "mon_module",
  "version": "1.0.0",
  "name": { "fr": "Mon Module", "en": "My Module" },
  "description": { "fr": "Description du module", "en": "Module description" },
  "icon": "fas fa-puzzle-piece",
  "category": "scolaire",
  "core": false,
  "requires_php": ">=8.0",
  "dependencies": [],
  "permissions": {
    "view":   { "default_roles": ["*"] },
    "manage": { "default_roles": ["administrateur", "professeur"] },
    "edit":   { "default_roles": ["administrateur"] },
    "delete": { "default_roles": ["administrateur"] }
  },
  "routes": {
    "main": "mon_module.php",
    "api": "api/actions.php"
  },
  "database": { "install": "Database/install.sql" },
  "widgets": [],
  "establishment_types": null,
  "sidebar": { "sort_order": 50 },
  "topbar": { "category": "scolaire", "sort_order": 50 },
  "settings_schema": {},
  "author": "Fronote Team",
  "author_url": "",
  "contributors": [],
  "license": "MIT"
}
```

### Champs du manifeste

| Champ | Type | Requis | Description |
|---|---|---|---|
| `key` | string | **Oui** | Identifiant unique. Doit matcher `^[a-z][a-z0-9_]*$` (minuscule + underscore) et correspondre au nom du dossier. |
| `name` | object | **Oui** | Nom traduit. Doit contenir au moins la clé `fr`. |
| `icon` | string | **Oui** | Classe Font Awesome de l'icône. |
| `category` | string | **Oui** | Catégorie — doit appartenir à `ModuleSDK::VALID_CATEGORIES`, sinon le module est rejeté à la synchro. |
| `version` | string | Non | Version SemVer (informative). |
| `description` | object\|string | Non | Description traduite (`{fr, en}`). |
| `core` | bool | Non | `true` ⇒ activé d'office (`enabled = 1`) et **non désactivable** ; sinon installé mais désactivé jusqu'à activation admin. |
| `requires_php` / `dependencies` | string / array | Non | Informatifs (non vérifiés par le SDK). |
| `permissions` | object | Non | Map `action → { default_roles: [...] }`. Convertie en lignes role-based dans `module_permissions` (voir [Permissions](#permissions-rbac)). |
| `routes.main` | string | Non* | Fichier PHP principal (point d'entrée). La route effective stockée en base est `<dossier_relatif>/<fichier>` (ex. `modules/notes/notes.php`). |
| `routes.api` | string | Non | Endpoint API du module (informatif côté SDK). |
| `database.install` | string | Non | Chemin du `install.sql` (défaut `Database/install.sql`), exécuté par `provisionSql()`. |
| `widgets` | array | Non | Widgets fournis par le module (voir [Widgets](#widgets)). |
| `establishment_types` | array\|null | Non | `null` = tous types. Sinon un tableau, ex. `["college", "lycee", "superieur"]`. Doit être un tableau s'il est présent. |
| `sidebar.sort_order` | int | Non | Ordre dans la navigation (défaut 100). |
| `sidebar.hidden` | bool | Non | `true` ⇒ module installé mais masqué de la navigation (`sidebar_hidden = 1`). |
| `topbar` | object | Non | `{ category, sort_order }` — **convention de manifeste** ; voir l'encadré ci-dessous. |
| `settings_schema` | object | Non | Paramètres configurables par l'admin (voir [Settings Schema](#settings-schema)). |
| `author`, `author_url`, `contributors`, `license` | — | Non | Crédits (voir [Credits](#credits)). |

\* `routes.main` n'est pas dans `REQUIRED_FIELDS`, mais sans lui aucune `route_path` n'est calculée et le module n'a pas de page d'entrée.

> ⚠️ **Pas de clé `migrations`.** Toute clé `"migrations"` dans un `module.json` est **ignorée** : le mécanisme de migrations a été supprimé. Mettez tout le schéma dans `Database/install.sql`.

> ℹ️ **À propos de `topbar`.** La clé `topbar` est présente dans les manifestes mais **n'est pas lue par `ModuleSDK::syncModule()`**. Le placement dans la barre horizontale est piloté par les colonnes `topbar_category` / `topbar_sort_order` de `modules_config` (auto-créées par `ModuleService::ensureTopbarColumns()`), avec un fallback sur `category` / `sidebar.sort_order`. Voir `ModuleService::getForTopbar()`.

### Champs requis (validation)

`ModuleSDK::validate()` impose : `key`, `name`, `icon`, `category` (constante `REQUIRED_FIELDS`). Il valide aussi le format de `key`, l'appartenance de `category`, la présence de `name.fr`, la structure des `widgets` (chaque widget doit avoir `key` + `name`), des `permissions` (chaque action doit avoir `default_roles`) et que `establishment_types` est un tableau. Un manifeste invalide est **ignoré à la synchro** (loggé), pas installé.

### Catégories disponibles

Valeurs acceptées (`ModuleSDK::VALID_CATEGORIES`) :

`navigation`, `scolaire`, `vie_scolaire`, `communication`, `etablissement`, `logistique`, `outils`, `administration`, `systeme`, `sante`, `custom`.

L'affichage groupé (libellés, icônes, ordre) côté sidebar/topbar est défini par `ModuleService::categoryMeta()` et `categoryLabels()` ; certains modules sont remappés visuellement via `sidebarCategoryOverrides()` (ex. `infirmerie` → `sante`).

## Boot d'une page de module

Une page de module suit ce squelette (cf. `modules/notes/`) :

```php
<?php
// modules/mon_module/mon_module.php
require_once __DIR__ . '/../../API/module_boot.php';

// Variables disponibles automatiquement après module_boot.php :
//   $user          — array  : données utilisateur courant
//   $user_role     — string : rôle (administrateur, professeur, vie_scolaire, eleve, parent)
//   $user_fullname — string : nom complet
//   $user_initials — string : initiales
//   $isAdmin       — bool
//   $pdo           — PDO    : connexion base de données
//   $rootPrefix    — string : chemin relatif vers la racine (auto-calculé)
// + gates appliqués : auth obligatoire, onboarding (établissement 'default'),
//   redéfinition des périodes si l'année est terminée.

$pageTitle  = __('mon_module.title');
$activePage = 'mon_module';

include __DIR__ . '/includes/header.php';   // shared_header + shared_topbar, ouvre .content-container
?>

<!-- Contenu du module -->

<?php include __DIR__ . '/includes/footer.php'; // ferme .content-container + shared_footer ?>
```

Le `includes/header.php` du module inclut `templates/shared_header.php` (CSS unifié, CSP, métadonnées) puis `templates/shared_topbar.php` (navigation horizontale, plus de sidebar) et ouvre `<div class="content-container">`. Le `includes/footer.php` ferme ce conteneur et inclut `shared_footer.php`.

- Surchargez `$extraCss` (tableau de chemins relatifs au module) **avant** d'inclure le header pour ajouter la feuille de style du module — ex. `$extraCss = ['assets/css/mon_module.css'];`.
- `$rootPrefix` est calculé automatiquement par `module_boot.php` selon la profondeur du fichier appelant : ne le codez pas en dur.

## Cycle de vie & synchronisation

```
discover → validate → syncModule (modules_config + widgets + settings + permissions)
                    → [activation admin] provisionSql (install.sql) → enabled = 1 → bootProviders
```

| Méthode (`app('module_sdk')`) | Rôle |
|---|---|
| `discover(): array` | Scanne `modules/*/module.json` **et** `*/module.json` (essentiels racine). Retourne `[key => manifest]` (avec `_path` / `_json_path` ajoutés). Résultat mis en cache mémoire. |
| `validate(array $manifest): array` | `['valid' => bool, 'errors' => string[]]`. Champs requis + format key + catégorie + name.fr + widgets + permissions + establishment_types. |
| `syncAll(): array` | Valide puis `syncModule()` chaque manifeste découvert. Retourne `['synced' => int, 'errors' => string[]]`. |
| `syncModule(array $manifest): void` | Upsert dans `modules_config`, et synchronise `dashboard_widgets`, `module_settings_schema`, `module_permissions`. Calcule `route_path` depuis `routes.main`. |
| `provisionSql(string $key): array` | Exécute **uniquement** `Database/install.sql` (idempotent). `['success' => bool, 'errors' => string[]]`. **Pas de migrations.** |
| `getManifest(string $key): ?array` | Manifeste d'un module. |
| `getWidgetConfigs($key)` / `getAllWidgetConfigs()` | Configs widgets (avec `_module_path`). |
| `resolveWidgetProvider($widgetKey)` / `resolveWidgetTemplate($widgetKey)` | Résout le `WidgetDataProvider` / le template d'un widget. |
| `bootActiveModuleProviders($app): void` | Charge les ServiceProviders des modules actifs (appelé dans `bootstrap.php`). |
| `clearCache(): void` | Vide le cache mémoire des manifestes. |

### Activation / désactivation (`app('modules')`)

L'état activé/désactivé est géré par `ModuleService` (`API/Services/ModuleService.php`) :

- **`setEnabled($key, true)`** : appelle d'abord `provisionSql($key)`. **Si le SQL échoue, le module n'est pas activé** (jamais de module à moitié installé). En cas de succès, passe `enabled = 1` (uniquement si `is_core = 0`) et vide le cache.
- **`setEnabled($key, false)`** : refusé pour les modules `core` (`isCore()` ⇒ `false`).
- À la **première insertion** dans `modules_config`, `enabled` vaut `is_core` (les modules `core` sont activés d'office). L'`ON DUPLICATE KEY UPDATE` de `syncModule()` **ne touche pas** `enabled` ni `sort_order` (préserve le choix admin) — il rafraîchit seulement label, description, icon, category, establishment_types, route_path, sidebar_sort/hidden.

La page d'administration des modules est `admin/modules/index.php`.

## Schéma & provisionnement SQL

> **Source de vérité = `modules/<m>/Database/install.sql`** (+ `pronote.sql` pour le cœur). Il n'y a **plus aucune migration** : pas de `module_migrations`, pas de `Database/migrations/`, pas de clé `module.json "migrations"`.

### Où placer le schéma

Tout le schéma du module va dans **un seul** `Database/install.sql`, écrit en **CREATE TABLE IF NOT EXISTS** (schéma final complet, idempotent). Exemple réel (`modules/notes/Database/install.sql`) :

```sql
CREATE TABLE IF NOT EXISTS `notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `id_eleve` int(11) NOT NULL,
  `id_matiere` int(11) NOT NULL,
  `note` decimal(4,2) NOT NULL,
  -- ...
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_notes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Règles à respecter :

1. **`CREATE TABLE IF NOT EXISTS`** systématiquement (le SQL peut être rejoué).
2. **Scoping multi-établissement** : toute table contenant des données métier porte une colonne `etablissement_id`, idéalement avec un index et une FK vers `etablissements(id)`. Le code filtre ensuite sur `\API\Core\EstablishmentContext::id()`.
3. **InnoDB + `utf8mb4` / `utf8mb4_unicode_ci`**.
4. Un seul fichier : pas de fichiers incrémentaux.

### Quand le SQL est exécuté

- **À l'activation** d'un module (`ModuleService::setEnabled($key, true)`) : `provisionSql($key)` exécute son `install.sql`.
- **À chaque mise à jour applicative** (bouton unique, voir ci-dessous) : `SchemaSyncService::sync()` réconcilie la base avec **tous** les `install.sql` + `pronote.sql`.

### Comment le SQL est exécuté (`provisionSql`)

`provisionSql()` lit `database.install` (défaut `Database/install.sql`) puis exécute le script via `execSchemaSql()` :

- `SET FOREIGN_KEY_CHECKS=0` (références croisées inter-modules, ordre d'activation arbitraire), restauré en `finally`.
- Découpage en instructions individuelles (`API\Core\SqlSplitter`), exécutées **une par une** — un échec isolé n'arrête pas les suivantes.
- **Pas de transaction** : le DDL provoque un commit implicite.
- Les codes `1050` (table déjà existante) et `1060` (colonne dupliquée) sont **ignorés** (sûr en ré-exécution). Tout autre échec agrège un message d'erreur ⇒ `success = false` ⇒ activation refusée.

### Mise à jour de l'application (un seul bouton)

La mise à jour passe par `admin/systeme/update.php` → `app('updates')->applyUpdate()` (`API/Services/UpdateService.php`) :

1. `git fetch origin <GITHUB_BRANCH>`
2. `git reset --hard origin/<GITHUB_BRANCH>` (le serveur reflète exactement le dépôt ; le `.env` est sauvegardé/restauré)
3. **`SchemaSyncService::sync()`** — réconciliation **déclarative et idempotente** : `CREATE` des tables manquantes + `ADD COLUMN` des colonnes manquantes, lues depuis les `install.sql` / `pronote.sql`. **Jamais de migration, jamais de DROP, jamais de modification de type existant** (« ajout seulement »).
4. `app('module_sdk')->syncAll()` — re-synchronise permissions, widgets, routes…
5. `app('cache')->flush()`

Config `.env` : `GITHUB_BRANCH` (défaut `main`), `GIT_BINARY` (chemin de git s'il est hors du PATH d'Apache).

## Permissions (RBAC)

Les permissions sont déclarées dans `module.json → permissions` et synchronisées en base par `ModuleSDK::syncPermissions()`.

### Du manifeste à la base

Le manifeste déclare des **actions** (`view`, `manage`, `create`, `edit`, `delete`, `export`, `import`) avec leurs `default_roles`. Le SDK les convertit en colonnes `can_*` de la table `module_permissions` (orientée rôle, une ligne par `module_key × role`), via cette correspondance :

| Action | Colonnes activées |
|---|---|
| `view` | `can_view` |
| `manage` | `can_view`, `can_create`, `can_edit`, `can_delete` |
| `create` | `can_create` |
| `edit` | `can_edit` |
| `delete` | `can_delete` |
| `export` | `can_export` |
| `import` | `can_import` |

- Le wildcard `"*"` dans `default_roles` accorde l'action à **tous** les rôles (`administrateur`, `professeur`, `vie_scolaire`, `eleve`, `parent`).
- Une action inconnue retombe sur `can_view`.
- L'insertion est en **`INSERT IGNORE`** : on ne sème que les paires (module, rôle) absentes, sans écraser les ajustements faits par l'admin dans la matrice de permissions.

### Vérifier une permission dans le code

```php
// Helper global
if (hasPermission('mon_module.manage')) {
    // ...
}

// Via le service RBAC
$rbac = app('rbac');
if ($rbac->can($userId, $userType, 'mon_module', 'edit')) {
    // ...
}
```

### Rôles disponibles

| Rôle | Description |
|---|---|
| `administrateur` | Accès total |
| `professeur` | Enseignant |
| `vie_scolaire` | CPE / Assistant d'éducation |
| `eleve` | Élève |
| `parent` | Parent d'élève |

(`super_admin` existe au niveau plateforme pour gérer plusieurs établissements ; il n'est pas une cible des `default_roles` de modules.)

## Widgets

Un module peut fournir un ou plusieurs widgets pour le dashboard d'accueil.

### Déclaration dans `module.json`

```json
{
  "widgets": [
    {
      "key": "mon_widget",
      "name": { "fr": "Mon Widget", "en": "My Widget" },
      "description": { "fr": "…", "en": "…" },
      "type": "list",
      "icon": "fas fa-list",
      "roles": ["eleve", "professeur"],
      "default_size": { "width": 2, "height": 1 },
      "min_width": 1,
      "max_width": 4,
      "is_default": true,
      "sort_order": 30,
      "data_provider": "includes/MonWidgetProvider.php",
      "template": "widgets/mon_widget.php"
    }
  ]
}
```

Synchronisé dans `dashboard_widgets` par `ModuleSDK::syncWidgets()`. Champs effectivement persistés : `key`, `name.fr`, `description.fr`, `icon` (défaut `fas fa-th`), `type` (défaut `list`), `roles` (→ `roles_autorises`), `default_size.width` (→ `default_width`, défaut 2), `is_default`, `sort_order` (défaut 50). Les champs `min_width` / `max_width` / `default_size.height` sont indicatifs côté front.

### Créer un WidgetDataProvider

Le provider doit implémenter `API\Contracts\WidgetDataProvider`. Le moyen recommandé est d'hériter de `API\Contracts\AbstractWidgetProvider`, qui fournit `pdo()` et `etabId()` / `etabIdOrEmpty()` pour garantir le **scoping établissement** par défaut.

```php
<?php
// modules/mon_module/includes/MonWidgetProvider.php
declare(strict_types=1);

use API\Contracts\AbstractWidgetProvider;

class MonWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        // Court-circuit propre si pas de contexte établissement
        $etabId = $this->etabIdOrEmpty(['items' => [], 'count' => 0]);
        if (is_array($etabId)) return $etabId;

        $stmt = $this->pdo()->prepare(
            'SELECT id, libelle FROM ma_table WHERE etablissement_id = ? ORDER BY id DESC LIMIT 5'
        );
        $stmt->execute([$etabId]);

        return [
            'items' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'title' => __('mon_module.widget_title'),
        ];
    }

    public function getRefreshInterval(): int
    {
        return 300; // 5 minutes (0 = pas de refresh auto)
    }
}
```

**Résolution de classe** (`ModuleSDK::resolveWidgetProvider()`) : le SDK lit le fichier `data_provider`, en extrait le `namespace` déclaré, `require_once` le fichier, puis instancie `Namespace\NomFichier` (fallback sans namespace). La classe **doit** implémenter `WidgetDataProvider`, sinon elle est ignorée (loggé). Le nom de classe doit donc correspondre au nom du fichier.

### Template du widget

```php
<?php
// modules/mon_module/widgets/mon_widget.php
// Variable $data = résultat de getData()
?>
<div class="widget-content">
    <h3><?= htmlspecialchars($data['title']) ?></h3>
    <ul>
        <?php foreach ($data['items'] as $item): ?>
        <li><?= htmlspecialchars($item['libelle']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
```

### Types de widgets

| Type | Description |
|---|---|
| `list` | Liste d'éléments (défaut) |
| `chart` | Graphique (Chart.js) |
| `stat` | Statistique avec icône |
| `calendar` | Mini-calendrier |
| `custom` | Template personnalisé |

## Internationalisation (i18n)

### Utiliser les traductions

```php
echo __('mon_module.title');

// Avec interpolation de paramètres (2e argument)
echo __('mon_module.welcome', ['name' => $user_fullname]);
// Fichier JSON : "mon_module.welcome": "Bienvenue :name"
```

> ⚠️ Une clé absente **renvoie la clé elle-même**. Le 2e argument est l'**interpolation**, **pas** une valeur par défaut. Toute nouvelle chaîne affichée doit donc avoir sa clé dans les fichiers de langue, sinon l'utilisateur voit `mon_module.welcome` à l'écran.

### Fichiers de traduction

Le translator (`app('translator')`) lit `lang/<locale>/<domaine>.json`. Placez vos clés dans le domaine du module (ex. `lang/fr/modules/mon_module.json` côté racine), ou fournissez `lang/fr.json` / `lang/en.json` dans le dossier du module.

```json
{
  "mon_module.title": "Mon Module",
  "mon_module.welcome": "Bienvenue :name"
}
```

## Settings Schema

Le champ `settings_schema` de `module.json` définit les paramètres configurables par l'administrateur, synchronisés dans `module_settings_schema` par `ModuleSDK::syncSettingsSchema()`.

```json
{
  "settings_schema": {
    "note_max": {
      "type": "number",
      "label": { "fr": "Note maximale", "en": "Maximum grade" },
      "default": 20,
      "min": 0,
      "max": 100
    },
    "allow_comments": {
      "type": "checkbox",
      "label": { "fr": "Autoriser les commentaires", "en": "Allow comments" },
      "default": true
    },
    "display_mode": {
      "type": "select",
      "label": { "fr": "Mode d'affichage", "en": "Display mode" },
      "options": [
        { "value": "list", "label": { "fr": "Liste", "en": "List" } },
        { "value": "grid", "label": { "fr": "Grille", "en": "Grid" } }
      ],
      "default": "list"
    }
  }
}
```

### Types supportés

`field_type` est un ENUM en base. Valeurs acceptées (le SDK élargit automatiquement l'ENUM des anciennes bases) :

`text`, `number`, `integer`, `checkbox`, `boolean`, `select`, `textarea`, `color`, `json`, `email`, `url`, `date`, `time`, `datetime`.

Alias tolérés : `int → integer`, `bool → boolean`, `string → text`, `long → textarea`. Un type inconnu retombe sur `text`. Les valeurs `label` / `default` / `hint` acceptent un scalaire, un booléen, ou un objet i18n `{fr, en}` (le SDK normalise : `fr` sinon `en` sinon JSON).

Les paramètres sont édités dans `admin/modules/configure.php` et stockés dans la table `module_settings`.

## API REST d'un module

Convention en vigueur (cf. `API/endpoints/messagerie.php`) : réponse JSON construite
à la main — `header('Content-Type: application/json')`, `http_response_code()` posé
avant l'`echo`, corps `json_encode(['success' => bool, ...])` avec la clé `error`
pour les messages d'erreur.

```php
<?php
// modules/mon_module/api/actions.php
require_once __DIR__ . '/../../../API/module_boot.php'; // auth + $pdo + $user

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        echo json_encode(['success' => true, 'items' => getItems($pdo)]);
        break;

    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Méthode POST requise']);
            break;
        }
        csrf_verify(); // mutation → CSRF obligatoire (403 + exit si invalide)

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Le titre est requis']);
            break;
        }
        echo json_encode(['success' => true, 'id' => createItem($pdo, $title)]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}
```

Vérifiez toujours le **CSRF** sur les mutations (`csrf_verify()` ou `app('csrf')->validate()`), validez les entrées et utilisez des requêtes préparées. Toute requête sur une table scopée doit filtrer `etablissement_id` via `EstablishmentContext::id()`.

## Audit

```php
$audit = app('audit');

$audit->log('mon_module.action', null, ['new' => $data]);
$audit->logCreated($model);
$audit->logUpdated($model, ['champ' => $nouvelleValeur]);
$audit->logDeleted($model);
$audit->logSecurity('mon_module.access_denied', ['reason' => 'Insufficient permissions']);
```

## Cache

```php
$cache = app('cache');

$cache->put('mon_module.data', $data, 300);          // TTL en secondes
$data = $cache->get('mon_module.data');
$data = $cache->remember('mon_module.expensive', 600, fn() => expensiveCalculation());
$cache->forget('mon_module.data');
```

Pensez à invalider vos clés de cache après une écriture (cf. les services modules qui font `app('cache')->forget(...)` après un `dispatch`).

## Credits

| Champ | Type | Description |
|---|---|---|
| `author` | string | Auteur principal (défaut `"Fronote Team"`) |
| `author_url` | string | URL du profil de l'auteur |
| `contributors` | array | Liste de contributeurs |
| `license` | string | Licence (`"MIT"`, `"GPL-3.0"`, …) |

Affichés dans `admin/modules/credits.php`.

## Feature flags (types d'établissement)

Pour réserver un module à certains types d'établissement :

```json
{ "establishment_types": ["lycee", "superieur"] }
```

Pour conditionner une fonctionnalité dans le code :

```php
if (app('features')->isEnabled('stages.enabled')) {
    // ...
}
```

## Validation CI

Le script `tests/validate_manifests.php` charge tous les `module.json` via `ModuleSDK::discover()` et les passe à `validate()` ; il sort en erreur au premier manifeste invalide (utilisé en CI, fonctionne sans MySQL — `discover()`/`validate()` ne touchent jamais la base).

## Bonnes pratiques

1. **Nommage** : préfixez vos tables SQL, clés de cache et clés de traduction par le `key` du module.
2. **Schéma** : tout dans un seul `Database/install.sql` en `CREATE TABLE IF NOT EXISTS`. **Aucune migration.**
3. **Multi-établissement** : colonne `etablissement_id` + FK ; filtrez toujours sur `EstablishmentContext::id()`.
4. **Sécurité** : requêtes préparées, validation des entrées, `csrf_verify()` sur les mutations.
5. **RBAC** : vérifiez les permissions avant chaque action sensible.
6. **i18n** : ne hardcodez jamais de texte — une clé manquante s'affiche telle quelle.
7. **Audit** : loggez créations / modifications / suppressions.
8. **CSP** : pas d'inline styles ni de scripts inline — classes CSS + fichiers JS séparés.
9. **Événements** : dispatchez des objets `Modules\<M>\Events\*` via `app('hooks')?->dispatch(...)` ; abonnez-vous dans le `boot()` du ServiceProvider.
10. **Widgets** : héritez de `AbstractWidgetProvider` pour un scoping établissement par défaut.
