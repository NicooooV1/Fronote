# Changelog

All notable changes to Fronote will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [4.1.0] — Refonte du système de rôles + purge du code mort — 2026-08-12

Refonte du système d'autorisation vers **UN moteur unique et UN catalogue**, puis grande passe
de suppression du code mort. Changements **potentiellement cassants** pour tout code externe qui
appelait les fonctions/tables retirées.

### Changed — Autorisation unifiée
- **Moteur unique** : toute décision passe par `API\Security\Authorization` (`app('authz')`) via les
  helpers globaux `can()` / `authorize()` / `canOn()` / `hasCapability()` / `requireCapability()`.
- **Un catalogue** : `API\Security\RoleCatalog` (défaut en code) + table **globale** `rbac_grants`
  (déviations force-accorder/force-refuser), éditée **côté plateforme** (`platform/roles.php`).
- `tenantGate('perm')` (un seul argument) route désormais vers `can()` ; les 11 gardes d'entrée de
  module `requireRole(...)` codées en dur deviennent `requireCapability('module.<clé>.access')`.
- L'attribution des rôles reste à la direction (`admin/users/roles.php`) ; **les permissions ne sont
  plus éditables au niveau établissement** (les anciennes matrices `admin/modules/{role_permissions,permissions}.php`
  redirigent). Profil « façon Discord » (poste + badges) dans `modules/profil`.

### Removed — Systèmes de rôles morts
- Classe `API\Security\RBAC`, `API\Security\RoleSync`, fonction globale `requireRole()`, binding `app('rbac')`.
- Tables `rbac_permissions`, `rbac_roles`, `module_permissions`, `tenant_permissions`,
  `tenant_role_permissions`, `user_role_scope_values` (droppées ; catalogue en code faisant foi).
- `ModuleSDK::syncPermissions()` (le bloc `permissions` de `module.json` reste déclaratif) ; l'export/import
  de configuration n'embarque plus la matrice de permissions (import rétro-compatible : section ignorée).

### Removed — Purge du code mort (audit adversarial)
- Classes/fichiers sans appelant : `IpFirewall`, `QueueService` + `SendEmailJob`, `SupportSessionGuard`,
  événements `UserCreated`/`UserPasswordChanged`/`MessageSent`, coquilles de modules `securite/` et `tutorat/`.
- Bindings conteneur jamais résolus retirés : `firewall`, `queue`, `encryption`, `quarantine`,
  `super_admin`, `pdf` (les classes vivantes s'instancient directement).
- ~40 fonctions globales sans appelant retirées de `API/Legacy/Bridge.php` (login/loginUser, canOn/authorizeOn
  globaux, canModule, sanitizeInput, validateEmail, executeQuery, tableExists, csrf_meta/csrf_validate,
  wrappers support*/tenant*On/currentWorld, …).
- 22 tables sans lecteur droppées (api_tokens, webhooks, payments, signatures, sms_*, user_profiles,
  account_profiles, ip_blocklist, custom_fields/values, …) ; définitions retirées de `pronote.sql`.
- Décompte réel : **61 modules** (57 sous `modules/` + 4 essentiels racine).

### Fixed
- Binding conteneur des services pédagogiques au cœur (`absences`, `notes`, `matieres`, `periodes`,
  `evenements`, `devoirs`) → pages `admin/scolaire/*` (5 pages back-office 500 → 200).
- 404 CSS messagerie ; bug latent `ClientCache` appelant `RBAC::getAllPermissions()` inexistant.
- Seed `rbac_permissions` + `ALTER` orphelins retirés de `pronote.sql` (corrige l'installation neuve).

---

## [4.0.0] — « Étanche » : isolation multi-tenant complète + remédiation des 25 critiques — 2026-08-11

Version stable **définitive**. Aboutissement du durcissement : les 25 bugs critiques de l'audit
runtime multi-rôles sont corrigés et vérifiés, l'isolation multi-tenant est **étanche**, et toute
la documentation est alignée.

### Sécurité — Isolation multi-tenant (12 IDOR cross-tenant fermés)
- **Cantine** : export des réservations scopé établissement (fin de la fuite de PII élèves entre
  établissements) ; clé UNIQUE `menus_cantine` régionalisée `(etablissement_id, date, régime)`
  (fin de l'écrasement silencieux du menu d'un autre établissement).
- **Cahier de textes** : `deleteFichier` scopé via le devoir parent (fin de la suppression
  destructive cross-tenant de pièces jointes).
- **Messagerie** : l'action « restaurer » ne permet plus de rejoindre une conversation dont on n'a
  jamais été membre (IDOR horizontal / escalade de privilège).
- **Notes** : `ajax_stats` (évolution) borné à l'établissement même en accès complet.
- Cloisonnement ajouté sur : transports, salles, réunions (convocations), projets pédagogiques,
  vie associative, emploi du temps (maquettes), internat (incidents — PII de mineurs).

### Correctifs de robustesse
- **Plantages HTTP 500 corrigés** : pointage cantine (variable `$user` non définie), module
  intelligence (`logAudit()` indéfini → `app('audit')->log()`).
- **Dérive de schéma éliminée** (8 modules — clubs, compétences, diplômes, périscolaire, personnel) :
  code aligné sur le schéma réel (colonnes et valeurs d'enum inexistantes corrigées).
- **Orientation** : `sauvegarderVoeux` rendu transactionnel — un INSERT en échec ne détruit plus
  tous les vœux de l'élève.

### Documentation & version
- Documentation entièrement revue et alignée : version 4.0.0 partout, 2FA obligatoire, RBAC global
  (`rbac_grants`), stack de production de référence (MariaDB 10.11 + PHP-FPM/mpm_event).
- `version.json` : **63 modules**, prise en charge **MariaDB** déclarée.

## [3.6.0] — Second facteur obligatoire + RBAC centralisé plateforme — 2026-08-11

### Sécurité — Authentification
- **2FA obligatoire pour les rôles à responsabilité** (professeur, vie scolaire, administrateur,
  super-admin) : second facteur exigé à chaque nouvelle connexion, avec tolérance d'1 h par appareil
  (cookie de confiance signé HMAC — `API/Security/TwoFactorTrust`). Enrôlement forcé
  (`login/setup_2fa.php`) avec secret TOTP + codes de secours pour les comptes non encore équipés.
  Élèves et parents non impactés.
- Anti-bruteforce du 2e facteur persistant (`login_attempts`) en plus du compteur de session.

### RBAC — Contrôle d'accès centralisé
- **Table globale `rbac_grants`** (possédée par la plateforme) : les permissions associées aux rôles se
  pilotent depuis la plateforme et s'appliquent à tous les établissements, allégeant les dirigeants
  d'établissement. La gestion directe rôle→permission du panneau d'administration passe en lecture seule
  (vue des permissions effectives = catalogue + surcharges).
- Éditeur rôles→permissions côté plateforme écrivant `rbac_grants`.

### Design & modules
- Refonte du module messagerie (bulles groupées, temps réel) et de l'attribution des rôles (boutons/cartes).
- Système de design plateforme unifié (composants `pf-*`).

### Infrastructure (déploiement de référence)
- Serveur web migré Apache mpm_prefork + mod_php → **mpm_event + PHP-FPM 8.2**.
- Base de données migrée MySQL 8.0 (Docker) → **MariaDB 10.11 natif**.

## [3.5.1] — Régionalisation RBAC + harness d'autorisation — 2026-07-12

- **Matrice RBAC cloisonnée par établissement** (finding #2, précédemment en chantier) : une surcharge
  de permission (`rbac_permissions`) ne s'applique plus qu'à l'établissement qui l'a posée — lecture
  scopée (`Authorization::roleGrants`, `RBAC::checkDynamicPermission`, résilientes), écriture scopée
  (`role_permissions.php`), clé unique `(role, permission, etablissement_id)` (migration idempotente
  `2026_07_12_000001`, appliquée). Rétrocompatible (lignes existantes à `etablissement_id=1`).
- **`scopeAllows` — fin du fail-open** (finding #23) : un rôle scopé établissement sans cible explicite
  déduit désormais le scope de requête résolu (`EstablishmentContext`) et refuse s'il diffère de son
  périmètre ; contexte non résolu → permissif (aucune régression).
- **Harness d'autorisation** (`AuthorizationMatrixScopeTest`, SQLite) : prouve qu'un grant/deny d'un
  établissement ne fuit pas vers un autre, et couvre les 3 cas de #23 — c'est ce banc qui a rendu ces
  changements d'authz vérifiables.
- **`.env` hors docroot** supporté (`EnvLoader`) + `docs/SECURITY.md` (checklist de durcissement prod).
- **Bancs de régression sécurité** : `SecurityRemediationTest` (csv_safe, js_json, allowlist SSRF push)
  et `RoleAssignmentIsolationTest` (anti-IDOR d'attribution de rôle).

## [3.5.0] — Remédiation de l'audit complet (sécurité renforcée) — 2026-07-12

Suite à l'audit complet (posture 58/100). ~26 des 33 findings vérifiés corrigés ; les 7 restants
sont des chantiers structurants documentés ci-dessous. 144 tests verts.

### Sécurité — Isolation multi-tenant
- **Messagerie cross-établissement (CRITIQUE)** : validation d'appartenance à l'établissement de
  chaque participant avant insertion (création + ajout), `getMessages` joint `conversations` et exige
  `etablissement_id`, anti-IDOR sur le re-join. Fin de la lecture des échanges d'un autre tenant.
- **Attribution de rôle** : la cible doit appartenir à l'établissement de l'acteur (`assign`/`revoke`,
  fail-closed). Scoping tenant ajouté à `corriger.php` et aux en-têtes PDF officiels (convention,
  convocation, diplôme, désormais sur la vraie table `etablissements`).
- **RBAC** : un admin d'établissement ne peut plus (dé)accorder de permission sensible/plateforme ni
  au-delà de son propre privilège (anti-escalade).
- **RGPD** : registres de violations fail-CLOSED, taux de consentement du dashboard scopé.

### Sécurité — Sessions, secrets, injections
- **Révocation de session** : le changement de mot de passe expulse les autres sessions
  (`session_security` + logout forcé par le bootstrap) et régénère l'ID ; jeton WebSocket 24 h → 30 min.
- **Secrets/logs** : masquage des clés sensibles dans le Logger ; `ALLOW_DEMO_ACCOUNTS` bloque le boot
  en prod ; alerte d'exposition des secrets sous la racine web.
- **2FA** : anti-rejeu TOTP fail-closed + table au schéma ; suppression d'une méthode fuyant le secret.
- **Push** : anti-SSRF (allowlist d'hôtes) + désabonnement borné au propriétaire.
- **Exports CSV** : neutralisation d'injection de formule sur tous les exporteurs.
- **Thème CSS** : liste blanche des `url()` (anti-exfiltration). CSRF : bucket 10 → 50.

### Intégrité des données pédagogiques
- Moyenne générale pondérée par coefficient (cohérence notes ↔ bulletin) ; verrouillage des notes
  réparé (via `notes_verrous`) ; validation d'appel idempotente (fin des doublons d'absences) ;
  comptage d'absences par chevauchement de période.

### Chantiers documentés (non traités — nécessitent migration/décision de déploiement)
- Régionalisation complète de `rbac_permissions` (colonne `etablissement_id` + migration de clé).
- Docroot `public/` dédié (sortir `.env`/`vendor`/`database`/`logs` de l'arborescence servie).
- `scopeAllows()` fail-closed et garde module-niveau (`enforceModuleAccess`) : risque de régression
  d'accès large → à valider par tests d'autorisation dédiés.
- Unification des deux architectures de modules ; complétion du module médiathèque.

---

## [3.4.1] — Adaptation multi-appareils (responsive) — 2026-07-12

Adaptation ordinateur / tablette / téléphone, priorité aux **formulaires** (le point douloureux).
Réalisée par une couche CSS centrale agissant sur les classes partagées — sans toucher au HTML.

### Responsive
- **Couche `responsive.css`** (chargée en tout dernier, mobile-first, 3 seuils canoniques :
  téléphone < 640, tablette 640–1024, ordinateur ≥ 1024) qui corrige l'essentiel via les classes
  existantes :
  - **Formulaires** : champs en **pleine largeur** (corrige un bug du thème classic où un `<input>`
    nu n'était pas étiré), **16 px sur téléphone** (supprime le zoom automatique iOS au focus) et
    **cibles tactiles ≥ 44 px** ; `.form-row` et les grilles `.form-grid*` passent en **1 colonne**
    sur téléphone (les grilles se replient toutes seules via `auto-fit` sur tablette) ; actions de
    formulaire **empilées pleine largeur** ; barres de filtres empilées.
  - **Modales** : tiennent dans l'écran (largeur + hauteur `88vh` + défilement) ; **tables** en
    défilement horizontal au lieu de déborder ; filet anti-débordement horizontal ciblé.
- **Page de connexion** : champs (identifiant, mot de passe, code 2FA) passés à 16 px (fin du zoom
  iOS dès le premier écran).
- Grilles de formulaire figées converties en repli automatique (`admin/systeme/technicien.php`,
  `modules/notes/form_note.php`).

### Connu / à suivre
- Unification du seuil de bascule de navigation (768 vs 1024), déduplication des classes de
  formulaire, et pattern « cartes » pour les très grands tableaux restent des chantiers.

---

## [3.4.0] — Refonte visuelle (direction sobre) — 2026-07-12

Modernisation transverse de l'interface, dans un langage sobre inspiré de l'écosystème Apple :
gris froids, bleu système retenu, coins continus, ombres douces, barre supérieure translucide.
Appliquée par la couche de tokens partagée + une couche de *polish* — sans modifier le HTML,
réversible, et respectant le branding par établissement.

### Design
- **Tokens** (`tokens.css`, source unique) retouchés : typographie **SF Pro** en tête de pile
  (`-apple-system…`, crénage resserré, interlignage 1.47), neutres froids (fond `#f5f5f7`, encre
  `#1d1d1f`, filet `#e5e5ea`), accent **bleu système** `#0071e3` (`#0a84ff` en sombre), rayons
  continus (8/12/18), ombres diffuses à faible opacité. Le sombre passe du slate bleuté à un
  **graphite neutre** (`#161618` / `#1f1f21`). Le branding établissement reste prioritaire.
- **Couche `modernize.css`** (chargée en dernier) : **barre supérieure translucide et floutée**
  (toolbar façon macOS/iOS), **transitions entre pages** via l'API *View Transitions* (fondu
  inter-pages, amélioration progressive), entrée de page en fondu, transitions d'interaction
  douces, léger soulèvement des cartes au survol, liseré de focus net, barres de défilement fines.
  Tout le mouvement respecte `prefers-reduced-motion`.
- **Cohérence tous portails** : la page de **connexion** (`login.css`) et les portails
  **plateforme/tenant** (valeurs de repli des tokens DS + surfaces sombres) adoptent la même
  direction. Thème **verre** préservé (dégradé intact — surcharge sombre scopée hors glass).

---

## [3.3.1] — Audit qualité front (HTML/CSS/JS) — 2026-07-11

Suite à l'audit front (note 58/100, 55 findings). Corrige les 8 findings majeurs + la majorité
des mineurs actionnables ; les chantiers structurants (fusion des deux design systems, chaîne de
build, migration des ~1571 styles inline, px→rem) restent documentés comme travaux de fond.

### Sécurité / CSP
- **Dépendances tierces vendorisées** sous `assets/lib/` (Font Awesome 6.5.2, Socket.IO 4.7.5,
  Chart.js 4.4.1), servies **same-origin** et versionnées : plus aucune ressource CDN externe
  (l'app tourne en LAN — les icônes/WebSocket/graphes ne cassent plus hors ligne, et l'absence de
  SRI n'est plus un risque). Chart.js unifié (finissait sur 2 hôtes CDN divergents).
- **CSP resserrée** : `cdnjs.cloudflare.com` retiré de `style-src`/`font-src` (socle + 6 en-têtes
  des pages login/reset/2FA), désormais `'self'`.
- **Menu Favoris (JS)** reconstruit sans `onclick` inline → `data-fr-click` (était cassé sous la CSP
  stricte). Barre de dev (`dev_toolbar`) idem. Dispatcher `csp-actions.js` : liste noire des globales
  d'exécution de code (`eval`, `Function`, `setTimeout`…) refusées à la résolution par nom.
- **Aperçu de fichiers** (cahier de textes) et **badges de réaction** (messagerie) : rendu passé de
  `innerHTML` à une construction DOM (`textContent`) — anti-XSS DOM.

### Performance / robustesse JS
- **Cache-busting du JS des modules** : `?v=mtime` désormais appliqué au JS chargé via `$extraJs`
  et aux `<script src>` embarqués (messagerie, notes, agenda, EDT, compétences) — helpers globaux
  `asset_url()` / `asset_bust()`. Corrige le JS servi périmé jusqu'à 1 an après déploiement.
- **Fuites de timers/écouteurs** corrigées : `setupUnifiedPolling` (messagerie) rendu idempotent
  avec `stopUnifiedPolling()` (timer + `visibilitychange` détachés) ; chaîne de refresh de token
  `ws-global` réduite à une seule instance annulée à la déconnexion.

### Accessibilité
- **Modale de recherche (Ctrl+K)** : `aria-modal`, nom accessible, piège de focus, restauration du
  focus au déclencheur. **Panneau mobile** : `aria-controls`/`aria-expanded`, dialog, focus géré,
  fermeture Échap.
- Menus déroulants topbar : `aria-haspopup` + `aria-controls` ; icônes décoratives `aria-hidden` ;
  sélecteur d'enfant et bouton de fermeture mobile nommés.
- **Labels de formulaire** associés (`for`/`id`) dans les paramètres ; hiérarchie de titres du
  dashboard admin corrigée (h1→h2) ; **live region** (`role="log"`) sur le fil de conversation ;
  indicateurs de **focus visibles** rétablis (recherche topbar/sidebar) ; `<footer>` sémantique +
  bouton (au lieu de `<a href="#">`) pour les mentions légales.

### CSS
- `dark-overrides`/`theme-glass` : bloc `@media prefers-color-scheme` mort supprimé (le thème est
  toujours résolu en `data-theme` côté serveur) ; composant `.btn-xs` dédupliqué (`admin.css`).

### Tests
- `FrontendHygieneTest` : cliquet CI verrouillant l'absence de CDN externe et de handlers `on*=`
  inline dans le socle (144 tests au total, verts).

---

## [3.3.0] — Durcissement production & schéma déclaratif — 2026-07-11

### Sécurité
- **Cloisonnement multi-tenant** généralisé : `etablissement_id` + `\API\Core\EstablishmentContext::id()`
  (fail-closed) sur l'ensemble des modules et endpoints ; index + clé étrangère `etablissement_id`
  déclarés dans le `CREATE TABLE`. Nombreux IDOR / fuites cross-tenant corrigés.
- **Chiffrement au repos** AES-256-GCM (`\API\Core\Encryption`), dérivation de clé versionnée
  `KEY_VERSION=2` (HKDF-SHA256), rétro-compatible v1. Données de santé (infirmerie, accessibilité)
  et `two_factor_secret` chiffrés ; colonnes chiffrées élargies (`TEXT` / `VARCHAR(255)`).
- **2FA** : anti-rejeu TOTP (pas-de-temps consommé, table `two_factor_last_step`), codes de secours
  poivrés HMAC-SHA256.
- **CSP** en mode *enforce* sans `'unsafe-inline'` sur `script-src` (nonce + `strict-dynamic`),
  `report-uri /API/endpoints/csp_report.php`, handlers inline convertis en dispatcher délégué
  `data-fr-*`. **CSRF** à jetons rotatifs à usage unique. En-tête CSP *sandbox* sur les fichiers
  servis inline.
- `declare(strict_types=1)` généralisé sur les points d'entrée (+ casts défensifs).

### RGPD
- Rétention scolaire (opt-in) + purges plancher ; export Art.15 et effacement Art.17 complets et
  **déchiffrés** (santé, MDPH, ESS) ; consentements appliqués ; audit des accès santé de masse.

### Schéma & base de données
- **Schéma DDL 100 % déclaratif** : `pronote.sql` + `modules/*/Database/install.sql` +
  `rgpd/Database/*.sql`, réconciliés par `SchemaSyncService` (additif : CREATE TABLE + ADD COLUMN).
  Les migrations de schéma one-shot (`cron/migrate_*.php`) sont **supprimées** — plus aucun script à
  exécuter pour le schéma. Index + clés étrangères de cloisonnement bakés dans le schéma.
- **Précision migrations** : un système de **migrations de données versionnées** subsiste pour les
  transformations que le déclaratif ne sait pas faire (`database/migrations/` + `MigrationRunner`,
  journal `schema_migrations`), exécuté par `UpdateService::applyUpdate()` après `SchemaSyncService`.
  Voir [docs/UPDATING.md](docs/UPDATING.md).
- **Correctif d'ordre à l'install** : `SchemaSyncService::sync()` s'exécute désormais **avant** les
  INSERT de données de référence des modules — les données de référence (ex. types de bourses) se
  chargent correctement à l'install neuve.

### Internationalisation
- `__()` câblé sur les 58 modules en 8 langues (ar/de/en/es/fr/nl/ru/th) ; installeur et flux
  d'authentification entièrement traduits.

### Qualité / CI
- PHPStan **bloquant** (`phpstan-baseline.neon`) + `npm audit` bloquant + service MySQL pour les
  tests d'intégration dans `.github/workflows/validate.yml`. PHPUnit : 29 fichiers, 127 tests.

### Documentation
- Nouveau guide [docs/UPDATING.md](docs/UPDATING.md) (mise à jour & évolution du schéma) ;
  documentation alignée sur le schéma déclaratif + `MigrationRunner`, `tests/README.md` régénéré.

---

## [Unreleased] — Durcissement développement — 2026-06-17

### Changed

- **Suppression du système de migrations _par module_.** Plus de `ModuleSDK::migrate()`, de tables `module_migrations` / `core_migrations`, de dossiers `*/Database/migrations/`, de `CoreMigrator`, de `scripts/migrate.php`, ni de clé `module.json["migrations"]`. Le schéma DDL vit entièrement dans `pronote.sql` (cœur) + `modules/<m>/Database/install.sql` (`CREATE TABLE IF NOT EXISTS`, schéma final complet). `ModuleSDK::provisionSql()` n'exécute plus que `install.sql`. _(Depuis 3.3.0, un système de migrations **de données** versionnées au niveau du dépôt — `database/migrations/` + `MigrationRunner`, journal `schema_migrations` — subsiste pour les rares transformations que le schéma déclaratif ne sait pas exprimer ; voir [docs/UPDATING.md](docs/UPDATING.md).)_
- **Mise à jour en un seul bouton.** `admin/systeme/update.php` → `app('updates')->applyUpdate()` = `git fetch` + `git reset --hard origin/<GITHUB_BRANCH>` + `API\Services\SchemaSyncService::sync()` (réconciliation déclarative idempotente : création des tables et ajout des colonnes manquantes lues depuis les `install.sql`/`pronote.sql`, **sans migration ni DROP**) + `module_sdk->syncAll()` + vidage du cache. Remplace l'ancien système (GitHub Releases + zip + `scripts/update.php` + webhook + cron).
- **Restructuration vers la topbar.** 28 pages (22 modules + 6 pages d'administration) migrées de l'ancienne sidebar vers le layout topbar unifié (`shared_header` + `shared_topbar` + `.content-container`). CSS modules corrigé (`$extraCss` page-relatif).

### Added

- **Import en masse** : `admin/systeme/import_export.php` + `API/Services/Import/{BulkImporter,ImportSchemas}` — fichier CSV/TSV ou copier-coller, reconnaissance automatique des en-têtes d'un export Pronote (FR), entités élèves/professeurs/parents/classes/matières/notes/devoirs, écran de correspondance des colonnes, scoping établissement.
- **Refonte du support** : fil de discussion multi-messages (`support_ticket_messages`), notifications (création / réponse / changement de statut), SLA + satisfaction + notes internes, accès admin (carte du tableau de bord).
- **Périodes auto** : `PeriodeService::defaultPeriodes()` déduit trimestres/semestres à partir de l'année scolaire (rentrée septembre).
- **Onboarding obligatoire** : `API/onboarding_gate.php` force la configuration de l'établissement tant que son code vaut `default`.
- **Boutons retour** : convention `$pageBack` rendue par la topbar (automatique sur l'administration).
- **`API\Services\SchemaSyncService`** : réconciliation de schéma déclarative (remplace les migrations).

### Fixed

- Déconnexion inopérante (cookie « se souvenir de moi » non invalidé → reconnexion immédiate).
- Messagerie : bouton « Nouveau message » absent (perdu lors du passage à la topbar).
- « Sessions actives » : sessions désormais enregistrées à la connexion + colonne corrigée.
- Widgets d'accueil vides trop hauts + normalisation des données des fournisseurs SDK.
- Clés i18n du bandeau cookies manquantes (une clé absente affichait son nom).
- Périodes et notes scopées par établissement ; RGPD : anonymisation par identifiant `nom.prenom` (au lieu d'un ID numérique).
- `super_admin` ne pouvait pas atteindre l'administration ; faux positifs du scanner d'audit de code réduits.

### Removed

- `scripts/update.php`, `scripts/check_update.php`, `API/endpoints/webhook_update.php`, `API/Database/CoreMigrator.php` + `API/Database/migrations/`, ancien `UpdateService` (GitHub Releases / zip).

---

## [3.2.4] "Marketplace" — 2026-05-31

### Added — Marketplace v1.5.2 (CDC n°2 — format .fmod + infrastructure test)

- **Format `.fmod` v1** : structure ZIP normalisée (`MANIFEST.sha256`, `SIGNATURE.json`, `module.json`, arborescence source). Spec publique dans [`fmod-format.md`](fmod-format.md).
- **`test_only` channel** : modules marqués `test_only: true` bloqués sur instances production ; activables via `ALLOW_TEST_MODULES=true` dans `.env`.
- **Consentement des permissions** : si un `.fmod` déclare `permissions_requested`, l'installation est suspendue. L'admin coche chaque permission explicitement. Consentement horodaté dans `marketplace_consents` (`granted_by_name` dénormalisé pour traçabilité RGPD post-suppression admin).
- **`MarketplaceService::isTestModulesAllowed()`** : lit `ALLOW_TEST_MODULES` env.
- **`MarketplaceService::confirmInstall()`** : finalise l'installation après consentement ; partage `deployFromStaging()` avec `installFromFmod()` (factorisation).
- **Module de référence `hello_world` v1.0.0** : module test officiel validant l'intégralité du pipeline .fmod (table `hello_world_log`, service, provider, page admin avec log/clear, langues fr/en).
- **Infrastructure PKI de test** : `scripts/pki/generate-test-ca.sh` génère Root CA test, Intermediate CA, certificat éditeur `fronote-team`, keypair libsodium. Copie automatique de `fronote-test-root.pub` dans `config/marketplace/roots/`.
- **CLI `scripts/install-module.php`** : install interactive depuis CLI avec consent et `--dry-run`.
- **`API/endpoints/test_catalog.php`** : catalogue JSON des modules de test (local + registry configurable via `MARKETPLACE_TEST_REGISTRY_URL`).
- **`marketplace.php` refactorisé** : écran consentement, badge `test_only` warning prod, pagination 20/page, Root CA listées, `BASE_PATH` remplace les `dirname(__DIR__, 2)` relatifs.
- **`install.sql` v1.5.2** : `root_public_key BINARY(32)` (était `VARBINARY(64)`), `COLLATE ascii_bin` sur colonnes SHA-256/fingerprint, `updated_at ON UPDATE` sur `marketplace_sources`, `granted_by_name` dans `marketplace_consents`, `acknowledged_by` + FK dans `marketplace_advisories_seen`, `KEY idx_fingerprint` dans `marketplace_revocations`, table `marketplace_installs` créée (utilisée par `installModule`/`installTheme`).

### Added — Architecture modules (CDC n°1 — refactoring)

- **`composer.json`** : namespace `Modules\\` → `modules/` (PSR-4). Chaque module peut définir ses propres classes sans modifier l'autoloader core.
- **`ModuleSDK::bootActiveModuleProviders(Application $app)`** : charge le `{Pascal}ServiceProvider.php` de chaque module actif après `$app->boot()`. Point d'entrée pour les services module lazy.
- **`WebSocket::dispatch(string $channel, array $payload)`** : méthode générique remplaçant les cinq méthodes domaine-spécifiques (`notifyNewGrade`, `notifyNewAbsence`, etc.) conservées comme `@deprecated`.
- **`RBAC::PERMISSIONS`** réduit aux permissions système (admin.*, rgpd.*, notifications.view, parametres.view). Toutes les permissions module viennent de `rbac_permissions` en base (alimentée par `syncPermissions()` à l'activation).
- **ServiceProviders de 16 modules** créés sous `modules/{key}/Providers/` : notes, absences, agenda, bulletins, reporting, notifications, reunions, messagerie, emploi_du_temps, devoirs, facturation, documents, appel, tableau_de_bord, recherche, admin_sessions.
- **Déplacement physique des services** (9 services Scolaire → `modules/{key}/Services/`) et des events (25 classes → `modules/{key}/Events/`). Anciens emplacements `API/Services/Scolaire/*` et `API/Events/*` réduits à des `class_alias` de compat.
- **`EventServiceProvider`** réduit aux seuls events core (`UserCreated`, `UserPasswordChanged`). Listeners domaine enregistrés dans le boot de chaque module ServiceProvider.
- **`bootstrap.php`** : 20 singletons module retirés (sms, email_queue, webpush, visio, analytics, bulletin_pdf, payment, signature, qr_presence, global_search, activity_feed, cross_analytics, metrics, queue pour modules) ; `ScolaireServiceProvider` remplacé par `bootActiveModuleProviders`. Core réduit à ~14 singletons.
- **Nouveaux modules créés** : `devoirs`, `tableau_de_bord`, `recherche`, `admin_sessions` (module.json + ServiceProvider).
- **`SendAbsenceNotificationJob`** déplacé vers `modules/absences/Jobs/`.
- **Endpoints** `messagerie.php` et `agenda_persons.php` proxiés depuis `modules/{key}/endpoints/`.

### Fixed

- `getInstalled()` dans `MarketplaceService` : table `marketplace_installs` → `marketplace_installed` (mismatch schéma).
- `marketplace.php` : double inclusion de `shared_topbar_nav.php` supprimée, `<div class="main-content">` orphelin retiré.
- `logAudit()` inexistante → `app('audit')->log()`.
- `substr(htmlspecialchars(...), 0, 16)` → `htmlspecialchars(substr(..., 0, 16))` (coupure en milieu d'entité HTML).
- `$_SESSION['csrf_token']` dans `accueil.php` → conservé (géré par `shared_header.php`, ne pas remplacer par `app('csrf')->generate()`).

### Security

- `marketplace_installed` : `package_sha256` / `manifest_sha256` / `cert_fingerprint` déclarés `COLLATE ascii_bin` — comparaisons SHA-256 hex case-sensitive, élimine faux positifs CRL.
- `BINARY(32)` pour `root_public_key` — rejet implicite de toute clé Ed25519 d'une longueur incorrecte.

---

## [3.0.0-alpha.1] "Hub" — 2026-05-29

### Added — Marketplace foundations (phase 1, client-side)
- **`.fmod` package format** : ZIP shipping the module + `MANIFEST.sha256` (per-file integrity) + `SIGNATURE.json` (detached Ed25519 over the manifest hash + editor certificate chain).
- **`API\Services\FmodService`** : keygen (Ed25519 via `ext-sodium`), manifest building, package build/sign, full verification (chain → Root CA, revocation, signature, per-file integrity, publisher binding, core compatibility, yank).
- **CLI tools** under `scripts/` :
  - `fmod_keygen.php` — generates an Ed25519 keypair
  - `fmod_cert.php` — issues a Fronote cert (subject signed by an issuer)
  - `fmod_build.php` — packages and signs a module directory into a `.fmod`
  - `fmod_verify.php` — offline verification against `config/marketplace/roots/*.pub`
- **`MarketplaceService::installFromFmod()`** : sideload pipeline (verify → static scan → quarantine on violations → atomic swap → `syncModule` + `provisionSql` → recorded in `marketplace_installed` with package hash + cert fingerprint).
- **`modules/marketplace/`** core module : `module.json`, `Database/install.sql` (tables `marketplace_sources`, `marketplace_installed`, `marketplace_cache`, `marketplace_consents`, `marketplace_advisories_seen`, `marketplace_revocations`), sideload UI page (CSRF-protected, admin only).
- **CI** : `.github/workflows/validate.yml` runs PHP lint, `composer validate`, manifest validation (`tests/validate_manifests.php`), end-to-end fmod self-test (`tests/fmod_selftest.php`), and ModuleSDK smoke test (`tests/module_sdk_smoke.php`).
- **Documentation** : [docs/marketplace.md](docs/marketplace.md) describes the implemented spec, key ceremony, and CLI usage.

### Changed
- `composer.json` requires `ext-sodium`, `ext-zip`, `ext-json`, `ext-pdo` explicitly.
- `.gitignore` blocks `config/marketplace/keys/`, every `*.sk`, and `dist/*.fmod`.

### Security
- Zero network trust : signature verification is offline, against Root CAs embedded under `config/marketplace/roots/*.pub`. TLS is necessary, never sufficient.
- ZIP extraction refuses entries with `..` or absolute paths.
- Signed module ≠ innocuous module : `ModuleScanner` still runs after signature verification, and `QuarantineService` is wired on violations.

### Not yet shipped (phase 2+)
- Central registry HTTP API (`/v1/modules`, CRL publishing).
- Publisher portal and moderation console.
- Sandbox execution during moderation.
- Paid modules.

---

## [2.1.0] "Modular" — 2026-05-29

### Changed — Architecture
- **Modules métier déplacés sous `modules/<clé>/`** ; composants essentiels (`accueil/`, `admin/`, `login/`, `parametres/`, `API/`, `templates/`) restés à la racine.
- **Schéma SQL modularisé** : `pronote.sql` ne crée plus que le socle ; chaque module porte `modules/<m>/Database/install.sql` (idempotent). Provisionnement via `ModuleSDK::provisionSql()` à l'installation et à l'activation. Migrations incrémentales tracées dans `module_migrations`.
- **Installation** : l'assistant provisionne désormais le schéma de **tous** les modules découverts (et non plus seulement les migrations). FK désactivées pendant l'import du socle.
- **`$rootPrefix`** calculé automatiquement par `shared_header.php` depuis `SCRIPT_FILENAME` (profondeur réelle) — corrige CSS/JS/liens des modules imbriqués.
- **Permissions** : `ModuleSDK` sème `module_permissions` (role-based) depuis les `default_roles` des manifestes (INSERT IGNORE). La matrice admin sérialise la grille en un champ JSON unique (contourne `max_input_vars`).

### Changed — Modules
- **Fusion `devoirs` → `cahierdetextes`** : la soumission/correction des devoirs (mes_devoirs, rendre, corriger, voir_rendu) vit désormais dans `cahierdetextes` (onglets « Cahier de textes » / « Devoirs & rendus »). Module `devoirs` retiré.
- **Multi-établissement** : périodes par établissement (trimestre/semestre/annuel, scopées `etablissement_id`) ; gate de reconfiguration en fin d'année scolaire ; onboarding au premier login admin.

### Fixed
- Déconnexion : redirection vers la page de connexion (plus de page blanche).
- Nombreuses erreurs « table doesn't exist » (modules non provisionnés à l'installation).
- Fatals « Cannot redeclare » : `VieScolaireService::getFicheEleve`, `DocumentService::getVersions`.
- Sync permissions : colonne `action_key` inexistante (mauvais schéma) → conversion role-based.
- `reporting` : requêtes alignées sur le schéma réel (`eleves.classe`, `notes.id_eleve`/`trimestre`).
- Route `onboarding` (404) ; CSS non fonctionnel sur plusieurs modules ; liens accueil pointant vers `/modules`.

---

## [2.0.0] "Nova" — 2026-04-09

### Added — 13 New Modules

#### Phase 2 — Portails & Enquêtes
- **portail_parents/** — Consolidated child view, e-signature, QR exit authorizations, ICS calendar, payment history
- **enquetes/** — Multi-page survey builder, anonymous participation, NPS calculation, climate barometer, year-over-year comparison

#### Phase 3 — Scolaire & Sécurité
- **tutorat/** — Algorithmic peer matching (quartile-based), session planning, XP/badges gamification, leaderboard, attestation data
- **intelligence/** — Weighted risk scoring (absences 30% + notes 35% + discipline 20% + engagement 15%), RAG dashboard, pattern detection, auto-recommendations
- **securite/** — PPMS plans, evacuation drills with zone check, hazard registry, emergency alerts, Vigipirate levels
- **accessibilite/** — Accommodations registry, AESH management with calendar, MDPH decisions, ESS planning, RGAA audit

#### Phase 4 — Formation & Logistique
- **formations/** — Training catalog, enrollment workflow, certifications with expiry alerts, budget management, post-training evaluations
- **bourses/** — Eligibility simulator (French national brackets), online applications, instruction workflow, payment scheduling, accounting export
- **inventaire/** — IT asset registry, QR codes, preventive maintenance, loan/return system, depreciation calculation (linear/degressive)
- **echanges/** — Exchange programs (Erasmus+/eTwinning), student applications, host families, CEFR linguistic tracking
- **mediatheque/** — Digital content library, playlists, viewing tracking, ratings/favorites, recommendations, storage quota

#### Phase 5 — New module manifests
- Each new module includes `module.json` manifest with key, category, icon, settings, routes, permissions

### Enhanced — 47 Existing Modules (~200 new features)

#### Pedagogy Modules
- **notes/** — CSV import, configurable weighting by evaluation type, subject-level locking
- **competences/** — Bulk evaluation, cross-reference notes suggestion, LSU export, Cycle 3/4 referentials (D1-D5)
- **devoirs/** — Shingle-based plagiarism detection (Jaccard similarity), peer review, criteria grids
- **cahierdetextes/** — Reusable course templates, read tracking, curriculum alignment, voice notes
- **besoins/** — Multi-stakeholder observations, progress visualization, plan templates, expiry alerts
- **orientation/** — Parcoursup integration (voeux/statuts), interest questionnaire (6 domains), alumni tracking, interview scheduling
- **examens/** — Auto seating plans (alpha/random/alternate), bulk convocations, anonymous copy numbering, CSV result import
- **parcours_educatifs/** — Portfolio generation, bulk validation, photo attachments, progression tracking
- **projets_pedagogiques/** — Budget tracking, Gantt data, parental authorization workflow, project evaluation
- **ressources/** — Versioning, resource sharing, usage statistics, tag-based search
- **bulletins/** — PDF template system, digital signature workflow, parent acknowledgment, class distribution, bulk queue
- **emploi_du_temps/** — Conflict detection, free slot finder, week types (A/B), ICS export, modification notifications

#### Vie Scolaire & Communication
- **absences/** — Pattern detection (by day/by subject), cumulative hours, heatmap data, class comparison
- **appel/** — QR course attendance, precise late recording, period presence export
- **discipline/** — Incident escalation, behavior contracts, academy statistics export
- **vie_scolaire/** — Daily briefing, quick student sheet, cross-module timeline, active alerts
- **signalements/** — Follow-up tracking, assignment, recurrence detection
- **annonces/** — Read acknowledgment, analytics
- **documents/** — Versioning, folder hierarchy
- **reunions/** — Video conference URL, attendance recording, minutes, ICS export
- **trombinoscope/** — Search, trombinoscope data generator, badge data generator
- **support/** — SLA tracking, template responses, satisfaction ratings, FAQ suggestions, internal notes
- **reporting/** — Scheduled reports, KPI tracking, custom SQL report builder

#### Établissement & Logistique
- **bibliotheque/** — ISBN lookup, reading lists, student reader history and stats
- **inscriptions/** — Public portal form, document checklist completion, auto class suggestion, admission letter data, re-enrollment campaigns
- **diplomes/** — QR-verifiable digital diplomas, bulk generation, official register, download tracking
- **stages/** — Convention PDF data, marketplace (offres), visit planning
- **transports/** — GPS stops map (GeoJSON), bus presence tracking, pickup authorizations
- **cantine/** — Nutritional info, menu satisfaction surveys, waste tracking, pre-ordering
- **garderie/** — Real-time present count, activity planning, parent departure notification, monthly summary
- **periscolaire/** — Illustrated catalog, automatic monthly billing, monthly report
- **salles/** — Interactive floor plan, availability calendar, maintenance reports, QR codes, recurring reservations
- **internat/** — Room inspections (cleanliness/order/equipment scoring), evening roll call, exit permissions, weekend activities
- **clubs/** — Session attendance, club budget, photo gallery, waiting list with auto-promotion
- **infirmerie/** — Medication tracking with PAI, epidemic detection, PAI display, monthly stats widget

#### Admin & Navigation
- **parametres/** — Keyboard shortcuts, active sessions management, settings export/import
- **notifications/** — Scheduled notifications, group/class notifications, analytics
- **archivage/** — Scheduled archiving, inter-annual comparison, integrity verification
- **facturation/** — Credit notes (avoirs), treasury dashboard, installment plans (échéancier)
- **personnel/** — Overtime tracking, annual evaluations, leave balance
- **rgpd/** — Processing register (Art. 30), impact analysis (DPIA), data breach management (Art. 33/34), compliance dashboard
- **vie_associative/** — Electronic voting (majority), online membership campaigns, annual report generator
- **agenda/** — Full ICS export, event reminders, agenda statistics, event duplication

### Changed
- Version bumped from 1.0.0 to 2.0.0 "Nova"
- Module count: 47 → 60
- Table count: 156+ → 200+

---

## [1.5.0] "Production" — 2026-04-06

### Added

#### Module Enhancements — Pedagogy (Batch A)
- **notes/** — Batch entry with auto-save, grade locking (`locked_at`/`locked_by`), weighted average calculation, grade distribution statistics, parent notification on new grades
- **competences/** — Configurable referential system, radar graph data, LSU XML export format, link grades to competence evaluations
- **bulletins/** — Live preview, batch generation, appreciation progress tracking per class, customizable PDF templates
- **devoirs/** — Online submission with file upload, late submission tracking (`is_late`), auto-reminders (24h/1h before deadline), teacher annotation, submission dashboard
- **cahierdetextes/** — Rich text entries, multi-file attachments, weekly navigation, copy entry to another class
- **emploi_du_temps/** — Drag-drop schedule editor, conflict detection (room/teacher/class), replacement management with notifications, iCal export
- **examens/** — Exam planning with room assignment, PDF convocations with QR codes, surveillance scheduling
- **agenda/** — Event recurrence (rrule), conflict detection, iCal export, multi-view (day/week/month)

#### Module Enhancements — Student Life & Communication (Batch B)
- **absences/** — Grouped entry, QR presence scanning, online justification upload workflow, SMS alerts, pattern detection (recurring absences)
- **appel/** — Real-time attendance status, history timeline per student, default-present mode
- **discipline/** — Points system with automatic sanction thresholds, discipline timeline, PDF reports
- **vie_scolaire/** — Consolidated dashboard, dropout detection algorithm (absenteeism + grades + incidents scoring)
- **reporting/** — Custom report builder with saved templates, scheduled execution (cron), multi-format export
- **signalements/** — Anonymous reporting with tracking tokens, auto-notification to administration
- **messagerie/** — Already complete: threads, reactions, search, file attachments, WebSocket typing indicators
- **notifications/** — Digest mode (grouped daily emails), bulk operations, filtered listing, notification preferences
- **annonces/** — Already complete: scheduled publishing, read receipts, polls
- **reunions/** — Auto-reminders (24h before), video conference link integration, available slot booking, meeting notes (PV)
- **documents/** — File versioning with history and restore, sharing with role/class targeting

#### Module Enhancements — School & Logistics (Batch C)
- **inscriptions/** — Multi-step form with progress persistence, waitlist management with automatic promotion
- **facturation/** — Auto-billing by service type (cantine/garderie), escalating payment reminders (J+15/J+30/J+45), accounting export
- **stages/** — Weekly journal entries, external evaluation via unique tokens, enterprise directory
- **transports/** — Bus delay signaling with parent notification via push
- **salles/** — Equipment tracking per room (JSON), search rooms by equipment, weekly occupation planning, occupancy rate statistics
- **cantine/** — Allergen conflict detection (cross-reference menu/student allergies), frequentation forecast, 14 EU standard allergens
- **garderie/** — Arrival/departure time tracking, billable hours calculation per month
- **periscolaire/** — Waitlist system with automatic promotion when spots open
- **bibliotheque/** — Book reservation queue with notification when available
- **clubs/** — Session calendar, session management per club, student session view
- **infirmerie/** — Vaccination tracking (7 mandatory vaccines), missing vaccine detection, emergency protocols, frequent visitor tracking, top motifs statistics, monthly statistics
- **trombinoscope/** — RGPD photo consent tracking, consent-filtered class views
- **diplomes/** — Success rate statistics by type/year, mention distribution analysis
- **personnel/** — Leave management workflow (request → approval → auto-create absence), schedule conflict detection, searchable directory
- **ressources/** — Resource sharing with targets (class/role/all), download counter, top downloads
- **internat/** — Evening/morning attendance tracking

#### Module Enhancements — System & Meta (Batch D)
- **support/** — SLA tracking with priority-based targets (urgente: 1h/4h, haute: 4h/24h, normale: 24h/72h, basse: 48h/168h), SLA dashboard metrics, first response recording
- **besoins/** — Periodic evaluation system (JSON), plans needing evaluation detection (>3 months threshold)
- **orientation/** — Career catalog (fiches métiers by sector), counselor appointment booking, orientation history across years
- **parcours_educatifs/** — Student portfolio with file/link attachments, teacher validation workflow
- **projets_pedagogiques/** — Budget tracking with expense recording, budget summary (planned/spent/remaining), kanban board view
- **vie_associative/** — Budget summary (recettes/dépenses/solde), upcoming events across associations, association statistics
- **accueil/** — Already complete: drag-drop widgets, role-based defaults, layout save/load, widget cache
- **archivage/** — Student dossier transfer export (notes, absences, bulletins, health records as JSON)
- **parametres/** — Privacy level setting (public/private profiles)
- **rgpd/** — Already complete: data export, anonymization, consent tracking, retention policies

### Changed
- `version.json` — version bumped to 1.5.0 "Production"
- `README.md` — updated version badge, expanded documentation cross-reference (16 docs linked)

---

## [1.4.0] "Horizon" — 2026-04-04

### Added

#### Infrastructure
- SQL migration system (`API/Services/MigrationService.php`, `API/Commands/migrate.php`)
- Environment detection (`API/Core/Environment.php`) with dev toolbar
- Maintenance mode with admin UI, IP whitelist, and ETA (`API/Services/MaintenanceService.php`)
- Custom error pages (404, 403, 500, 503) with `API/Core/ErrorHandler.php`
- Health check service with DB latency, disk, cache, SMTP, WebSocket, PHP checks

#### UI & Design System
- 17 PHP UI components (`API/UI/Components.php`): card, table, modal, form_group, tabs, badge, toast, skeleton, dropdown, button, alert, pagination, breadcrumb, avatar, stat_card, empty_state
- CSS utility classes (spacing, flex, text, display) in `assets/css/base.css`
- BEM naming convention across all components (`assets/css/components.css`)
- Design tokens refinement (4px grid, subtle shadows)

#### Internationalization (i18n)
- 8 supported locales: FR, EN, ES, DE, RU, NL, AR, TH
- 384 translation files (48 modules x 8 locales) in `lang/{locale}/modules/`
- RTL support for Arabic (`assets/css/rtl.css`, `[dir="rtl"]` selectors)
- Language selector on login page with flag indicators
- Date/number/currency formatting via `IntlDateFormatter` and `NumberFormatter`
- Admin translation management page (`admin/systeme/translations.php`)

#### Credits System
- `author`, `author_url`, `contributors`, `license` fields in all 47 `module.json` files
- Credits persisted in `modules_config` table
- Credits page (`admin/modules/credits.php`) and About page (`admin/about.php`)

#### Feature Flags
- ~80 granular feature flags covering sub-features across all 47 modules
- Admin UI for flag management (`admin/systeme/feature_flags.php`)
- Toggle switches, search/filter, grouped by module
- Migration for bulk flag insertion

#### WebSocket Security
- WSS/TLS support with configurable cert/key paths
- JWT-based authentication with 20-minute token rotation
- Heartbeat mechanism (30s ping, 90s timeout)
- Rate limiting: 30 events/min per connection
- Room membership verification via HTTP callback
- Admin live dashboard (`admin/systeme/live.php`)

#### Marketplace Security
- SHA-256 integrity verification for downloaded packages
- Static analysis scanner (`API/Security/ModuleScanner.php`)
- Blocked dangerous functions: `eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`
- Quarantine system for suspicious modules (`API/Services/QuarantineService.php`)
- Automatic backup before module installation with rollback support
- Module permission system (`required_permissions`, `optional_permissions`)

#### AJAX Framework
- Client-side utility (`assets/js/fronote-ajax.js`): post, get, delete, submitForm, confirmDelete, upload
- Server-side response class (`API/Core/AjaxResponse.php`): success, error, redirect, paginated, guard

#### Monitoring & Maintenance
- System monitoring dashboard (`admin/systeme/monitoring.php`)
- Daily maintenance cron: audit cleanup, DB backup, rotation, cache GC, token purge, rate limit cleanup, temp files, sessions, notifications, orphan uploads, translation coverage report
- Hourly maintenance cron: cache GC, health check refresh, disk space check, rate limit cleanup

#### Documentation
- `CONTRIBUTING.md` — contributor guide with setup, code style, architecture, PR process
- `SECURITY.md` — security policy with vulnerability reporting and measures
- `CODE_OF_CONDUCT.md` — community standards
- `CHANGELOG.md` — this file
- GitHub issue templates (bug report, feature request) and PR template
- Technical docs: theme development, translation guide, deployment guide

### Changed
- `docs/module-sdk.md` — added credits, settings schema, AJAX, UI components sections
- `docs/security.md` — added marketplace scanning, module permissions, WebSocket security
- `README.md` — added i18n badge, contributing/security links, feature flags mention
- `templates/shared_header.php` — loads `fronote-ajax.js` globally
- `login/index.php` — all strings use `__()`, language selector, RTL support

---

## [1.3.0] — 2026-03-01

### Added
- Initial 47-module architecture
- IoC Container with service providers
- RBAC with 6 user types
- WebSocket server (Socket.IO) with global client
- Design system with CSS tokens and themes (classic/glass)
- Marketplace for module distribution
- Dashboard with drag-and-drop widgets

---

## [1.2.0] — 2026-01-15

### Added
- Audit logging system
- Rate limiting with exponential backoff
- File upload service with context-based validation
- Import/Export service for users and configuration

---

## [1.1.0] — 2025-11-01

### Added
- API token authentication (Bearer tokens)
- Module settings schema system
- Notification center with multi-channel support

---

## [1.0.0] — 2025-09-01

### Added
- Initial release of Fronote
- Core modules: accueil, notes, absences, emploi du temps, messagerie
- Session-based authentication
- MySQL/MariaDB database with PDO
- Apache with mod_rewrite routing
