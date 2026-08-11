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
| `config` | (array) `ConfigServiceProvider` | Configuration applicative (notation pointée via `config()`). |
| `db` | `API\Database\Database` | Connexion PDO. `app('db')->getConnection()` → `PDO`. |
| `auth` | `API\Auth\AuthManager` | Session utilisateur : `check()`, `user()`, `attempt()`, `logout()`, `loginUser()`. |
| `auth.provider` | `API\Auth\UserProvider` | Récupération/validation des comptes (scopé établissement). |
| `auth.guard` | `API\Auth\SessionGuard` | Garde de session sous-jacente. |
| `etablissement` | `API\Services\EtablissementService` | Données établissement (`getData()`, classes, matières, périodes). |
| `super_admin` | `API\Services\SuperAdminService` | Gestion multi-établissement / super-admin. |
| `user` | `API\Services\UserService` | CRUD comptes : `create()`, `changePassword()`, `findByCredentials()`, `createResetRequest()`. |
| `email` | service mail | Envoi d'e-mails. |
| `pdf` | `API\Services\PdfService` | Génération PDF. |
| `modules` | `API\Services\ModuleService` | Modules côté UI : favoris (`getFavorites`, `toggleFavorite`, `addPageFavorite`, `reorderFavorites`), navigation. |
| `module_sdk` | `API\Services\ModuleSDK` | Découverte/activation modules : `discover()`, `provisionSql()`, `syncAll()`, `bootActiveModuleProviders()`. |
| `marketplace` | `API\Services\MarketplaceService` | Installation locale de modules (`.fmod`). |
| `csrf` | `API\Security\CSRF` | Jetons CSRF : `getToken()`, `validate()`, `verifyOrFail()`, `field()`, `meta()`, `emitNextToken()`. |
| `rate_limiter` | `API\Security\RateLimiter` | Limitation : `tooManyAttempts()`, `hit()`, `clear()`. |
| `validator` | `API\Security\Validator` | Validation de données (`validate($data, $rules)`). |
| `rbac` | `API\Security\RBAC` | Permissions : `can()`, `authorize()`, `canModule()`, `requireRole()`, `setUser()`. |
| `password_policy` | `API\Security\PasswordPolicy` | Règles mot de passe (longueur, casse, chiffres, spéciaux). |
| `translator` | `API\Services\TranslationService` | i18n : `get()`, `choice()`, `locale()`. |
| `hooks` | `API\Core\HookManager` | Bus d'événements pour modules. |
| `features` | `API\Services\FeatureFlagService` | Feature flags par type d'établissement. |
| `queue` | `API\Services\QueueService` | File de jobs générique. |
| `log` | `API\Core\Logger` | Logger structuré avec rotation (`logs/`). |
| `audit` | `API\Services\AuditService` | Journal d'audit : `log()`, `logSecurity()`. **Admin-only** (cf. note). |
| `cache` | `API\Core\CacheManager` | Cache file/redis : `get()`, `remember()`, `has()`, `forget()`, `flush()`. |
| `client_cache` | `API\Core\ClientCache` | Cache côté client (session + cookies signés HMAC). |
| `themes` | `API\Services\ThemeService` | Thèmes applicatifs. |
| `firewall` | `API\Security\IpFirewall` | Pare-feu IP. |
| `encryption` | `API\Core\Encryption` | AES‑256‑GCM (`null` si `APP_KEY` absent). |
| `backup` | `API\Services\BackupService` | Sauvegardes. |
| `updates` | `API\Services\UpdateService` | Mise à jour : `getCurrentVersion()`, `applyUpdate()`. |
| `maintenance` | `API\Services\MaintenanceService` | Mode maintenance (fichier `storage/maintenance.json`). |
| `health` | `API\Services\HealthCheckService` | Diagnostics : `runAll()`. |
| `quarantine` | `API\Services\QuarantineService` | Quarantaine sécurité marketplace. |
| `admin_dashboard` | `API\Services\Scolaire\AdminDashboardService` | Données tableau de bord admin. |
| `classes` | `API\Services\Scolaire\ClasseService` | Gestion des classes. |
| `env.loader` / `env.load_error` | `API\Core\EnvLoader` / `Throwable\|null` | Loader `.env` et cause d'échec éventuelle. |

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
| `executeQuery($sql, $params, $mode)` | SELECT/SHOW → `fetchAll` ; INSERT → `lastInsertId` ; sinon `rowCount`. |
| `tableExists($table)` | `SHOW TABLES LIKE`. |

### Authentification & session

| Helper | Effet |
|---|---|
| `requireAuth()` / `requireLogin()` | Redirige vers le login si non connecté, sinon retourne l'utilisateur. |
| `isLoggedIn(): bool` | `app('auth')->check()`. |
| `getCurrentUser()` / `checkAuth()` | Tableau utilisateur courant (ou `null`). |
| `getUserId()` | ID utilisateur courant. |
| `getUserRole()` | Rôle (`profil`/`type`) courant. |
| `getUserFullName()` / `getUserInitials()` | Affichage. |
| `login($profil,$id,$pwd)` / `logout()` / `loginUser($user)` | Connexion/déconnexion. |

### Rôles & permissions

| Helper | Effet |
|---|---|
| `requireRole(string ...$roles)` | Bloque (redirige vers `/accueil/accueil.php`) si le rôle courant n'est pas listé. |
| `requireAdmin()` | Réservé back-office (gère aussi l'accès `technicien` temporaire). |
| `isAdmin()` / `isTeacher()`/`isProfesseur()` / `isStudent()`/`isEleve()` / `isParent()` / `isVieScolaire()` | Tests de rôle. |
| `isSuperAdmin(): bool` | `SuperAdminService::isSuperAdmin()`. |
| `can($permission): bool` | `app('rbac')->can()`. |
| `authorize($permission): void` | Bloque si refusé. |
| `canModule($key, $action='view'): bool` | Permission CRUD module (ex. `canModule('notes','create')`). |
| `hasPermission($action): bool` | Pont legacy → RBAC (`'notes'` → `'notes.manage'`). |
| `parentOwnsEleve($parentId,$eleveId)` / `assertUserCanReadEleve($eleveId)` | Gardes anti-IDOR (rattachement parent↔élève, lecture des données d'un élève). |

### CSRF

| Helper | Effet |
|---|---|
| `csrf_verify(): void` | `app('csrf')->verifyOrFail()` — **à appeler sur toute mutation**. |
| `csrf_token()` | Jeton courant. |
| `csrf_field()` / `csrfField()` | `<input type="hidden">` prêt à l'emploi. |
| `csrf_meta()` | Balise `<meta>` (pour fetch JS). |
| `csrf_validate($token=null): bool` / `validateCSRFToken()` | Validation non bloquante (token explicite, header, body JSON). |
| `isAjaxRequest(): bool` | Détecte `X-Requested-With: XMLHttpRequest`. |

### i18n

| Helper | Effet |
|---|---|
| `__($cle, $params=[], $locale=null)` | Traduction. **`$params` = interpolation `:nom`, PAS un défaut.** Clé absente ⇒ la clé est renvoyée telle quelle. |
| `_n($cle, $count, $params=[], $locale=null)` | Pluralisation (variantes séparées par `\|`). |
| `currentLocale(): string` | Locale active (`fr` par défaut). |

### Établissement / divers

| Helper | Effet |
|---|---|
| `getEstablishmentId(): int` | `\API\Core\EstablishmentContext::id()` — ID établissement courant. |
| `getEtablissementData()` | Bloc d'infos établissement (info/classes/matières/périodes). |
| `redirect($path, $message=null, $type='info')` | Redirection (URL absolue ou relative à `BASE_URL`) + flash. |
| `redirectTo($url, $message=null)` | Variante simple. |
| `setFlashMessage($type,$message)` | Message flash en session. |
| `logError()` / `logInfo()` / `logSecurityEvent()` | Logging (le 3e fait aussi l'audit en base). |
| `checkRateLimit($key,$max=5,$decay=60): bool` | Garde anti-bruteforce. |
| `validate($data,$rules)` | `app('validator')->validate()`. |
| `formatDate()` / `formatDateTime()` / `getTrimestre()` | Formatage FR. |
| `sanitizeInput()` / `validateEmail()` / `validateStrongPassword()` | Helpers validation. |

> `\API\Core\EstablishmentContext::id()` lève une exception si l'établissement n'est
> pas résolu pour la session — certains endpoints (cf. `agenda_persons.php`)
> traitent ce cas en **fail-closed** (HTTP 409) plutôt qu'en retombant sur l'étab. 1.

---

## 3. Authentification & rôles

Rôles applicatifs : `administrateur`, `professeur`, `vie_scolaire`, `eleve`,
`parent`, plus `super_admin` (transverse) et un accès temporaire `technicien`.

- Connexion via `app('auth')->attempt(['type'=>..., 'login'=>..., 'password'=>...])`.
  L'identifiant utilisateur est le **login `nom.prenom`**.
- Mots de passe : **bcrypt cost 12**. Politique via `app('password_policy')`.
- Rate-limit IP + identifiant à la connexion ; anti-énumération.
- En-têtes de sécurité + CSP injectés par `templates/shared_header.php`.
- **Multi-établissement** : toute requête sur une table scopée DOIT filtrer
  `etablissement_id = getEstablishmentId()`. L'auth (`UserProvider`) est scopée.
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
$etabId = getEstablishmentId();                // scoping établissement

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
