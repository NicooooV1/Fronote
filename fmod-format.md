# Format de paquet module Fronote — Spécification `.fmod` v1

> Plateforme **4.0.0** (build 2026-08-11) · Format **.fmod v1** · Signature **Ed25519** (ext-sodium)

## Vue d'ensemble

Un fichier `.fmod` est une **archive ZIP** au contenu déterministe, accompagnée d'une **signature Ed25519 détachée** et d'un **manifeste de hachage par fichier**. L'extension `.fmod` est opaque : n'importe quel outil ZIP peut en inspecter le contenu.

Le format sert au **sideload** : on téléverse un paquet `.fmod` signé dans l'admin (ou via CLI), Fronote vérifie la chaîne de signature **contre une Root CA embarquée** (`config/marketplace/roots/*.pub`), puis installe le module. Modèle de confiance « zero network trust » : la signature est vérifiée quel que soit l'URL source ; TLS est nécessaire mais jamais suffisant.

> ⚠️ **Pas de migrations.** Le schéma d'un module = `Database/install.sql` (un seul fichier, `CREATE TABLE IF NOT EXISTS`, schéma final complet). Il **n'existe plus** de dossier `migrations`, ni de clé `migrations` dans `module.json`, ni de table `module_migrations`. À l'installation, `ModuleSDK::provisionSql($key)` exécute **uniquement** `install.sql` (idempotent).

Code de référence :
- `API/Services/FmodService.php` — build, signature, vérification, extraction.
- `API/Services/MarketplaceService.php` — pipeline d'installation (`installFromFmod`, `confirmInstall`, `deployFromStaging`).
- `API/Services/ModuleSDK.php` — `discover()`, `syncModule()`, `provisionSql()`.

## Structure de l'archive

À la **racine** du ZIP (pas de dossier parent imposé — `MarketplaceService::extractZip` du flux catalogue remonte d'ailleurs un éventuel dossier racine unique) :

```
{module_key}-{version}.fmod        (ZIP)
├── MANIFEST.sha256                ← hachage SHA-256 par fichier (signé)
├── SIGNATURE.json                 ← signature Ed25519 détachée + chaîne de certificats
├── module.json                    ← métadonnées du module (obligatoire)
├── Database/
│   └── install.sql                ← schéma final (CREATE TABLE IF NOT EXISTS)
├── <route principale>.php         ← ex. notes.php, déclaré dans routes.main
├── includes/                      ← header.php, footer.php, providers, services
├── assets/                        ← css/, js/, img/ propres au module
├── widgets/                       ← templates de widgets dashboard (si déclarés)
├── api/                           ← endpoints AJAX (si routes.api)
└── lang/<locale>/<domaine>.json   ← traductions (optionnel)
```

Fichiers **toujours exclus** du paquet ET du manifeste (`FmodService::EXCLUDE_NAMES`) :
`.git`, `.gitignore`, `.DS_Store`, `node_modules`, `.idea`, `.vscode`.
`MANIFEST.sha256` et `SIGNATURE.json` sont eux-mêmes exclus du manifeste (ce sont des méta-fichiers).

## `module.json` (manifeste)

Source de vérité du module. Lu par `ModuleSDK::discover()` (scan de `modules/*/module.json` **et** `*/module.json` à la racine) et validé par `ModuleSDK::validate()`.

### Champs obligatoires

`ModuleSDK::REQUIRED_FIELDS = ['key', 'name', 'icon', 'category']`.

| Champ | Type | Règle |
|-------|------|-------|
| `key` | string | minuscules, `^[a-z][a-z0-9_]*$`. Doit être unique et égal au nom du dossier. |
| `name` | objet | au moins la clé `fr` (ex. `{"fr":"Notes","en":"Grades"}`). |
| `icon` | string | classe Font Awesome (ex. `fas fa-chart-bar`). |
| `category` | string | une des valeurs valides ci-dessous. |

Catégories valides (`ModuleSDK::VALID_CATEGORIES`) :
`navigation`, `scolaire`, `vie_scolaire`, `communication`, `etablissement`, `logistique`, `outils`, `administration`, `systeme`, `sante`, `custom`.

### Champs courants (optionnels mais usuels)

| Champ | Rôle |
|-------|------|
| `version` | SemVer du module (ex. `1.0.0`). Utilisé pour les enregistrements d'install et le yank. |
| `description` | objet `{fr, en}`. |
| `core` | `true` pour un module système non désinstallable (`MarketplaceService::uninstallModule` refuse via `ModuleService::isCore`). |
| `requires_php` | contrainte PHP simple (`>=8.0`, `8.1`, …), vérifiée au sideload. |
| `fronote_min` / `fronote_max` | intervalle de compatibilité cœur (flux catalogue / preflight). |
| `dependencies` | tableau de `key` de modules requis (présence vérifiée). |
| `routes` | `{ "main": "notes.php", "api": "api/actions.php" }`. |
| `database` | `{ "install": "Database/install.sql" }` — chemin relatif du SQL d'install. **Défaut** `Database/install.sql` si absent. |
| `permissions` | par action : `{ "view": { "default_roles": ["*"] }, "edit": {...} }`. |
| `widgets` | tableau de widgets dashboard (`key`, `name`, `data_provider`, `template`, `roles`, …). |
| `settings_schema` | schéma des réglages éditables (type, default, label i18n). |
| `establishment_types` | `null` (tous) ou tableau de types d'établissement. |
| `sidebar` / `topbar` | `{ "category": "...", "sort_order": N }` pour l'ordre dans la barre. |
| `author`, `author_url`, `contributors`, `license` | métadonnées éditeur. |

> Il n'y a **PAS** de clé `migrations`. Le schéma est entièrement porté par `database.install`.

### Bloc `publish` (signature / marketplace)

Le bloc `publish` n'est pas requis pour qu'un module fonctionne localement, mais il est **indispensable pour un paquet `.fmod` signé** : `FmodService::verifyInternal()` exige que `publish.publisher_id` soit présent et **identique** dans `module.json`, `SIGNATURE.json` et le certificat éditeur.

```json
"publish": {
  "publisher_id": "fronote-team",
  "required_permissions": ["db_read", "db_write"],
  "optional_permissions": [],
  "min_core": "3.0.0",
  "max_core": "4.0.0"
}
```

- `publisher_id` — identifiant éditeur, doit matcher les trois couches (manifeste/signature/cert).
- `required_permissions` — permissions demandées au module ; passées à `ModuleScanner` et utilisées pour le **consentement** (l'install est suspendue tant que l'admin n'a pas accepté).
- `min_core` / `max_core` — contraintes de version cœur vérifiées par `FmodService::semverSatisfies()` (supporte `>=X`, `<X`, `^X.Y.Z`, `~X.Y`, `X.Y.*`, et conjonctions séparées par espace).

### `test_only`

`"test_only": true` → le module ne peut être installé que si `ALLOW_TEST_MODULES=true` dans `.env` (`MarketplaceService::isTestModulesAllowed()`). Sinon le sideload est refusé. Sert aux modules de test (ex. `hello_world`, `channel: "test"`).

### Exemple réel — `modules/hello_world/module.json`

```json
{
  "key": "hello_world",
  "version": "1.0.0",
  "name": { "fr": "Hello World (Test)", "en": "Hello World (Test)" },
  "icon": "fas fa-flask",
  "category": "systeme",
  "core": false,
  "requires_php": ">=8.0",
  "fronote_min": "2.1.0",
  "fronote_max": "4.0.0",
  "channel": "test",
  "test_only": true,
  "routes": { "main": "hello_world.php" },
  "database": { "install": "Database/install.sql" },
  "permissions": { "view": { "default_roles": ["administrateur"] } },
  "publish": {
    "publisher_id": "fronote-team",
    "required_permissions": ["db_read", "db_write"],
    "optional_permissions": []
  }
}
```

## `Database/install.sql`

Schéma **final et complet** du module, idempotent. Conventions :

- `CREATE TABLE IF NOT EXISTS` (jamais de `DROP`).
- Tables des modules métier préfixées par la clé (`hello_world_log`, …).
- Données scopées par établissement : prévoir `etablissement_id` sur les tables concernées (cf. `\API\Core\EstablishmentContext::id()`).
- Exécuté par `ModuleSDK::provisionSql($key)` → `execSchemaSql()` : désactive les FK le temps du script, exécute chaque instruction séparément (un échec isolé n'interrompt pas les suivants), pas de transaction (le DDL provoque un commit implicite).

Exemple (`modules/hello_world/Database/install.sql`) :
```sql
CREATE TABLE IF NOT EXISTS `hello_world_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event` VARCHAR(64) NOT NULL,
  `payload` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> Lors d'une **mise à jour** de l'app (bouton unique `admin/systeme/update.php`), c'est `SchemaSyncService::sync()` qui réconcilie le schéma de façon déclarative et **additive** (CREATE des tables manquantes + ADD COLUMN des colonnes manquantes, lues depuis les `install.sql`/`pronote.sql`/`rgpd/Database/*.sql`), sans `DROP` ni changement de type. Les transformations non additives passent, elles, par des **migrations de données versionnées au niveau du dépôt** (`database/migrations/*.php` via `MigrationRunner`, juste après `SchemaSyncService`) — un paquet `.fmod`, lui, n'embarque **jamais** de migration.

## `MANIFEST.sha256`

Une ligne par fichier, **triée par chemin** (`ksort`, `SORT_STRING`). Format :

```
<sha256_hex>  <chemin_relatif>
```

(double espace entre le hash et le chemin ; chemins en `/`, jamais `\`). Exemple :
```
a1b2c3...  Database/install.sql
d4e5f6...  module.json
7a8b9c...  notes.php
```

Construit par `FmodService::buildManifest()`, relu par `parseManifest()` (regex `^([0-9a-f]{64})\s{2,}(.+)$`).

## `SIGNATURE.json`

Signature Ed25519 **détachée** produite par `FmodService::signManifest()`. La signature porte sur **`sha256(contenu de MANIFEST.sha256)`** — le hash du fichier de hash, pas le ZIP entier.

```json
{
  "alg": "Ed25519",
  "publisher_id": "fronote-team",
  "manifest_sha256": "<sha256-hex-de-MANIFEST.sha256>",
  "signature": "<base64-de-la-signature-Ed25519-64-octets>",
  "certificate_chain": ["<base64-cert-editeur>", "<base64-cert-intermediaire>"],
  "signed_at": "2026-05-31T10:00:00Z"
}
```

- `alg` doit valoir exactement `Ed25519` (`FmodService::SIG_ALG`).
- `certificate_chain` : **index 0 = certificat éditeur** (le plus interne), puis intermédiaires. La Root CA **n'est pas** dans la chaîne (elle est embarquée côté client).

## Format de certificat

Chaque entrée de `certificate_chain` est un **JSON encodé en base64**. Document signé par l'émetteur :

```json
{
  "publisher_id": "fronote-team",
  "public_key_b64": "<clé-publique-Ed25519-32-octets-base64>",
  "issuer_fp": "<sha256-hex-de-la-clé-publique-émettrice>",
  "issued_at": "2026-01-01T00:00:00Z",
  "expires_at": "2027-01-01T00:00:00Z",
  "signature_b64": "<signature-Ed25519-base64-par-l-émetteur>"
}
```

La signature (`signature_b64`) porte sur la **forme canonique** de tous les autres champs : lignes `clé=valeur` triées alphabétiquement et jointes par `\n`, sans `signature_b64` (`FmodService::canonicalCertBytes()`). Ce format évite les variations de `json_encode` entre runtimes.

## Chaîne de confiance

```
Root CA (config/marketplace/roots/*.pub)      ← clé publique embarquée, 32 octets Ed25519, base64
  └── cert intermédiaire (certificate_chain[1])
        └── cert éditeur (certificate_chain[0])
              └── signe MANIFEST.sha256
```

`FmodService::verifyCertChain()` parcourt la chaîne : chaque cert doit être signé par le suivant (`issuer_fp` = `sha256(clé publique du suivant)`), non expiré, et **le dernier doit être signé par une Root CA configurée** (`issuer_fp` ∈ fingerprints de `roots/*.pub`). Tant qu'aucun `.pub` n'est présent, `installFromFmod()` refuse **tout** sideload.

## Pipeline d'installation (sideload `.fmod`)

`MarketplaceService::installFromFmod($fmodPath)` puis `deployFromStaging()` :

1. Le paquet existe ; au moins une Root CA chargée depuis `config/marketplace/roots/*.pub` (sinon refus).
2. **Verrou global** (`storage/tmp/marketplace.install.lock`, `flock`) — interdit deux installs concurrentes.
3. `FmodService::verifyAndExtract()` — **une seule ouverture du ZIP** (élimine le TOCTOU verify→extract) :
   - `alg = Ed25519` ; chaîne de certificats valide → Root CA ; cert éditeur non révoqué (hook `marketplace_revocations`) ;
   - signature Ed25519 vérifiée sur `sha256(MANIFEST)` ;
   - SHA-256 **par fichier** comparé au manifeste, en streaming ; écriture atomique (`.part` renommé seulement si le digest correspond) sous le dossier de staging ; noms d'entrée vérifiés (anti-path-traversal) ;
   - `publisher_id` identique entre manifeste / signature / cert ; `min_core`/`max_core` satisfaits ; version non yankée (hook `marketplace_advisories_seen`).
4. `test_only` refusé sauf `ALLOW_TEST_MODULES=true`.
5. **Scan statique** `ModuleScanner` (défense en profondeur — la signature garantit l'origine, pas l'innocuité). Violations critiques → `QuarantineService::quarantine()` (le staging est sorti du runtime), installation refusée.
6. **Consentement** : si des permissions sont demandées (`publish.required_permissions` / `permissions_requested`), l'install est suspendue (`pending_consent`), le staging est conservé ; l'admin confirme via `confirmInstall()`.
7. **Bascule atomique** (`deployFromStaging`) : sauvegarde de l'éventuel dossier live existant dans `storage/backups/modules/<key>_<horodatage>`, puis `rename(staging → modules/<key>/)`.
8. `ModuleSDK::clearCache()` + `syncModule($manifest)` (peuple `modules_config`, `dashboard_widgets`, `module_permissions`) + **`provisionSql($key)` → exécute `install.sql` uniquement**.
9. Enregistrement dans `marketplace_installed` (`package_sha256`, `manifest_sha256`, `cert_fingerprint`, `publisher_id`, `channel='sideload'`, `signature_verified_at`).

Limite de taille : **50 Mo** (vérifiée à l'upload côté UI, `modules/marketplace/marketplace.php`). Le téléversement n'accepte que `*.fmod`.

> Le flux **catalogue distant** (`installModule()`) est distinct : il télécharge un ZIP non signé Ed25519, vérifie un SHA-256 optionnel du registre, scanne, déploie, et enregistre dans `marketplace_installs`. En **production** il est **désactivé** sauf `MARKETPLACE_ALLOW_UNSIGNED=true` : on exige un `.fmod` signé.

## Créer un `.fmod`

### En PHP (API `FmodService`)

```php
require 'API/bootstrap.php';
use API\Services\FmodService;

$svc = new FmodService();           // pas de Root CA nécessaire pour BUILD (seulement pour verify)
$svc->buildPackage(
    './modules/cantine',            // dossier source (doit contenir module.json)
    './dist/cantine-2.3.1.fmod',    // sortie .fmod
    $editorSecretKeyB64,            // clé secrète éditeur Ed25519 (base64)
    'fronote-team',                 // publisher_id (doit matcher module.json publish.publisher_id)
    [$editorCertB64, $intermediateCertB64] // chaîne : cert éditeur d'abord
);
```

### Plus d'outils CLI (`scripts/` supprimé)

Les anciens scripts `scripts/fmod_*.php` et `scripts/pki/generate-test-ca.sh` ont été **retirés**
(suppression du répertoire `scripts/`). Construction, signature, vérification et génération de clés
passent désormais par l'**API PHP `\API\Services\FmodService`** :

| Méthode | Rôle |
|---------|------|
| `FmodService::generateKeypair(): array` | keypair Ed25519 (`secret_key_b64`, `public_key_b64`, `fingerprint`). |
| `FmodService::fingerprint()` / `certFingerprint()` / `canonicalCertBytes()` | empreintes de clé/cert, octets canoniques à signer (primitives pour émettre un certificat). |
| `$svc->buildManifest($src)` + `signManifest(...)` | construit puis signe le `MANIFEST.sha256`. |
| `$svc->buildPackage($src, $out, $secretKeyB64, $publisherId, $certChainB64)` | construit et signe le `.fmod` (exemple ci-dessus). |
| `$svc->verifyPackage(...)` / `verifyAndExtract(...)` | signature + intégrité (charge `config/marketplace/roots/*.pub`). |

L'**installation** se fait par le sideload dans l'UI marketplace (`MarketplaceService`), pas en CLI.

## Workflow de dev / test

En développement, générez un keypair et construisez le paquet via l'API PHP :

```php
require 'API/bootstrap.php';
use API\Services\FmodService;

[$secretKeyB64, $publicKeyB64, $fingerprint] = FmodService::generateKeypair();
(new FmodService())->buildPackage(
    './modules/mon_module', '/tmp/mon_module-1.0.0.fmod', $secretKeyB64, 'fronote-team'
);
```

Pour une **PKI de test** (Root CA + intermédiaire + cert éditeur), le générateur
`generate-test-ca.sh` n'est plus fourni : composez la chaîne avec les primitives `FmodService`
(`generateKeypair`, `canonicalCertBytes`, `certFingerprint`) depuis un contexte PHP, puis déposez
la clé publique du Root CA de test dans `config/marketplace/roots/` (voir son README). Les modules
de test exigent `ALLOW_TEST_MODULES=true` dans `.env`.

⚠️ Ne **jamais** committer de fichier `*.key` / `*.sk`. La clé privée Root CA reste hors-ligne (HSM ou stockage air-gapped) ; cf. `config/marketplace/roots/README.md`.

## Propriétés de sécurité

| Propriété | Garantie |
|-----------|----------|
| Intégrité (anti-altération) | ✓ SHA-256 par fichier + signature du manifeste |
| Authenticité (origine) | ✓ chaîne de certificats → Root CA embarquée |
| Non-répudiation | ✓ Ed25519 déterministe |
| Anti-rejeu / révocation | ✓ révocation cert (`marketplace_revocations`) + yank de version (`marketplace_advisories_seen`) |
| Extraction sûre | ✓ noms vérifiés, traversée bloquée, commit atomique par fichier |
| Concurrence | ✓ verrou `flock` global pendant l'install |

Ed25519 garantit intégrité, authenticité et non-répudiation. Il **ne** garantit **pas** la qualité du code ni l'absence de logique malveillante ou de non-conformité RGPD : `ModuleScanner` fournit la couche d'analyse statique complémentaire, et le consentement explicite des permissions reste requis.
