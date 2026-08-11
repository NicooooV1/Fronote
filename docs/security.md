# Sécurité — Guide Développeur & Admin

## Vue d'ensemble

Fronote empile plusieurs couches de sécurité : authentification par session durcie,
anti-bruteforce, CSRF, contrôle d'accès basé sur les rôles (RBAC), isolation
multi-établissement, en-têtes HTTP + CSP, chiffrement at-rest et outillage RGPD.

Ce document décrit les mécanismes **réellement implémentés** (chemins de fichiers
cités) et les exigences pour les développeurs de modules.

Code de référence :
- `API/Auth/` — `SessionGuard`, `AuthManager`, `UserProvider`, `OAuthGuard`
- `API/Security/` — `CSRF`, `RBAC`, `RateLimiter`, `PasswordPolicy`, `ModuleScanner`, `IpFirewall`, `Validator`
- `API/Core/Encryption.php`, `API/Core/EstablishmentContext.php`
- `templates/shared_header.php` (en-têtes + CSP)
- `API/Legacy/Bridge.php` (helpers globaux : `csrf_verify()`, `requireRole()`, `getCurrentUser()`…)
- `login/index.php` (flux de connexion), `rgpd/` (anonymisation)

Les services sont exposés via le conteneur DI : `app('auth')`, `app('csrf')`,
`app('rbac')`, `app('rate_limiter')`, `app('password_policy')`, `app('encryption')`,
`app('user')`.

---

## Authentification (session)

L'authentification navigateur est gérée par `API\Auth\SessionGuard` (exposé via
`app('auth')`, voir `API\Auth\AuthManager`). L'identifiant utilisateur est le
**login `nom.prenom`** (ou l'adresse e-mail — colonne `mail`). Le mot de passe est
hashé en **bcrypt cost 12** (`PasswordPolicy::hash()` → `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`).

### Après connexion

`SessionGuard::login()` :

- **régénère l'ID de session** (`session_regenerate_id(true)`) pour prévenir la
  fixation de session ;
- ne stocke **jamais** le hash du mot de passe en session (`unset($safeUser['mot_de_passe'])`) ;
- fixe le périmètre établissement (`$_SESSION['etablissement_id']` + `EstablishmentContext::set()`) ;
- enregistre la session dans `session_security` (best-effort) pour l'outil admin
  « Sessions actives » (`admin/users/sessions.php`).

```php
// Contenu de session après login
$_SESSION['user_id']          = 42;
$_SESSION['user_type']        = 'professeur';   // administrateur|professeur|vie_scolaire|eleve|parent
$_SESSION['user']             = [...];           // sans mot_de_passe
$_SESSION['etablissement_id'] = 1;
```

### Authentification à deux facteurs (2FA) — obligatoire pour les rôles à responsabilité

Tout **rôle à responsabilité accédant à des données** (`professeur`, `vie_scolaire`,
`administrateur`, `super_admin`) valide un **second facteur TOTP à chaque nouvelle connexion**.
Élèves et parents en sont exemptés.

- **Tolérance 1 h par appareil** : après une validation réussie, l'appareil n'est plus sollicité
  pendant une heure (cookie signé HMAC — `API\Security\TwoFactorTrust`, clé = `APP_KEY`).
- **Enrôlement forcé** : un compte à responsabilité sans 2FA configuré est redirigé vers
  `login/setup_2fa.php` (clé TOTP + **codes de secours** à usage unique) avant toute session ;
  les comptes déjà équipés passent par `login/verify_2fa.php`.
- Anti-bruteforce du second facteur **persistant** (`login_attempts`, clé `2fa:<type>:<id>`).
- Service : `API\Services\TwoFactorService` (base32, période 30 s, 6 chiffres, SHA-1).
- **Bypass de test** : `TEST_2FA_BYPASS=1` contourne le 2FA **uniquement** pour les comptes `sim.*`
  (harnais de tests). À ne **jamais** activer en production réelle.

### Vérifier l'authentification

Helpers globaux (définis dans `API/Legacy/Bridge.php`) :

```php
requireLogin();            // alias requireAuth() — redirige vers /login si non authentifié
$user = getCurrentUser();  // null si non authentifié (alias checkAuth())
isLoggedIn();              // bool
getUserRole();             // 'administrateur' | 'professeur' | ...
```

### Déconnexion (`SessionGuard::logout()`)

L'ordre des opérations est important :

1. **`clearRememberToken()` AVANT de vider la session** — sinon `login/index.php`
   restaurerait automatiquement la session via le cookie `remember_<INSTANCE_ID>`
   au prochain chargement et le logout « ne fonctionnerait pas » ;
2. marque la session inactive dans `session_security` ;
3. vide `$_SESSION`, supprime le cookie de session, appelle `session_destroy()`.

```php
logout();                  // helper global → app('auth')->logout()
```

### « Se souvenir de moi » (remember-me)

Implémenté dans `API\Services\UserService` (`createRememberToken` / `validateRememberToken` /
`clearRememberToken`). Caractéristiques de sécurité :

- le token (32 octets aléatoires) n'est **jamais stocké en clair** : seul son
  **SHA-256** est en base (`remember_tokens.token_hash`) ;
- cookie `remember_<INSTANCE_ID>` : `httponly`, `samesite=Lax`, `secure` si HTTPS,
  durée 30 jours ;
- **rotation à chaque usage** : `validateRememberToken()` consomme le token de
  façon atomique (`SELECT ... FOR UPDATE` + `DELETE` en transaction) puis en émet
  un nouveau — un token volé ne peut être rejoué.

---

## Anti-énumération de comptes (timing)

`AuthManager::attemptAndGetUser()` appelle `dummyVerify()` lorsqu'**aucun compte ne
correspond** : une vérification bcrypt cost 12 factice est exécutée pour que le
temps de réponse soit comparable à celui d'un mot de passe erroné sur un compte
existant. Cela empêche l'énumération de comptes par canal temporel.

```php
private function dummyVerify(): void {
    password_verify('dummy_password', '$2y$12$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy');
}
```

---

## Anti-bruteforce (rate-limit login)

Deux mécanismes coexistent.

### 1. Lockout progressif du login (`UserService::checkLoginRateLimit`)

Utilisé par `login/index.php`. La limitation s'applique **à la fois par IP et par
identifiant** (anti brute-force ciblé distribué sur plusieurs IP) ; le délai le
plus long des deux dimensions est retenu. Les tentatives échouées sont stockées
dans la table `login_attempts` (`ip`, `identifier`, `attempted_at`).

Paliers (`UserService::checkRateTier`) :

| Tentatives (fenêtre) | Verrouillage |
|---|---|
| 5 en 15 min  | 15 min |
| 10 en 1 h    | 1 h    |
| 20 en 24 h   | 24 h   |

```php
$wait = $userService->checkLoginRateLimit($ip, $username); // minutes restantes (0 = autorisé)
if ($wait > 0) { /* "Trop de tentatives. Réessayez dans {$wait} minute(s)." */ }

$userService->recordFailedAttempt($ip, $username);  // sur échec
$userService->cleanOldAttempts();                   // purge > 1 h
```

> Robustesse : si la colonne `identifier` est absente (très ancienne base), le code
> retombe silencieusement sur une limitation par IP seule sans bloquer la connexion.

### 2. Limiteur générique (`API\Security\RateLimiter`, `app('rate_limiter')`)

Limiteur à fenêtre glissante stocké en base (`api_rate_limits`), clé = `hash('sha256', $key . '|' . $ip)`.
Upsert atomique (`INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1`) pour
éviter la race SELECT→INSERT.

```php
$rl = app('rate_limiter');
$rl->setMaxAttempts(5)->setDecayMinutes(1);
if ($rl->tooManyAttempts('mon_module.action')) { /* HTTP 429 */ }
$rl->hit('mon_module.action');
$rl->clear('mon_module.action'); // reset après succès
```

**IP cliente & proxies.** `getClientIp()` n'utilise `X-Forwarded-For` / `X-Real-IP`
que si `REMOTE_ADDR` figure dans `TRUSTED_PROXIES` (`.env`). Sinon seul
`REMOTE_ADDR` est utilisé, pour éviter le spoofing d'en-tête.

> ⚠️ Il n'existe **pas** de classe `API\Middleware\RateLimitMiddleware` ni de
> méthodes `handle()/handleStrict()` : utilisez `app('rate_limiter')` ou
> `UserService::checkLoginRateLimit()`.

---

## Protection CSRF

Géré par `API\Security\CSRF` (`app('csrf')`). **Toutes les mutations (POST/PUT/DELETE)
doivent être protégées.**

### Modèle de token

- Token = `bin2hex(random_bytes(32))` (64 hex) ;
- **token-bucket en session** (`$_SESSION['csrf_tokens']`), max 10 tokens, durée de
  vie 3600 s ;
- **usage unique** : `validate()` supprime le token après vérification ;
- après une validation réussie via `verifyOrFail()`, un en-tête `X-Csrf-Token-Next`
  contenant un token frais est émis pour que les clients AJAX (`fronote-ajax.js`)
  fassent tourner leur copie.

Le `meta[name="csrf-token"]` injecté par `shared_header.php` contient ce token
tournant ; un token hérité stable est aussi conservé dans `$_SESSION['csrf_token']`
pour les formulaires legacy.

### Formulaires HTML

```php
<form method="POST">
    <?= csrf_field() ?>   <!-- <input type="hidden" name="csrf_token" value="..."> -->
    <!-- ... -->
</form>
```

### Requêtes AJAX

```javascript
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
});
```

`validateFromRequest()` cherche le token, dans l'ordre : `$_POST['csrf_token']`,
`$_POST['_csrf_token']`, l'en-tête `X-CSRF-Token`, puis le corps JSON
(`csrf_token`/`_csrf_token`).

### Validation côté serveur

```php
csrf_verify();   // app('csrf')->verifyOrFail() : HTTP 403 + exit si invalide (JSON si AJAX)
                 // émet aussi X-Csrf-Token-Next

// Validation manuelle sans interruption :
if (!csrf_validate()) { /* token absent/invalide */ }
```

Helpers globaux (`API/Legacy/Bridge.php`) : `csrf_field()`, `csrf_meta()`,
`csrf_token()`, `csrf_verify()`, `csrf_validate()`, ainsi que les alias legacy
`generateCSRFToken()` / `validateCSRFToken()`.

---

## RBAC — Contrôle d'accès basé sur les rôles

Centralisé dans `API\Security\RBAC` (`app('rbac')`). L'utilisateur courant est
injecté automatiquement à la résolution du service (`setUser()` depuis la session).

### Rôles

`administrateur`, `vie_scolaire`, `professeur`, `parent`, `eleve` (+ `super_admin`
hors de cette matrice, géré séparément).

**Hiérarchie** (`ROLE_HIERARCHY`) : `administrateur` hérite des permissions de
`vie_scolaire` et `professeur`. Les autres rôles n'héritent de rien.

### Deux sources de permissions

1. **Matrice statique** (`RBAC::PERMISSIONS`) — uniquement les permissions
   **système/transversales** : `admin.*`, `rgpd.*`, `notifications.view`,
   `parametres.view`.
2. **Permissions dynamiques en base** (`rbac_permissions`) — toutes les permissions
   **module** (`notes.*`, `absences.*`, `devoirs.*`…). Elles sont déclarées dans
   `module.json` et injectées par `ModuleSDK::syncPermissions()` à l'activation du
   module. `can()` tombe en fallback DB pour toute permission absente de la matrice
   statique.

### Attribution centralisée rôle → permissions (`rbac_grants`)

Les permissions **associées à un rôle** se pilotent depuis la **plateforme**
(`platform/roles.php`) via la table globale **`rbac_grants`**, possédée par l'opérateur et
appliquée à **tous les établissements**. `API\Security\Authorization::roleGrants()` lit ces
surcharges globales, avec repli sur le **catalogue de rôles** (`API\Security\RoleCatalog`) si
aucune surcharge n'existe. Objectif : *un rôle = un jeu de permissions*, **modifiable au niveau
plateforme** sans reporter cette charge sur les dirigeants d'établissement — le panneau
d'administration n'expose plus qu'une **vue en lecture seule** des permissions effectives
(catalogue + surcharges). La table `rbac_permissions` (ancien modèle par établissement) subsiste
pour rétrocompatibilité.

### API

```php
$rbac = app('rbac');

$rbac->can('admin.users');              // bool (avec héritage de rôles)
$rbac->canAny(['notes.view', 'notes.manage']);
$rbac->canAll([...]);
$rbac->authorize('admin.modules');      // HTTP 403 (JSON) ou redirection + exit si refusé
$rbac->requireAdmin();                  // back-office : administrateur uniquement
$rbac->requireRole('professeur', 'vie_scolaire');

// Permissions CRUD par module (table module_permissions, colonnes can_view/create/edit/...)
$rbac->canModule('messagerie', 'send');
$rbac->canModule('notes', 'create');
```

Helpers globaux équivalents : `can()`, `authorize()`, `canModule()`,
`requireRole(...)` (ce dernier, dans `Bridge.php`, redirige vers l'accueil avec un
message explicite et journalise le refus).

### Journalisation des refus

`RBAC::denyAccess()` écrit dans `error_log` **et** dans la table `audit_log`
(action `access_denied`, modèle `rbac`, avec permission/URI/IP/user-agent), puis
renvoie un 403 JSON ou redirige selon le type de requête.

---

## Isolation multi-établissement (scoping)

Une même installation héberge plusieurs établissements ; l'isolation des données
est une **exigence de sécurité**, pas seulement fonctionnelle.

- L'établissement courant est résolu par `\API\Core\EstablishmentContext::id()`.
  Le contexte est fixé au login depuis `user.etablissement_id`.
- **Pas de fallback silencieux** : si aucun scope n'est défini et qu'il existe
  plusieurs établissements, `id()` lève une `RuntimeException` plutôt que de
  deviner (sécurité de périmètre). S'il n'existe qu'un seul établissement, il est
  retenu.
- Toute table métier porte `etablissement_id` ; **toute requête de liste / recherche
  / agrégat doit la filtrer**.
- Les identifiants venant du client (`?eleve=`, `?id=`) doivent être **revalidés**
  contre le périmètre autorisé (établissement et, pour un parent, ses enfants)
  avant toute lecture — ne jamais faire confiance à un ID fourni (risque IDOR).

```php
use API\Core\EstablishmentContext;

$stmt = $pdo->prepare("SELECT * FROM ma_table" . EstablishmentContext::placeholderWhere());
$stmt->execute([EstablishmentContext::scopeValue()]);
// ou directement : ... WHERE etablissement_id = ?  /  EstablishmentContext::id()
```

`super_admin` peut gérer plusieurs établissements. Un onboarding obligatoire
(`API/onboarding_gate.php`) force la configuration tant que l'établissement porte le
code `default`.

---

## Authentification SSO (OAuth2)

`API\Auth\OAuthGuard` gère le SSO Google / Microsoft / fournisseur custom (config
`.env` : `OAUTH_PROVIDER`, `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET`,
`OAUTH_REDIRECT_URI`, et `OAUTH_*_URL` pour custom). Sécurité :

- **paramètre `state` anti-CSRF** : généré (`random_bytes(16)`) et vérifié au
  callback avec `hash_equals()` ;
- **e-mails non vérifiés rejetés** (`email_verified`) pour empêcher le détournement
  de compte via un provider custom ;
- mapping vers un compte local **par e-mail** (colonne `mail`) dans les cinq tables
  utilisateurs ; aucune création automatique de compte (binding admin requis si
  aucun compte local).

---

## Token API (intégrations externes)

> **Fonctionnalité retirée de la baseline v4.** Le guard `API\Auth\TokenGuard`
> (Bearer token pour apps mobiles / services tiers) a été supprimé. Il n'existe plus
> d'authentification par token applicatif : seules l'authentification par session et
> le SSO OAuth2 (ci-dessus) sont supportés. Seule exception, indépendante des comptes
> utilisateurs : le endpoint de santé peut exiger un `Authorization: Bearer
> <HEALTH_TOKEN>` (cf. [docs/api-reference.md](api-reference.md)).

---

## Politique de mot de passe

`API\Security\PasswordPolicy` (`app('password_policy')`). Règles par défaut
(surcharge via `.env` / `config('security.*')`) :

- longueur **≥ 10** (et ≤ 128), avec avertissement au-delà de **72 octets**
  (limite de troncature bcrypt) ;
- au moins une majuscule, une minuscule, un chiffre, un caractère spécial ;
- pas plus de 3 caractères identiques consécutifs ;
- **blocklist** de mots de passe courants (`password`, `azerty`, `pronote`,
  `motdepasse`, `changeme`, …).

```php
$policy = app('password_policy');
$res = $policy->validate($password);   // ['valid' => bool, 'errors' => [...], 'score' => 0..5]
$policy->isDifferentFrom($new, $oldHash);
$hash = \API\Security\PasswordPolicy::hash($password); // bcrypt cost 12
```

À la première connexion (`password_changed_at` vide), `login/index.php` force le
changement de mot de passe (`login/change_password.php`).

---

## Chiffrement at-rest

`API\Core\Encryption` (`app('encryption')`) — **AES-256-GCM** (via OpenSSL),
authentifié.

- clé maître = `APP_KEY` (`.env`), repli sur `JWT_SECRET` ; dérivée en 256 bits par
  version : le chemin d'écriture courant est **HKDF-SHA256** (`hash_hkdf`,
  `KEY_VERSION=2`), rétrocompatible avec la v1 legacy (`hash('sha256', $key, true)`,
  toujours lisible en déchiffrement) ;
- format de sortie : `version:nonce_b64:ciphertext_b64:tag_b64` (nonce 96 bits, tag
  128 bits) ; la version inscrite dans le payload sélectionne la dérivation (rotation) ;
- `Encryption::available()` indique si une clé est configurée.

```php
$enc = app('encryption');             // ou new \API\Core\Encryption();
$cipher = $enc->encrypt('secret');
$plain  = $enc->decrypt($cipher);
$enc->encryptIfPlain($value);
$enc->isEncrypted($value);
\API\Core\Encryption::hash($value);   // SHA-256 one-way (comparaison)
```

> `ext-sodium` est requis par ailleurs (composer), mais le chiffrement at-rest de
> `Encryption` repose sur OpenSSL (`aes-256-gcm`).

---

## En-têtes de sécurité & CSP

Émis par `templates/shared_header.php` (sur toute page passant par le header
partagé) et, de façon plus restreinte, par `login/index.php`.

| En-tête | Valeur |
|---|---|
| `Content-Security-Policy` | voir ci-dessous |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS uniquement) |

### Content Security Policy

CSP **réellement émise** par `shared_header.php` :

```
default-src 'self';
script-src 'self' 'nonce-{…}' 'strict-dynamic' https:;
style-src  'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
font-src   'self' https://cdnjs.cloudflare.com data:;
img-src    'self' data: blob: https:;
connect-src 'self' ws: wss:;
object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';
report-uri /API/endpoints/csp_report.php;
upgrade-insecure-requests;   (HTTPS uniquement)
```

> ⚠️ État réel : `'unsafe-inline'` a été **retiré de `script-src`**. Tous les
> `<script>` portent désormais un **nonce** (`$_hdr_nonce`) et `'strict-dynamic'`
> autorise, en cascade, les scripts chargés par un script déjà noncé — l'allowlist
> d'hôtes de `script-src` n'a donc plus d'effet. `'unsafe-inline'` n'est **conservé
> que sur `style-src`** (nombreux attributs `style="…"` inline en legacy ;
> durcissement planifié). `'unsafe-eval'` et les sources `http://` ont été retirés
> (anti-MITM CDN), `object-src 'none'`, embedding interdit (`frame-ancestors 'none'`).
> Une politique **identique en Report-Only** est émise en parallèle, avec
> `report-uri /API/endpoints/csp_report.php`.
>
> Le **nonce CSP** est généré à chaque requête (`$_hdr_nonce`) et utilisé sur les
> scripts inline injectés par le header (config WS, service worker, dark mode). Sous
> `'strict-dynamic'`, vous **devez** l'utiliser pour vos propres scripts inline :
>
> ```php
> <script nonce="<?= $_hdr_nonce ?>"> /* autorisé */ </script>
> ```

### Conséquences pour les modules

1. **Le nonce autorise les scripts, pas l'allowlist d'hôtes** : sous
   `'strict-dynamic'`, l'allowlist d'hôtes de `script-src` est **inopérante**. Tout
   script chargé dynamiquement (inline, local ou CDN) doit porter le **nonce** de la
   requête (`csp_nonce()` / `$_hdr_nonce`), ou être chargé par un script déjà noncé.
2. Un script CDN se déclare donc avec `nonce="<?= csp_nonce() ?>"` — inutile de
   demander l'ajout d'un hôte à l'allowlist. Un attribut SRI (`integrity="sha384-…"`)
   reste possible en défense supplémentaire mais n'est pas requis par la CSP.
3. Préférez les fichiers CSS/JS externes ; pour un script inline indispensable,
   utilisez le `nonce`.

---

## RGPD — Anonymisation (droit à l'oubli, Art. 17)

Outil admin : `rgpd/anonymiser.php` (service `rgpd/includes/AuditRgpdService.php`).

- **Ciblage par identifiant** : l'admin saisit le **login `nom.prenom`** (ou
  l'e-mail) + le type d'utilisateur. `resoudreUtilisateur()` résout l'ID **scopé à
  l'établissement courant** et **refuse en cas d'ambiguïté** (`LIMIT 2`, doit
  retourner exactement 1 résultat).
- Confirmation obligatoire : saisir le mot `ANONYMISER`. Protégé par CSRF
  (`validateCSRFToken()`).
- `anonymiserUtilisateur()` : transaction qui remplace les données personnelles
  (`nom`, `prenom`, `mail`, `identifiant` → `ANON_xxxx`, téléphone/adresse → NULL,
  `mot_de_passe` vidé, `actif = 0`). Le `SET` est construit dynamiquement à partir
  des colonnes réellement présentes (les tables n'ont pas toutes les mêmes colonnes).
  Les données statistiques (notes, absences) sont conservées de façon anonyme.
- **Les administrateurs ne peuvent pas être anonymisés.**

Voir aussi `rgpd/export_donnees.php` (droit d'accès, Art. 15), `rgpd/retention.php`
et `rgpd/cron_purge.php` (rétention/purge), `rgpd/consentements.php`.

---

## Audit

Les actions critiques sont journalisées dans `audit_log` via `app('audit')`
(`API\Services\AuditService`) : action, modèle, ID, anciennes/nouvelles valeurs
(données sensibles redactées), IP, User-Agent, sévérité, méthode HTTP, URI.

> `logAudit()` est réservé au back-office admin ; ailleurs, utilisez `app('audit')`.

```php
$audit = app('audit');
$audit->log('mon_module.action', $model, [
    'old' => $anciennes,
    'new' => $nouvelles,
], \API\Services\AuditService::WARNING); // INFO | WARNING | CRITICAL
```

---

## Sécurité des modules (marketplace)

### Vérification d'intégrité SHA-256

Tout module téléchargé depuis la marketplace est vérifié contre un hash SHA-256
avant installation (`MarketplaceService::installModule()`).

### Analyse statique (`API\Security\ModuleScanner`)

Scan du code PHP via `token_get_all()` (pas de regex fragile) avant installation.

**Fonctions bloquées** (`BLOCKED_FUNCTIONS`) :
`eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`,
`pcntl_exec`, `dl`, `putenv`, `apache_setenv`, l'opérateur backtick `` ` ``, ainsi
que les fonctions d'indirection permettant de contourner la liste : `call_user_func`,
`call_user_func_array`, `create_function`, `assert`, `forward_static_call(_array)`.

**Fonctions réseau** (`NETWORK_FUNCTIONS`, bloquées sauf permission `network`
déclarée) : `curl_exec`, `curl_multi_exec`, `fsockopen`, `pfsockopen`,
`stream_socket_client`.

**Fonctions suspectes** (`SUSPICIOUS_FUNCTIONS`, avertissement) : accès fichiers
(`file_get_contents`, `fopen`, `unlink`, `rename`, `chmod`…).

### Quarantaine

En cas d'anomalie mineure, le module est placé en quarantaine
(`storage/quarantine/{key}/`, `app('quarantine')`) plutôt que rejeté : non chargé,
revu par l'admin dans `admin/modules/marketplace.php`, puis approuvé ou rejeté.

### Permissions de module

Déclarées dans `module.json` ; affichées à l'admin avant installation.

| Permission | Description |
|---|---|
| `db_read` | Lecture base de données |
| `db_write` | Écriture base de données |
| `network` | Requêtes HTTP sortantes |
| `filesystem` | Lecture/écriture dans `storage/` |
| `cron` | Tâches planifiées |

---

## Checklist sécurité pour les modules

- [ ] Requêtes SQL **préparées** (jamais d'interpolation de valeurs)
- [ ] Requêtes **scopées par `etablissement_id`** (isolation multi-établissement)
- [ ] IDs fournis par le client **revalidés** contre le périmètre autorisé (anti-IDOR)
- [ ] **CSRF vérifié** (`csrf_verify()`) sur toutes les mutations POST/PUT/DELETE
- [ ] **Permissions RBAC** vérifiées (`can()` / `authorize()` / `canModule()` / `requireRole()`)
- [ ] Inputs validés et échappés en sortie (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, `(int)`)
- [ ] Pas de CDN hors liste CSP ; SRI sur les ressources CDN
- [ ] Uploads : vérifier le type MIME, limiter la taille, stocker hors webroot
- [ ] Données sensibles redactées dans les logs
- [ ] Rate limiting sur les endpoints sensibles (`app('rate_limiter')`)
- [ ] Permissions déclarées dans `module.json`
- [ ] Pas de fonctions dangereuses (`eval`, `exec`, `system`…) ni de variable-variables (`$$var`)
- [ ] HTTP sortant uniquement avec la permission `network` déclarée
