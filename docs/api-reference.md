# Référence API interne — Fronote

Aide-mémoire développeur : le conteneur DI maison, les services `app('clé')`, les
helpers globaux et les endpoints AJAX. Fronote n'expose **pas** d'API REST publique
versionnée : la quasi-totalité du code applicatif consomme directement les services
du conteneur et les helpers globaux. Les seuls points d'entrée HTTP « API » sont les
endpoints AJAX de `API/endpoints/` (consommés par le front).

> Version applicative : `version.json` → 4.0.0 (build 2026-08-11). PHP ≥ 8.0, PDO
> MySQL 8.0+ / MariaDB 10.3+. Pas de framework.

---

## 1. Conteneur DI maison

Tout est câblé dans `API/bootstrap.php`. Charger ce fichier (directement ou via
`API/Legacy/Bridge.php`) suffit à booter l'application : autoload, `.env`, session,
providers, contexte établissement.

```php
require_once __DIR__ . '/../API/bootstrap.php'; // ou .../API/Legacy/Bridge.php
```

L'instance d'application est `\API\Core\Application` (singleton accessible via
`\API\Core\Application::getInstance()`). Le helper `app()` est le point d'accès
canonique :

```php
app();              // → instance Application
app('db');          // → service résolu (singleton)
app()->make('...'); // équivalent explicite
```

Les bindings sont enregistrés soit par des **ServiceProviders** (`API/Providers/*`),
soit directement dans `bootstrap.php` via `$app->singleton(...)`. Les **services de
module** ne sont PAS dans cette liste : ils sont chargés à la demande par
`module_sdk->bootActiveModuleProviders()` (un module actif déclare ses providers
dans son `module.json`).

### Liste des services `app('clé')` (cœur)

| Clé | Classe | Rôle |
|---|---|---|
| `config` / `environment` | `ConfigServiceProvider` / env | Configuration (notation pointée via `config()`) / environnement. |
| `db` | `API\Database\Database` | Connexion PDO. `app('db')->getConnection()` → `PDO`. |
| `auth` | `API\Auth\AuthManager` | Session utilisateur : `check()`, `user()`, `attempt()`, `logout()`, `loginUser()`. |
| `auth.provider` | `API\Auth\UserProvider` | Récupération/validation des comptes (scopé établissement). |
| `auth.guard` | `API\Auth\SessionGuard` | Garde de session sous-jacente. |
| `authz` | `API\Security\Authorization` | **Moteur d'autorisation unique** : `can()`, `canOn()`, `authorize()`, `hasCapability()`, `roleKeys()`, `setUser()`. Catalogue `RoleCatalog` + déviations `rbac_grants`. |
| `etablissement` | `API\Services\EtablissementService` | Données établissement (`getData()`, classes, matières, périodes). |
| `user` | `API\Services\UserService` | CRUD comptes : `create()`, `changePassword()`, `findByCredentials()`, `createResetRequest()`. |
| `email` | `API\Services\EmailService` | Envoi d'e-mails (+ file `EmailQueueService`). |
| `modules` | `API\Services\ModuleService` | Modules côté UI : favoris, navigation, `getEnabledForRole()`, `isVisibleForRoles()`. |
| `module_sdk` | `API\Services\ModuleSDK` | Découverte/activation modules : `discover()`, `provisionSql()`, `syncAll()`, `bootActiveModuleProviders()`. |
| `marketplace` | `API\Services\MarketplaceService` | Installation locale de modules (`.fmod`). |
| `csrf` | `API\Security\CSRF` | Jetons CSRF : `getToken()`, `validate()`, `verifyOrFail()`, `field()`, `meta()`, `emitNextToken()`. |
| `rate_limiter` | `API\Security\RateLimiter` | Limitation : `tooManyAttempts()`, `hit()`, `clear()`. |
| `validator` | `API\Security\Validator` | Validation de données (`validate($data, $rules)`). |
| `password_policy` | `API\Security\PasswordPolicy` | Règles mot de passe (longueur, casse, chiffres, spéciaux). |
| `translator` | `API\Services\TranslationService` | i18n : `get()`, `choice()`, `locale()`. |
| `hooks` | `API\Core\HookManager` | Bus d'événements pour modules. |
| `features` | `API\Services\FeatureFlagService` | Feature flags par type d'établissement. |
| `log` | `API\Core\Logger` | Logger structuré avec rotation (`logs/`). |
| `audit` | `API\Services\AuditService` | Journal d'audit : `log()`, `logSecurity()`. **Admin-only** (cf. note). |
| `cache` | `API\Core\CacheManager` | Cache file/redis : `get()`, `remember()`, `has()`, `forget()`, `flush()`. |
| `client_cache` | `API\Core\ClientCache` | Cache côté client (session + cookies signés HMAC). |
| `themes` | `API\Services\ThemeService` | Thèmes applicatifs. |
| `backup` | `API\Services\BackupService` | Sauvegardes. |
| `updates` | `API\Services\UpdateService` | Mise à jour : `getCurrentVersion()`, `applyUpdate()`. |
| `maintenance` | `API\Services\MaintenanceService` | Mode maintenance (fichier `storage/maintenance.json`). |
| `health` | `API\Services\HealthCheckService` | Diagnostics : `runAll()`. |
| `admin_dashboard` | `API\Services\Scolaire\AdminDashboardService` | Données tableau de bord admin. |
| `classes` | `API\Services\Scolaire\ClasseService` | Gestion des classes. |
| `notes` `absences` `matieres` `periodes` `evenements` `devoirs` | `Modules\*\Services\*` | Services pédagogiques exposés au cœur (back-office `admin/scolaire/*`). |

> **Non exposés par un binding** (s'instancient directement là où ils servent) :
> `API\Core\Encryption` (AES-256-GCM, `null` si `APP_KEY` absent), `API\Services\PdfService`
> (exports/bulletins), `API\Services\SuperAdminService` (multi-établissement),
> `API\Services\QuarantineService` (marketplace). Les anciens bindings `rbac`, `firewall`,
> `queue`, `encryption`, `pdf`, `super_admin`, `quarantine` **n'existent plus**.

> **Note audit** : `app('audit')->log()` / `logSecurity()` sont conçus pour le
> back-office admin. Côté code applicatif, préférer le helper `logSecurityEvent()`
> (cf. §2) qui encapsule l'audit + le log système et ne plante pas si le service
> est indisponible.

---

## 2. Helpers globaux

Définis dans `API/Core/helpers.php` (chargé tôt) et surtout dans
`API/Legacy/Bridge.php` (chargé en fin de bootstrap). Tous sont protégés par
`if (!function_exists(...))`.

### Conteneur / config / env

| Helper | Effet |
|---|---|
| `app($cle = null)` | Instance de l'application, ou service résolu. |
| `config($cle, $defaut = null)` | Valeur de config, notation pointée (`'security.csrf_lifetime'`). |
| `env($cle, $defaut = null)` | Variable d'environnement (caste `true`/`false`/`null`/`empty`). |
| `e(?string $v)` | `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. |

### Base de données

| Helper | Effet |
|---|---|
| `getPDO(): PDO` | Connexion PDO (remplace l'ancien `$GLOBALS['pdo']`). |

> Il n'y a **pas** de helpers `executeQuery()`/`tableExists()` : utiliser directement
> `getPDO()->prepare(...)`. (Retirés lors du nettoyage — aucun appelant.)

### Authentification & session

| Helper | Effet |
|---|---|
| `requireAuth()` / `requireLogin()` | Redirige vers le login si non connecté, sinon retourne l'utilisateur. |
| `isLoggedIn(): bool` | `app('auth')->check()`. |
| `getCurrentUser()` / `checkAuth()` | Tableau utilisateur courant (ou `null`). |
| `getUserId()` / `getUserRole()` | ID / rôle de base (`profil`/`type`) courant. |
| `getUserFullName()` / `getUserInitials()` | Affichage. |
| `logout()` | Déconnexion. La **connexion** passe par `app('auth')->attempt()` / `->loginUser()` (les anciens wrappers globaux `login()`/`loginUser()` ont été retirés). |

### Rôles & permissions — moteur unique `authz`

Toute décision d'accès passe par `API\Security\Authorization` (`app('authz')`), via ces helpers globaux :

| Helper | Effet |
|---|---|
| `can($perm, $ctx=[]): bool` | Autorisation. Source : catalogue `RoleCatalog` + déviations `rbac_grants`. `$ctx` fournit le périmètre (`etablissement_id`, `class_id`, `student_id`, `owner_id`…). |
| `authorize($perm, $ctx=[]): void` | Comme `can()` mais **bloque** (403 JSON / redirection accueil) si refusé. |
| `canOn($perm, $type, $id, $extra=[])` / `authorizeOn(...)` | Variante **anti-IDOR** : le périmètre est déduit du type+id de ressource (`student`/`class`/`establishment`/`subject`/`self`). |
| `hasCapability($perm): bool` / `requireCapability($perm)` | Capacité **sans périmètre** (garde d'entrée de module/fonctionnalité, ex. `requireCapability('module.echanges.access')`). |
| `tenantGate($perm)` | Garde des pages **back-office** établissement (route vers `can()`). |
| `hasPermission($action): bool` | Pont legacy : `'notes'` → `can('notes.manage')`. Les `canManageX()` (ex. `canManageNotes()`) y délèguent. |
| `getEffectiveRoles(): array` | Rôles effectifs (type de compte + rôles attribués `user_roles`). |
| `enforceModuleAccess($key)` | Garde de visibilité/accès module (fail-closed, via `roles_autorises`). |
| `platformCan()` / `platformAuthorize()` / `tenantCan()` / `tenantAuthorize()` | Mondes plateforme / établissement (délèguent à `WorldContext`). |
| `isAdmin()` / `isProfesseur()` / `isEleve()` / `isParent()` / `isVieScolaire()` / `isSuperAdmin()` | Tests de type de compte. |
| `parentOwnsEleve($parentId,$eleveId)` / `assertUserCanReadEleve($eleveId)` | Gardes anti-IDOR (rattachement parent↔élève). |

> **Retirés** (aucun appelant) : `requireRole()`, `requireAdmin()`, `canModule()`, `hasRole()`, `isTechnicien()`, `isCpe()`. Utiliser `requireCapability()`/`tenantGate()`/`can()`.

### CSRF

| Helper | Effet |
|---|---|
| `csrf_verify(): void` | `app('csrf')->verifyOrFail()` — **à appeler sur toute mutation**. |
| `csrf_token()` | Jeton courant. |
| `csrf_field()` / `csrfField()` / `generateCSRFToken()` | Champ caché / génération. |
| `validateCSRFToken($token=null): bool` | Validation non bloquante (token explicite, header, body JSON). |

> Pour la meta CSRF côté fetch JS : `app('csrf')->meta()`. (Le helper global `csrf_meta()` a été retiré.)

### i18n

| Helper | Effet |
|---|---|
| `__($cle, $params=[], $locale=null)` | Traduction. **`$params` = interpolation `:nom`, PAS un défaut.** Clé absente ⇒ la clé est renvoyée telle quelle. |

> La pluralisation passe par `app('translator')->choice(...)`. (Les helpers globaux `_n()` et `currentLocale()` ont été retirés — utiliser `app('translator')->locale()`.)

### Établissement / divers

| Helper | Effet |
|---|---|
| `getEtablissementData()` | Bloc d'infos établissement (info/classes/matières/périodes). |
| `redirect($path, $message=null, $type='info')` | Redirection (URL absolue ou relative à `BASE_URL`) + flash. |
| `redirectTo($url, $message=null)` / `setFlashMessage($type,$message)` | Redirection simple / message flash. |
| `logError()` / `logInfo()` / `logSecurityEvent()` | Logging (le 3e fait aussi l'audit en base). |
| `formatDate()` / `formatDateTime()` / `getTrimestre()` | Formatage FR. |
| `e($v)` / `csp_nonce()` / `asset_url()` / `asset_bust()` / `deny_access()` / `json_error()` | Utilitaires `helpers.php` (échappement, nonce CSP, URL d'assets versionnées, refus, erreur JSON). |

> **ID établissement** : utiliser directement `\API\Core\EstablishmentContext::id()` (le helper `getEstablishmentId()` a été retiré). Idem `checkRateLimit()`/`validate()`/`sanitizeInput()`/`validateEmail()`/`validateStrongPassword()` : retirés — passer par `app('rate_limiter')`, `app('validator')`, `filter_var()` / `app('password_policy')`.

> `\API\Core\EstablishmentContext::id()` lève une exception si l'établissement n'est
> pas résolu pour la session — certains endpoints (cf. `agenda_persons.php`)
> traitent ce cas en **fail-closed** (HTTP 409) plutôt qu'en retombant sur l'étab. 1.

---

## 3. Authentification & rôles

**Types de comptes** (rôle de base) : `administrateur`, `professeur`, `vie_scolaire`,
`eleve`, `parent`, plus `super_admin` (transverse). Au-dessus, un **catalogue de rôles**
en code (`API\Security\RoleCatalog`, ~110 rôles) attribués via `admin/users/roles.php` ;
les permissions par rôle = catalogue + déviations globales `rbac_grants` (éditeur plateforme
`platform/roles.php`). **2FA obligatoire** pour les rôles à responsabilité (enrôlement forcé
`login/setup_2fa.php`).

- Connexion via `app('auth')->attempt(['type'=>..., 'login'=>..., 'password'=>...])`.
  L'identifiant utilisateur est le **login `nom.prenom`**.
- Mots de passe : **bcrypt cost 12**. Politique via `app('password_policy')`.
- Rate-limit IP + identifiant à la connexion ; anti-énumération.
- En-têtes de sécurité + CSP injectés par `templates/shared_header.php`.
- **Multi-établissement** : toute requête sur une table scopée DOIT filtrer
  `etablissement_id = \API\Core\EstablishmentContext::id()`. L'auth (`UserProvider`) est scopée.
  Un onboarding obligatoire (`API/onboarding_gate.php`) force la configuration tant
  que l'établissement porte le code `'default'`.

---

## 4. Endpoints AJAX (`API/endpoints/`)

Ce sont les seuls points d'entrée HTTP « API ». Réponses en JSON. Convention
courante : `{ "success": true, ... }` / `{ "success": false, "error": "..." }`
(le format exact varie d'un endpoint à l'autre — voir ci-dessous). Les mutations
exigent un jeton CSRF.

| Endpoint | Méthode | Auth | Description |
|---|---|---|---|
| `messagerie.php` | GET/POST | Session | API centrale messagerie. Routage par `?resource=&action=`. |
| `agenda_persons.php` | GET | Session (admin/prof/VS) | Recherche de personnes pour l'agenda. |
| `favorites.php` | POST | Session | Modules/pages favoris (`list`/`toggle`/`add_page`/`remove`/`reorder`). |
| `push_subscribe.php` | POST | Session | Abonnement Web Push (`subscribe`/`unsubscribe`/`vapid_key`). |
| `cookie_consent.php` | POST | — | Enregistre le consentement cookies (`level=all\|essential`). |
| `ws_token_refresh.php` | GET | Session | Émet un JWT frais pour la connexion WebSocket. |
| `health.php` | GET | Optionnelle | État système (`HealthCheckService::runAll`). Voir §5. |
| `test_catalog.php` | GET | Session + flag | Catalogue de modules de test (`ALLOW_TEST_MODULES=true`). |

### `messagerie.php` (la plus riche)

```
GET/POST  /API/endpoints/messagerie.php?resource=<resource>&action=<action>
```

- **Resources** : `conversations`, `messages`, `participants`, `notifications`,
  `search`, `reactions`.
- Auth obligatoire (`checkAuth()` → 401 sinon) ; **CSRF** (`csrf_verify()`) pour
  `POST/PUT/DELETE/PATCH` ; rate-limiting par utilisateur.
- Garde anti-IDOR `requireConversationMembership()` : l'appelant doit être
  participant actif de la conversation.
- Exemples d'actions : `conversations`→`list|get|create|mark_read|archive|delete|bulk` ;
  `messages`→`list|send|edit|delete|pin|get_new|read_status|mark_read` ;
  `participants`→`list|available|add|remove|promote|demote`.

Réponse type :

```json
{ "success": true, "messages": [ ... ], "has_more": false, "pinned": [ ... ] }
```

### `favorites.php`

```
POST /API/endpoints/favorites.php
Content-Type: application/json
{ "action": "toggle", "module_key": "notes", "csrf_token": "..." }
```

CSRF validé via `app('csrf')->validate($token)` puis rotation
`emitNextToken()`. Renvoie la liste à jour des favoris.

---

## 5. Health check

```
GET /API/endpoints/health.php
```

Pas d'auth par défaut. Si `HEALTH_TOKEN` est défini dans `.env`, un
`Authorization: Bearer <token>` est exigé (sinon 401). Code HTTP **200** si sain,
**503** sinon.

```json
{ "healthy": true, "checks": { "database": "ok", "...": "..." } }
```

---

## 6. Mise à jour applicative

Un **seul bouton** côté admin (`admin/systeme/update.php`) :

```php
app('updates')->applyUpdate();
```

Séquence : `git fetch` + `git reset --hard origin/<GITHUB_BRANCH>` →
`API\Services\SchemaSyncService::sync()` (réconciliation déclarative idempotente :
CREATE des tables manquantes + ADD COLUMN des colonnes manquantes, lues depuis les
`install.sql`/`pronote.sql` — additif : **jamais de DROP**) →
`API\Services\MigrationRunner::migrate()` (migrations de données versionnées) →
`module_sdk->syncAll()` → flush cache.

Config `.env` : `GITHUB_BRANCH` (défaut `main`), `GIT_BINARY` (chemin git si hors
PATH).

> Deux mécanismes complémentaires cohabitent. Le **schéma** est déclaratif
> (`modules/<m>/Database/install.sql` + `pronote.sql`, réconcilié **additivement**
> par `SchemaSyncService` ; `module_sdk->provisionSql($key)` exécute `install.sql`
> à l'activation). Les **transformations de données** que SchemaSync ne sait pas
> faire passent par des migrations versionnées (`database/migrations/*.php` +
> `API\Services\MigrationRunner`, journal `schema_migrations`), jouées par
> `MigrationRunner::migrate()` juste après la réconciliation du schéma. Ce runner
> n'a **pas** de wrapper CLI : il ne tourne que via le bouton de mise à jour. Il
> n'existe ni `scripts/migrate.php`, ni table `module_migrations`/`core_migrations`.
> Guide de référence : [docs/UPDATING.md](UPDATING.md).

---

## 7. Modèle de réponse côté serveur

Convention unique (référence : `API/endpoints/messagerie.php`) — la réponse JSON est
construite à la main :

- `header('Content-Type: application/json')` en tête de script ;
- corps `echo json_encode(['success' => true|false, ...])`, avec la clé `error` pour
  le message d'erreur lisible ;
- code HTTP posé à la main via `http_response_code()` **avant** l'`echo`
  (200 implicite en succès) ;
- `exit` après une garde (auth, CSRF, méthode) pour ne pas poursuivre le script.

> L'ancien helper `\API\Core\AjaxResponse` a été retiré de la baseline v4 : ne plus
> l'utiliser dans le nouveau code.

### Codes HTTP usuels

| Code | Sens |
|---|---|
| `200` | Succès |
| `400` | Requête invalide |
| `401` | Non authentifié |
| `403` | CSRF invalide ou permissions insuffisantes |
| `404` | Ressource inconnue |
| `405` | Méthode non autorisée |
| `409` | Conflit (ex. établissement non résolu) |
| `429` | Trop de requêtes |
| `500` | Erreur serveur |
| `503` | Maintenance / health KO |

---

## 8. Mémo : écrire un nouvel endpoint AJAX

```php
<?php
require_once __DIR__ . '/../bootstrap.php';   // boot complet
requireAuth();                                 // 401 + redirection sinon

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

csrf_verify();                                 // mutation → CSRF obligatoire

$pdo    = getPDO();
$etabId = \API\Core\EstablishmentContext::id(); // scoping établissement

// ... logique métier, requêtes scopées par etablissement_id ...

echo json_encode(['success' => true, 'data' => $data]);
```

Points de vigilance :
- **Toujours** scoper les requêtes par `etablissement_id`.
- **Toujours** vérifier CSRF sur les mutations (`csrf_verify()` ou
  `app('csrf')->validate($token)` + `emitNextToken()` pour les actions répétées).
- Pour les ressources liées à un élève, passer par `assertUserCanReadEleve()`
  (anti-IDOR parent/élève).
- Toute nouvelle chaîne d'interface doit avoir sa clé i18n (sinon `__()` renvoie la
  clé brute).
