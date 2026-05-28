# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Fronote — French school management system (équivalent Pronote). PHP 8.0+ vanilla (no framework), MySQL 5.7+/MariaDB, Apache + `mod_rewrite`. Optional Node.js WebSocket server. ~60 modules, ~240 SQL tables, IoC container, PSR-4 autoloading, 8-locale i18n. README.md is the canonical developer doc — read it for any deep dive.

## Common commands

```bash
# PHP dependencies
composer install --optimize-autoloader            # dev
composer install --no-dev --optimize-autoloader   # prod
composer dump-autoload --optimize                 # after adding classes

# WebSocket server (websocket-server/ has no package.json — bootstrap each deploy)
cd websocket-server
npm init -y && npm install express socket.io jsonwebtoken
JWT_SECRET=xxx API_SECRET=yyy node server.js      # dev
pm2 start server.js --name fronote-ws             # prod

# Background workers (run via cron in prod)
php scripts/worker.php                # job queue (every minute)
php cron/daily_maintenance.php        # nightly cleanup
php cron/hourly_maintenance.php
php scripts/update.php                # manual git-based update
php scripts/check_update.php          # update check (every 6h via cron)

# DB schema work
# Edit pronote.sql directly. There is NO migration system — pronote.sql is the
# single source of truth. Bump version.json when schema changes.

# Install / reinstall (browser only, no CLI)
# Visit /install.php — gated to LAN by default, requires deletion of install.lock to re-run.
```

There is no test suite, linter, or CI configured. No PHPUnit, no static analysis. Manual testing only.

## Architecture

### Request lifecycle

Every module page follows this exact pattern:

```php
require_once __DIR__ . '/../API/core.php';   // → bootstrap.php (autoload, container, env, session)
requireAuth();                                // helper from API/Core/helpers.php
requireRole('professeur');                    // optional

$pageTitle = 'X'; $activePage = 'mod'; $rootPrefix = '../';
include __DIR__ . '/../templates/shared_header.php';   // generates CSP nonce, CSRF token, theme
include __DIR__ . '/../templates/shared_sidebar.php';  // role-filtered modules
include __DIR__ . '/../templates/shared_topbar.php';
// ... module HTML ...
include __DIR__ . '/../templates/shared_footer.php';
```

`API/core.php` is a one-line legacy shim → `API/bootstrap.php`. Bootstrap is **idempotent** (guarded by `PRONOTE_BOOTSTRAP_LOADED`). It sets `INSTANCE_ID` (md5 of install path, first 8 chars) and `INSTANCE_COOKIE_PATH` so multiple Fronote installs on one server stay isolated.

### API layer (`API/`)

| Subdir | Purpose |
|--------|---------|
| `Core/` | `Container` (IoC), `helpers.php` (`app()`, `env()`, `requireAuth()`, `getPDO()`, `getUserId()`, `getUserRole()`, `logAudit()`), `EnvLoader`, `ErrorHandler`, `Facade`, `HookManager`, `Logger` |
| `Core/Facades/` | Static facades: `CSRF`, `Auth`, `DB`, `Log` |
| `Auth/` | `AuthManager` (resolves logins via `v_users` view), `SessionGuard`, `TokenGuard`, `OAuthGuard`, `UserProvider` |
| `Security/` | `CSRF` (rotating token bucket, max 10, 1h TTL, single-use), `RateLimiter` (table `api_rate_limits`), `RBAC`, `Validator`, `IpFirewall`, `CspManager` |
| `Services/` | All business services (`ModuleService`, `FileUploadService`, `ProfileService`, `FeatureFlagService`, `TranslationService`, `WebPushService`, `UpdateService`, etc.) |
| `Database/` | `Database` singleton (`getConnection()`, `ERRMODE_EXCEPTION`, `utf8mb4`), `QueryBuilder` |
| `endpoints/` | Centralized AJAX/REST endpoints (`messagerie.php`, `webhook_update.php`, `agenda_persons.php`, `health.php`, `push_subscribe.php`, `ws_token_refresh.php`) |
| `Providers/` | Service providers registered into the container |

Resolve services via `app('csrf')` / `app('auth')` / `app('modules')` / `app('upload')` or via static facades (`\API\Core\Facades\CSRF::generate()`).

### Modules

Each top-level directory like `notes/`, `agenda/`, `messagerie/`, `cahierdetextes/` is a self-contained module: `module.php` entry point + optional `assets/`, `includes/`, `api/`. To add a module: insert into `modules_config` SQL table **and** add the route to `ModuleService::$routeMap`. Visibility per role lives in `modules_config.roles_autorises` (JSON column, overrides hardcoded `$roleVisibility` in `ModuleService`).

Roles: `administrateur`, `professeur`, `eleve`, `parent`, `personnel`, `vie_scolaire`, `technicien`. The user's role string lives in session and is matched directly against `roles_autorises`.

### Database

- **Single source of truth**: `pronote.sql`. Edit it directly. No Alembic/migrations. Bump `version.json` alongside any schema change.
- **Auth view**: `v_users` UNIONs `administrateurs`/`professeurs`/`eleves`/`parents`/`personnel` so `AuthManager` can resolve logins without knowing the user type.
- Always go through `getPDO()` or `Database::getConnection()` — both force `ERRMODE_EXCEPTION`. Manual `new PDO()` will silently swallow errors.
- Use prepared statements only (no string concatenation in SQL).

### Templates & assets

`templates/shared_header.php` reads these globals from the calling page: `$pageTitle` (required), `$rootPrefix` (required), `$activePage`, `$extraCss`, `$extraHeadHtml`, `$headerExtraActions`, `$isAdmin`. It auto-generates the CSP nonce, CSRF meta tag, and pulls the user's theme from `user_settings`.

CSS load order: `assets/css/base.css` → `tokens.css` → `theme-classic.css` → optional `theme-glass.css` overlay. Glass is never loaded standalone. Per-module CSS lives under `<module>/assets/css/`.

### WebSocket

`websocket-server/server.js` is a Node.js Socket.IO server. PHP signs a JWT (HS256, `JWT_SECRET`) and injects `window.FRONOTE_WS = {url, token, userId, userType}` from `shared_header.php`. PHP-side notifications are sent via `POST http://localhost:3000/notify/<event>` with shared `API_SECRET` in the JSON body — the Node server then `io.to(...).emit(...)`s. If the WS server is down, the client falls back to HTTP polling automatically. Endpoints documented in README.md → "Routes HTTP internes".

### CSRF specifics

`API\Security\CSRF` uses a rotating token bucket in `$_SESSION['csrf_tokens']` (array, max `CSRF_MAX_TOKENS=10`, lifetime `CSRF_LIFETIME=3600`s). Tokens are **single-use** — `validate()` consumes the token. For non-critical concurrent AJAX, use `check()` (non-destructive) instead. Legacy `$_SESSION['csrf_token']` kept for backward compat in `shared_header.php`.

### Updates / deployment

Each client gets an isolated install with its own `GITHUB_WEBHOOK_SECRET`. GitHub push triggers a webhook → `API/endpoints/webhook_update.php` validates HMAC-SHA256 → runs `scripts/update.php` (`git pull` + `composer install` + bootstrap smoke test, with auto-rollback of `.env` on failure). Logs land in `temp/update.log`.

## Project conventions

- **No comments unless they explain *why*.** Names should carry the *what*.
- **Always `requireAuth()` first** in any new module entry point.
- **Always validate CSRF** on POST/DELETE: `\API\Core\Facades\CSRF::validate($_POST['csrf_token'] ?? '')`.
- **Always escape output**: `htmlspecialchars($value)`. Server outputs JSON with `header('Content-Type: application/json')` after CSRF check.
- **Uploads go through `FileUploadService`** with a context (`devoirs` | `messagerie` | `justificatifs`). Don't touch `$_FILES` directly.
- **Audit sensitive actions**: `logAudit('action', 'entity', $id, $oldData, $newData)`.
- **Schema changes**: edit `pronote.sql` + bump `version.json`. Never create separate migration files.
- **Two themes** (classic, glass) × **dark mode** must both work for any new UI — use existing tokens in `assets/css/tokens.css`, never hardcode colors.
- **Messagerie is OFF by default** for new installs (security stance). Don't re-enable it in seed data.

## Gotchas

- `composer dump-autoload --optimize` after creating new classes under `API\` or `Pronote\`.
- `pronote.sql`, `.env`, `install.lock`, `*.bak`, `*.log` are blocked by `.htaccess` — don't link to them publicly.
- `install.php` is LAN-gated unless `ALLOWED_INSTALL_IP` is set in `.env`. Delete `install.lock` to re-run (wipes DB).
- `websocket-server/node_modules/` and `package.json` are not versioned — re-`npm install` per environment.
- The `_GET`/`_POST`-driven action style in `API/endpoints/messagerie.php` uses `match($action)` dispatch — follow the same pattern for new endpoints.
- `INSTANCE_ID` salts cookie/session names so two installs on the same domain don't collide. Don't override session names manually.
