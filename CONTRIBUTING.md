# Contributing to Fronote

Thank you for your interest in contributing to Fronote! This guide will help you get started.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR_USERNAME/Pronote.git`
3. Create a branch: `git checkout -b feature/my-feature`
4. Make your changes
5. Push and open a Pull Request

## Development Setup

### Requirements

- PHP 8.0+ (matches `composer.json` and `version.json`) with extensions: pdo_mysql, mbstring, json, openssl, intl, gd, curl, zip
- MySQL 5.7+ or MariaDB 10.3+
- Node.js 18+ (for WebSocket server)
- Apache or Nginx with mod_rewrite

### Installation

```bash
# Import database
mysql -u root -p < pronote.sql

# Copy environment file
cp .env.example .env
# Edit .env with your database credentials

# Start WebSocket server (optional)
cd websocket-server && npm install express socket.io jsonwebtoken && node server.js
```

## Code Style

### PHP
- PSR-12 coding standard
- Use namespaces under `API\`
- Type hints on all method signatures
- Document public methods with PHPDoc

### JavaScript
- ES5-compatible (no transpiler)
- Use `var` (not `let`/`const`) for browser compatibility
- Follow existing patterns in `assets/js/`

### CSS
- BEM naming convention: `.ui-card__header--collapsed`
- Use design tokens from `assets/css/tokens.css`
- No inline styles in PHP files — use utility classes from `base.css`

## Architecture

### Directory Structure

```
API/              # Backend: services, core, security, endpoints, providers
accueil/          # Dashboard (essential, root)
admin/            # Admin panel pages (essential, root)
login/            # Login flow (essential, root)
parametres/       # User settings (essential, root)
modules/<m>/      # Business modules (notes, agenda, cahierdetextes, …) — since 2.1.0
assets/           # CSS, JS, images
lang/             # Translation files (8 locales)
templates/        # Shared PHP templates (header, topbar, topbar_nav, footer)
websocket-server/ # Socket.IO server (Node.js)
cron/             # Scheduled maintenance tasks
scripts/          # CLI scripts (worker, update, check_update)
```

Per-module schema lives in `modules/<m>/Database/install.sql`, provisioned by `ModuleSDK::provisionSql()`. No separate `migrations/` directory at root.

### Key Patterns

- **IoC Container**: `app('service')` for dependency injection
- **UI Components**: `ui_card()`, `ui_table()`, etc. — see `API/UI/Components.php`
- **Translations**: `__('key')` / `_n('key', $count)` — see `API/Services/TranslationService.php`
- **Feature Flags**: `app('features')->isEnabled('module.feature')`
- **AJAX**: Use `FronoteAjax.post()` (client) + `AjaxResponse::success()` (server)

## Creating a Module

Each module has:
- A directory under `modules/<clé>/` (e.g., `modules/notes/`)
- A `module.json` manifest (key, name, icon, category, routes, permissions, widgets, `database.install`, migrations)
- A `Database/install.sql` (idempotent) provisioned by the SDK
- Translation files in `lang/{locale}/modules/{module}.json` (or `lang/` under the module)
- Optional feature flags in the `feature_flags` table

See [docs/module-sdk.md](docs/module-sdk.md) for the full guide.

## Pull Request Process

1. Ensure your code follows the style guide above
2. Update translations if you added user-facing strings
3. Add feature flags for any toggleable functionality
4. Test in both French and English locales
5. Verify RTL layout is not broken (if touching CSS)
6. Fill in the PR template completely

## Commit Messages

Use conventional commits:
- `feat: add bulk grade import`
- `fix: correct absence date validation`
- `refactor: extract shared modal logic`
- `docs: update module SDK documentation`
- `i18n: add German translations for notes module`

## Reporting Issues

Use the GitHub issue templates:
- **Bug report**: Include steps to reproduce, expected vs actual behavior
- **Feature request**: Describe the use case and proposed solution

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
