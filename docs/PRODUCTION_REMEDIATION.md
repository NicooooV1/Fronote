# Remédiation production — suivi des 157 findings d'audit

> Mise à jour : 2026-06-18. Ce document trace l'état de correction de l'audit de mise en
> production. Légende : ✅ corrigé · 🤖 corrigé (lot automatisé, relu) · ⏳ nécessite la
> chaîne d'outils (PHP/Composer/npm) absente de la machine d'édition · 🌐 nécessite le réseau ·
> 🧱 reporté volontairement (refactor d'architecture — risqué sans tests, voir justification).

## 1. Vulnérabilités critiques (toutes corrigées ✅)

| # | Problème | Correctif | Fichiers |
|---|----------|-----------|----------|
| C1 | Escalade cross-tenant (bascule établissement) | `switch.php`/`multi.php` réservés au super_admin | `admin/etablissement/switch.php`, `multi.php` |
| C2 | RCE/webshell upload (module documents) | Upload validé (extension+MIME finfo+nom aléatoire+0755+.htaccess) ; download MIME réel+nosniff+visibilité | `modules/documents/includes/DocumentService.php`, `telecharger.php` |
| C3 | Prise de contrôle de compte croisée (change pwd) | `reset_user_type` propagé jusqu'à `UserService::changePassword` | `login/change_password.php`, `login/index.php`, `login/verify_2fa.php`, `API/Legacy/Bridge.php` |
| C4 | IDOR lecture messages d'autrui | `requireConversationMembership()` sur `get_new`/`check_updates` + `requirePost()` sur archive/delete/restore/unarchive | `API/endpoints/messagerie.php` |

## 2. Sécurité — majeurs (corrigés ✅)

- ✅ Comptes `actif=0` / `locked_until` refusés au login + sessions de comptes désactivés invalidées — `API/Auth/UserProvider.php`
- ✅ Gates de rôle internat (gestionnaires) et trombinoscope (personnel) — `modules/internat/includes/header.php`, `modules/trombinoscope/includes/header.php`
- ✅ 2FA : anti-brute-force (compteur de session lié à `pending_2fa`) — `login/verify_2fa.php`
- ✅ OAuth/SSO n'outrepasse plus la 2FA — `login/oauth_callback.php`
- ✅ Cookie remember-me `Secure` derrière reverse-proxy TLS (`request_is_https()`) — `API/Services/UserService.php`
- ✅ En-têtes de sécurité émis globalement (tous les entrypoints) — `API/bootstrap.php`
- ✅ `/health` fail-closed en prod (statut binaire, plus de `getMessage()` ni détails infra) — `API/endpoints/health.php`
- ✅ `.htaccess` : blocage `.git`/dotfiles + `tests`/`scripts`/`cron`/`config`/`docs`/`node_modules` + en-têtes statiques + gabarit redirection HTTPS — `.htaccess`
- ✅ Gardes CLI (`PHP_SAPI`) sur tous les `tests/*.php` et `scripts/*.php` 🤖
- ✅ `.htaccess` durci dans les dossiers d'upload de modules 🤖
- 🌐 **SRI / auto-hébergement des CDN** (Font Awesome, Chart.js, Socket.IO), surtout pages `login/*` : **nécessite le réseau** pour récupérer les hashes `integrity` ou télécharger les libs. Voir §7.
- 🧱 **CSP nonce** (retirer `unsafe-inline`) : `CspManager` existe mais inutilisé ; migration progressive après externalisation des handlers inline (1306 `style=`). Non bloquant car les 2 XSS sources sont corrigées.

## 3. Configuration (corrigés ✅)

- ✅ `APP_TIMEZONE` appliqué (`date_default_timezone_set`) — `API/bootstrap.php`
- ✅ install.php : clés `OAUTH_*` (au lieu de `SSO_*` ignorées), `WEBSOCKET_API_SECRET` sur 32 octets, génération `HEALTH_TOKEN` — `install.php`
- ✅ `.env.example` : `HEALTH_TOKEN` documenté — `.env.example`
- ⏳ Consommer réellement `SESSION_LIFETIME/SAMESITE/HTTPONLY` dans `session_start()` **ou** les retirer du `.env.example` (à trancher ; valeurs actuellement codées en dur dans bootstrap). Non bloquant.

## 4. Base de données & RGPD (corrigés ✅)

- ✅ Dérive `audit_log.details` (colonne manquante) : ajoutée au `CREATE TABLE` (SchemaSync la rétro-applique) + log de l'exception avalée — `pronote.sql`, `rgpd/includes/AuditRgpdService.php`
- ✅ Export RGPD (Art.15) désormais journalisé — `rgpd/export_donnees.php`
- ✅ Connexions (succès/échec, mot de passe et 2FA) journalisées dans l'audit — `login/index.php`, `login/verify_2fa.php`
- ⏳ `UNIQUE(mail)` non re-scopé par établissement : `ALTER TABLE … ADD UNIQUE (mail, etablissement_id)` à intégrer dans `pronote.sql` après confirmation qu'aucun login ne suppose l'unicité globale de l'e-mail.
- 🧱 `SchemaSyncService` ne réconcilie pas les colonnes ajoutées par `ALTER TABLE` (etablissement_id/index sur les 5 tables users) : déplacer ces colonnes dans les corps `CREATE TABLE`. **Impacte seulement la MAJ de bases pré-existantes** (installations fraîches OK car install.php exécute les ALTER). À faire prudemment.
- 🧱 `eleves.classe`/`professeurs.matiere` en varchar libre sans FK : migration `classe_id` — refactor de données, hors périmètre go-live.

## 5. Tests & CI

- 🤖 `scoping_lint.php` étendu à `modules/*/Services/` (angle mort comblé) + whitelist des délégations.
- ✅ CI : matrice PHP 8.0→8.3 + `composer audit` — `.github/workflows/validate.yml`
- ⏳ **PHPUnit** : à installer (chaîne d'outils absente ici). Étapes :
  ```bash
  composer require --dev phpunit/phpunit ^10
  # créer phpunit.xml + tests/Unit/ ; ajouter un job CI "tests" en composer install AVEC dev
  composer test
  ```
  Tests prioritaires (verrouiller les correctifs ci-dessus) : `UserProvider::accountUsable` (compte désactivé/verrouillé refusé), `UserService::changePassword` avec type (isolation inter-tables sur SQLite), validation upload `DocumentService` (refus `.php`/MIME falsifié/double extension), `requireConversationMembership` (403 hors participant), RBAC par rôle.

## 6. Dépendances — ⏳ nécessite Composer/npm (absents de la machine d'édition)

```bash
# PHP — figer les versions (reproductibilité + supply-chain)
composer update            # génère composer.lock
git add composer.lock      # le .htaccess le bloque déjà en lecture web

# Node (serveur WebSocket)
cd websocket && npm install   # génère package-lock.json
git add websocket/package-lock.json
# en prod : composer install --no-dev  /  npm ci
```
Ajouter `node_modules/` au `.gitignore` (fait : voir §8 si non).

## 7. CDN / SRI — 🌐 nécessite le réseau

Pour chaque `<script>/<link>` CDN (Font Awesome, Chart.js, Socket.IO), 2 options :
1. **Auto-héberger** (recommandé pour une appli scolaire) : télécharger les libs dans `assets/vendor/`, référencer en local, retirer les hôtes CDN de la CSP.
2. **SRI** : ajouter `integrity="sha384-…" crossorigin="anonymous"` (hashes à récupérer en ligne). Remplacer Font Awesome `6.0.0-beta3` par une version stable.
Pages prioritaires : `login/index.php`, `change_password.php`, `reset_password.php`, `verify_2fa.php`, `verify_reset_code.php`, `reset_confirmation.php`, `templates/shared_header.php`.

## 8. Reportés — architecture (🧱, risqué sans tests) et cosmétique (après prod)

- 🧱 Front controller unique + middleware d'auth (supprime le risque « 556 entrypoints »). Gros chantier ; à faire APRÈS la suite de tests.
- 🧱 Convergence des 2 architectures (legacy `includes/` ↔ `Services/` namespacés), suppression du code mort (`Modules\Notes\Services\NoteService`, rôle `super_admin`/`technicien` fantômes), découpe des god-files.
- 🧱 Dockerfile/docker-compose.
- Perf (après prod, à mesurer sur vraie base) : N+1 génération bulletins (file async déjà dispo), cache des `module.json` au boot, `<select>` élèves non bornés → autocomplete, `DATE()`/`LIKE '%…'` sargables.
- Cosmétique/UX (après prod) : `alert()/confirm()` → toasts maison, contraste `--text-muted` (WCAG AA), focus-trap modale, CSS morts, externalisation des `style=` inline, `declare(strict_types=1)` généralisé (⚠️ à faire fichier par fichier AVEC tests : peut révéler des coercitions cachées).

## 9. RGPD — contenu/ops à fournir (⏳ données de l'établissement)

- Pages **mentions légales + politique de confidentialité** (finalités, base légale, durées, droits, DPO/responsable de traitement) — gabarit à remplir avec les coordonnées réelles.
- Planifier `rgpd/cron_purge.php` dans le crontab (documenté ci-dessous) et **unifier** la durée audit_log (180 vs 365 j).
- Consentement des mineurs : router les consentements sensibles (médical, image, géoloc) vers le compte parent.

### Crontab recommandé
```cron
0 3 * * *  php /chemin/Pronote/cron/daily_maintenance.php   # maintenance + purge audit
0 4 * * *  php /chemin/Pronote/rgpd/cron_purge.php           # purge rétention RGPD
```

---

## VAGUE 2 — réalisée (18/06/26)

Désormais **✅ fait** (au-delà du §1-5 ci-dessus) :
- ✅ **Docker** : `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `docker/php.ini` (opcache + display_errors off).
- ✅ **RGPD** : `rgpd/mentions_legales.php`, `rgpd/confidentialite.php` (gabarits à compléter avec les coordonnées réelles), note de consentement parental dans `consentements.php`. Audit (`getAuditLogs`/`getAuditStats`) scopé par établissement (super_admin = global).
- ✅ **Schéma** : `UNIQUE(mail, etablissement_id)` sur les 5 tables users ; `etablissement_id` déplacé dans les corps `CREATE TABLE` (8 tables) → SchemaSync le rétro-applique. ⚠️ **Régression d'agent corrigée à la main** : la clause `ALTER ... ADD COLUMN etablissement_id` dupliquée a été retirée (sinon « Duplicate column » à l'install) — colonne dans CREATE, index/FK conservés en ALTER.
- ✅ **Perf** : pré-calcul agrégats bulletins, mémoïsation `getUserInfo` dashboard, `SELECT eleves` scopé+borné, dates sargables.
- ✅ **Accessibilité** : contraste `--text-muted` (WCAG AA), focus-trap + ARIA sur la modale.
- ✅ **API** : `nosniff` + rate-limit + validation URL favoris + exp JWT réduite — `API/endpoints/*`.
- ✅ **Sécurité divers** : timeout de session (inactivité+absolu) dans `bootstrap.php` ; bcrypt cost 12 centralisé (SettingsService/SuperAdmin/technicien/BulkImporter) + `password_changed_at` ; whitelist `type` dans `profile_ajax.php`.
- ✅ **Tests** : PHPUnit installé (`composer.json` require-dev, `phpunit.xml`, `tests/Unit/` : isolation change-pwd, validation upload, compte actif/verrouillé) + job CI `tests`. **À exécuter** : `composer install && composer test`.
- ✅ **Dead code** : suppression `API/Commands`, `API/Http`, `modules/dashboard`, CSS mort `notes-graphs.css` ; propriétés `PDO $pdo` typées sur 4 services.
- ✅ **CI** : matrice PHP 8.0→8.3 + `composer audit` + job PHPUnit.

**Reste volontairement non fait** (raisons inchangées) :
- ⏳ `composer.lock` / `package-lock.json` → lancer `composer update` / `npm install` (binaires absents de la machine d'édition).
- 🌐 SRI / auto-hébergement CDN → hashes à récupérer en ligne ; remplacer Font Awesome beta par stable.
- 🧱 Front controller unique + fusion des 2 architectures + suppression des rôles fantômes `super_admin`/`technicien` + découpe des god-files + versioning `/v1` : **chantiers lourds à mener APRÈS la suite de tests** — les faire à l'aveugle, sans PHP ni tests ici, dégraderait la stabilité.
- `accueil.css` racine conservé (référencé — suppression écartée à juste titre).

> **Leçon clé** (cf. la régression d'agent corrigée) : aucune vérification PHP/DB n'est possible sur la machine d'édition. **Avant tout déploiement** : `composer install`, `php -l` sur l'arbre (ou laisser la CI), `composer test`, puis un essai d'installation complète (`pronote.sql`) sur une base jetable.

---

## VAGUE 3 — Front controller de sécurité + RBAC/ABAC (18/06/26)

Architecture **additive, rétro-compatible** (l'auth existante n'est pas réécrite ; le « type de compte » reste l'identité de session et le rôle de base).

**Front controller (couche d'accès centralisée) :**
- `API/Core/AccessControl.php` — invoqué depuis `bootstrap.php` sur CHAQUE point d'entrée. Fail-closed selon le chemin : zones `admin/etablissement/{switch,multi,purge}.php` → super_admin ; `admin/` → administrateur/technicien/super_admin ; `modules|accueil|parametres|rgpd|API/endpoints…` → authentifié ; public : `login/`, `install*`, `health`, `cookie_consent`, pages RGPD légales. Chemin inclassable → ne verrouille pas (les gardes par page restent actives). 401/403 JSON pour l'AJAX, redirection sinon. Complète (ne remplace pas) les `requireAuth()` par page → rattrape une page qui aurait oublié sa garde.

**Modèle RBAC/ABAC (rôle → permission → périmètre → multi-rôles → audit) :**
- `API/Security/RoleCatalog.php` — **source de vérité** : 112 rôles, 130 permissions, grants par rôle, `ACCOUNT_BASE_ROLE`, `isSensitive()`. Audité : `super_admin=['*']`, `purge` réservé super_admin, `medical/psy/social/pai/handicap` uniquement rôles santé/social, `notes.create|edit|delete` uniquement professeur & dérivés pédago, `roles.manage` uniquement admin/direction/responsable_permissions, lecteurs/inspecteur/invité/démo en lecture seule.
- `API/Security/Authorization.php` — moteur `can($permission, $ctx)` : rôles effectifs (base + `user_roles` actifs) × grants × **périmètre** (global/establishment/establishments/self/children/assigned/own_classes) ; journalise les permissions sensibles dans `audit_log`.
- `API/Security/RoleSync.php` — réconcilie catalogue → `rbac_roles` + `rbac_permissions` (idempotent ; appelé par `UpdateService`).
- Schéma (`pronote.sql`) : `rbac_roles` (catalogue) + `user_roles` (multi-rôles scopés/temporisés : `etablissement_id`, `scope_type`, `scope_json`, `valid_from/until`, `granted_by`).
- Login infra : `super_admin` + `technicien` câblés dans `UserProvider` (restore + lookup dédiés, tables `super_admins`/`technicien_access`, requêtes isolées → tables vides sans effet).
- Helpers (`Bridge`) : `can()`, `authorizeOr403()`, `hasRole()`, `isTechnicien()`, `isCpe()`, `authz()`. `bootstrap` : service `authz`.
- `admin/etablissement/purge.php` — purge super_admin : POST+CSRF, confirmation par saisie du nom, **sauvegarde obligatoire avant**, audit systématique, **aucune suppression silencieuse** (refus propre si `SuperAdminService::purgeEstablishment` absent).

**Reste (migration incrémentale — NE PAS considérer comme fini) :**
- 🧱 **Adoption page par page** de `can($perm, $ctx)` à la place des `getUserRole()==='x'` épars (le front controller couvre la couche grossière ; le fin reste à migrer module par module, avec le `$ctx` de périmètre — ex. `notes` → `class_id`/`subject_id`, parent → `student_id`).
- 🧱 **UI d'attribution de rôles** (table `user_roles`) : écran admin/direction pour assigner cpe/psychologue/prof_principal/… avec périmètre + durée. Le moteur lit déjà `user_roles` ; l'UI manque.
- 🧱 **Périmètre super_admin** : à la connexion, scope = établissement 1 par défaut (god-mode contourne déjà l'audit/AccessControl) ; pour voir les données scopées de tous, utiliser `switch.php`. Vue « tous établissements » globale à ajouter si besoin.
- 🧱 **Flux de login technicien** (expiry/ip_whitelist de `technicien_access`) : restore + lookup faits ; la page de création existe ; valider le cycle complet sur base de test.
- 🧱 `SuperAdminService::purgeEstablishment()` à implémenter+tester (transaction sur les tables scopées).
- ⏳ `php -l API/Security/RoleCatalog.php` + `composer test` sur l'environnement cible (pas de PHP ici).

### VAGUE 3bis — RBAC rendu utilisable (18/06/26)

**✅ Désormais fait :**
- **Purge réelle** : `SuperAdminService::purgeEstablishment($id)` — transaction, découverte des tables scopées via `information_schema`, `SET FOREIGN_KEY_CHECKS=0` le temps de la purge, refus si dernier établissement. Bouton 🗑️ (super_admin, prompt du nom exact) dans `admin/etablissement/multi.php` → `purge.php` (sauvegarde obligatoire + audit avant).
- **Attribution des rôles** : `API/Services/RoleManagementService.php` (assign/revoke/list avec garde-fous : seul super_admin attribue super_admin ; pas d'auto-élévation ; périmètre d'attribution borné) + UI `admin/users/roles.php` (lien depuis `admin/users/index.php`). Multi-rôles scopés (établissement / `scope_type` / expiration).
- **Correctif moteur** : `Authorization::teachesClass()` corrigé — `professeur_classes` lie par `nom_classe` (nom), pas `id_classe` (résolution id→nom via `classes`).

**🧱 Reste (migration incrémentale, à faire AVEC base de test) :**
- Adoption de `can($perm, $ctx)` module par module (le front controller couvre le grossier). Patron de référence :
  ```php
  // Un prof ne saisit une note que pour SA classe / SA matière :
  if (!can('notes.create', ['class_name' => $classe, 'subject_id' => $matiereId])) {
      http_response_code(403); exit('Accès refusé');
  }
  // Un parent ne voit que SES enfants :
  if (!can('eleves.view', ['student_id' => $eleveId])) { ... }
  ```
  ⚠️ NE PAS retrofitter `can()` à l'aveugle sur un chemin critique (ex. saisie de notes) sans vérifier que `professeur_classes` est bien peuplé en prod — sinon risque de blocage de la saisie. Migrer module par module avec test.
- Valider le cycle de login `technicien` (expiry / ip_whitelist) sur base de test.
- `php -l` sur les nouveaux fichiers (`AccessControl`, `Authorization`, `RoleSync`, `RoleManagementService`, `RoleCatalog`, `purge.php`, `admin/users/roles.php`) + `composer test`.
