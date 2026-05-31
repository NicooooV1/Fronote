# Changelog

All notable changes to Fronote will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
