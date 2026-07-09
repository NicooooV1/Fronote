# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 3.2.x   | :white_check_mark: |
| 3.1.x   | :white_check_mark: |
| < 3.1   | :x:                |

## Reporting a Vulnerability

**Do NOT open a public issue for security vulnerabilities.**

Instead, please report security issues by emailing the project maintainers directly. Include:

1. Description of the vulnerability
2. Steps to reproduce
3. Potential impact
4. Suggested fix (if any)

We will acknowledge your report within 48 hours and provide a timeline for a fix.

## Security Measures

Fronote implements the following security measures:

### Authentication & Authorization
- RBAC (Role-Based Access Control) with 7 user types: `administrateur`, `professeur`, `eleve`, `parent`, `personnel`, `vie_scolaire`, `technicien`
- Multi-establishment isolation enforced via `API\Core\EstablishmentContext::id()` on every business query
- Progressive rate limiting on login (exponential backoff)
- Optional 2FA (TOTP-based)
- Remember-me tokens with secure storage
- Session fixation protection
- Force password change on first login

### CSRF Protection
- Rotating single-use token bucket implemented in `API\Security\CSRF` (max 10 tokens, 1h TTL)
- Facade: `\API\Core\Facades\CSRF::generate()` / `validate()` / `check()`
- All POST forms include CSRF tokens
- AJAX requests send `X-CSRF-TOKEN` header
- For concurrent AJAX sharing the meta-tag token, endpoints use the **non-destructive** `check()` instead of `validate()` to avoid 403 on parallel requests

### Content Security Policy
- Enforced CSP still allows `'unsafe-inline'` for `script-src`/`style-src` (inline handlers/styles not yet externalized); `'unsafe-eval'` and `http:` sources removed, `object-src 'none'`
- Strict nonce-based CSP (`'nonce-…'` + `'strict-dynamic'`) shipped in **Report-Only** to measure remaining inline surface — migration in progress before switching to enforce
- `frame-ancestors 'none'` (no iframing)
- `form-action 'self'`

### Input Validation
- Prepared statements for all SQL queries (PDO)
- HTML escaping via `e()` / `htmlspecialchars()`
- File upload validation (type, size, extension)

### Marketplace Security
- SHA-256 integrity verification for downloaded packages
- Static analysis scanner (`API/Security/ModuleScanner.php`)
- Blocked dangerous functions: `eval`, `exec`, `system`, `shell_exec`, etc.
- Quarantine system for suspicious modules
- Automatic backup before module installation

### WebSocket Security
- JWT-based authentication for WebSocket connections
- Token rotation every 20 minutes
- Rate limiting: 30 events/min per connection
- Room membership verification via HTTP callback
- Heartbeat with 90-second timeout

### Headers
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- HSTS in production (HTTPS only)

## Dependencies

- Font Awesome (CDN with SRI)
- Socket.IO client (CDN with SRI)
- **Server-side**: Composer is used to autoload classes; production dependencies are kept intentionally minimal (`composer install --no-dev --optimize-autoloader`). See `composer.json` for the exact list and run `composer audit` regularly.
- **Marketplace scanner caveat**: `API/Security/ModuleScanner.php` performs static `token_get_all()` checks that block a denylist of dangerous calls. It is a **layered defense, not a sandbox** — dynamic invocation (variable functions, `assert($code)`, reflection, string concat to bypass denylist) can defeat it. Trust marketplace modules only from sources you control.
