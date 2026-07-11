# Tests unitaires (Fronote)

Suite PHPUnit couvrant la sécurité, le cloisonnement multi-tenant, la crypto,
le modèle de comptes 3-mondes et les invariants de mise à jour. Elle tourne sur
**SQLite en mémoire + réflexion** (aucun serveur requis) ; certains tests
s'auto-marquent « skipped » si une dépendance runtime (`app()`) est absente.

> La CI (`.github/workflows/validate.yml`) provisionne en plus un **service MySQL**
> pour les tests d'intégration et exécute PHPStan (bloquant) + `npm audit`.

## Lancer les tests

```bash
composer install
composer test                     # = php vendor/bin/phpunit --configuration phpunit.xml
php vendor/bin/phpunit --no-coverage
```

`phpunit.xml` déclare la suite « unit » (`tests/Unit`, bootstrap `vendor/autoload.php`).

**État actuel : 29 fichiers, 127 tests, 368 assertions — verts.**

## Suite (`tests/Unit/`)

### Authentification, session, 2FA
- **CsrfTest** — jetons CSRF rotatifs à usage unique (génération / validation / rejeu).
- **TotpReplayTest** — anti-rejeu TOTP : un même pas-de-temps ne peut être consommé deux fois.
- **ChangePasswordIsolationTest** — `changePassword()` ne touche que la table du profil ciblé.
- **AccountUsableTest** — refus des comptes désactivés (`actif=0`) / verrouillés (`locked_until`).
- **PasswordSyncTest**, **OnboardingTest** — synchro de mot de passe inter-profils, gate d'onboarding.

### Chiffrement
- **EncryptionTest** — AES-256-GCM `\API\Core\Encryption` : round-trip, détection du chiffré,
  compatibilité v1 (SHA-256) ↔ v2 (HKDF, `KEY_VERSION=2`).

### Cloisonnement multi-tenant & autorisations
- **TenantIsolationTest** — les requêtes métier restent bornées à `etablissement_id`.
- **AuthorizationScopeTest**, **ScopeResolverTest**, **AuthzBasculeTest** — résolution de portée,
  bascule d'autorisation.
- **ReadOnlyGuardTest**, **RoleManagementGuardTest** — gardes lecture-seule et gestion des rôles.

### Comptes & rôles (modèle 3-mondes)
- **AccountServiceTest**, **CreateMirrorAccountTest**, **RelationshipServiceTest**,
  **UserProviderAccountsTest** — comptes unifiés, miroir tenant, relations parent↔élève.
- **RoleBridgeTest**, **RoleCatalogHelpersTest** — catalogue RBAC et ponts de rôles.
- **PlatformLayerTest**, **TenantLayerTest**, **PlatformInfraTest** — séparation plateforme / tenant.

### Support & impersonation
- **SupportBridgeTest**, **SupportImpersonationTest**, **SupportReportTest** — passerelle de
  support, impersonation encadrée, signalements.

### Infrastructure & mise à jour
- **MigrationRunnerTest** — migrations versionnées (`up()/down()`, journal `schema_migrations`).
- **BackupIntegrityTest** — intégrité des sauvegardes de base.
- **ProductionReadinessCheckerTest** — contrôles de configuration de production.
- **UploadValidationTest** — whitelist d'extensions (`php`/`phtml`/`phar` exclus).

## Ajouter un test

Un fichier `tests/Unit/<Nom>Test.php` étendant `PHPUnit\Framework\TestCase`. Pour les
dépendances runtime absentes hors serveur, `markTestSkipped()` plutôt qu'échouer.
