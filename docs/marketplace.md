# Marketplace — Guide (v3.2.4)

Module `marketplace` v1.5.2 — pipeline d'installation signé Ed25519, format `.fmod` v1, modules de test, consentement des permissions.

---

## Table des matières

- [Modèle de confiance](#modèle-de-confiance)
- [Format `.fmod`](#format-fmod)
- [Manifeste `publish`](#manifeste-publish)
- [Modules de test (test_only)](#modules-de-test-test_only)
- [Pipeline d'installation](#pipeline-dinstallation)
- [Consentement des permissions](#consentement-des-permissions)
- [Infrastructure PKI](#infrastructure-pki)
- [CLI et scripts](#cli-et-scripts)
- [Module de référence hello_world](#module-de-référence-hello_world)
- [Tables de base de données](#tables-de-base-de-données)
- [Sécurité](#sécurité)
- [Phases suivantes](#phases-suivantes)

---

## Modèle de confiance

```
Root CA  ──(signe)──▶  Cert intermédiaire  ──(signe)──▶  Cert éditeur  ──(signe)──▶  MANIFEST.sha256
   ▲                                                                                          │
   │ clé publique (32 bytes Ed25519)                                                          │
   │ config/marketplace/roots/*.pub                                                           │
   └────────────── client vérifie la chaîne + chaque fichier ────────────────────────────────┘
```

- **Signature** : Ed25519 via `ext-sodium` (pas de dépendance Composer).
- **Intégrité** : SHA-256 par fichier, vérifié en flux pendant extraction (pas de TOCTOU entre verify et extract).
- **Aucune confiance réseau** : TLS requis, jamais suffisant. Vérification offline contre Root CA embarquée.
- Root CA **production** hors-ligne (air-gapped). Root CA **test** distincte, distribuée avec instances dev.

---

## Format `.fmod`

Spec publique complète : [`fmod-format.md`](../fmod-format.md).

```
{module_key}-{version}.fmod   (ZIP)
├── MANIFEST.sha256      ← SHA-256 par fichier (trié par chemin, signé)
├── SIGNATURE.json       ← Signature Ed25519 détachée + chaîne de certificats
├── module.json          ← Métadonnées du module
└── <arborescence source>
```

**`SIGNATURE.json`** :

```json
{
  "alg": "Ed25519",
  "publisher_id": "fronote-team",
  "manifest_sha256": "<sha256-hex-de-MANIFEST.sha256>",
  "signature": "<base64-Ed25519-64-bytes>",
  "certificate_chain": ["<base64-cert-éditeur>", "<base64-cert-intermédiaire>"],
  "signed_at": "2026-05-31T10:00:00Z"
}
```

La signature couvre `sha256(MANIFEST.sha256)` — le hash du fichier de hashes. La Root CA n'est pas dans la chaîne ; elle est lue depuis `config/marketplace/roots/*.pub`.

---

## Manifeste `publish`

```json
{
  "key": "cantine",
  "version": "2.3.1",
  "publish": {
    "publisher_id": "fronote-team",
    "min_core": ">=2.1.0",
    "max_core": "<4.0.0",
    "channel": "stable",
    "license": "MIT",
    "required_permissions": ["db_read", "db_write"],
    "optional_permissions": ["network"]
  }
}
```

Le client refuse l'installation si :

- La chaîne de certificats ne remonte pas à une Root CA reconnue
- Un certificat de la chaîne est révoqué (`marketplace_revocations`)
- La signature Ed25519 ne valide pas `sha256(MANIFEST.sha256)`
- Un fichier extrait ne correspond pas à son SHA-256 dans le manifeste
- `publisher_id` incohérent entre manifeste, signature et certificat
- La version du cœur ne satisfait pas `min_core`/`max_core`
- La version est yankée (`marketplace_advisories_seen` haute/critique)

---

## Modules de test (test_only)

Les modules test valident le pipeline `.fmod` sans logique métier.

**Activation sur une instance dev/staging** :

```env
# .env
ALLOW_TEST_MODULES=true
```

**`module.json` d'un module test** :

```json
{
  "channel": "test",
  "test_only": true,
  "publish": {
    "required_permissions": ["db_read", "db_write"]
  }
}
```

> **Règle** : `test_only=true` + `network` dans `required_permissions` → refusé dès l'étape 3 (validation du manifeste), avant tout traitement cryptographique.

**Catalogue test** : `GET /API/endpoints/test_catalog.php` (requiert auth + `ALLOW_TEST_MODULES=true`). Registry configurable via `MARKETPLACE_TEST_REGISTRY_URL` dans `.env`.

---

## Pipeline d'installation

`MarketplaceService::installFromFmod(string $fmodPath): array`

| # | Étape | Erreur → |
|---|-------|---------|
| 1 | ZIP valide, ≤ 50 Mo (configurable `FMOD_MAX_SIZE`) | rejet |
| 2 | `MANIFEST.sha256` + `SIGNATURE.json` présents | rejet |
| 3 | JSON valide, champs requis, channel reconnu, `test_only` + `network` interdit | rejet |
| 4 | `test_only` vs `ALLOW_TEST_MODULES` | rejet |
| 5 | Chaîne de certificats → Root CA dans `config/marketplace/roots/` | quarantaine |
| 6 | Fingerprint cert éditeur absent de `marketplace_revocations` | quarantaine |
| 7 | Signature Ed25519 valide sur `sha256(MANIFEST)` | quarantaine |
| 8 | SHA-256 de chaque fichier extrait = MANIFEST | quarantaine |
| 9 | `publisher_id` cohérent (manifeste ∩ signature ∩ certificat) | quarantaine |
| 10 | `ModuleScanner` statique sur code extrait | quarantaine si violations |
| 11 | Consentement admin des `permissions_requested` | suspend → `pending_consent` |
| 12 | Bascule atomique : backup live → `rename(staging, modules/{key}/)` | rollback |
| 13 | `ModuleSDK::syncModule` + `provisionSql` | module désactivé |
| 14 | Insertion dans `marketplace_installed` (hash + fingerprint + horodatage) | toujours |

---

## Consentement des permissions

Si `permissions_requested` est non vide, `installFromFmod()` retourne `pending_consent: true` avec le staging dir. L'interface suspend l'installation et affiche un écran de consentement : l'admin coche chaque permission explicitement.

```php
// Retour pending_consent
[
  'success'         => false,
  'pending_consent' => true,
  'staging'         => '/storage/tmp/_staging_abc123',
  'manifest'        => [...],
  'permissions'     => ['db_read', 'db_write'],
  'sig_data'        => [...],
  'fmod_path'       => '/storage/tmp/sideload_xyz.fmod',
]

// Finalisation après consentement
$marketplace->confirmInstall($staging, $manifest, $fmodPath, $sigData);
```

Le consentement est enregistré dans `marketplace_consents` avec `granted_by` (ID admin) et `granted_by_name` (nom dénormalisé — conservé après suppression de l'admin, traçabilité RGPD).

**Permissions reconnues** :

| Permission | Description |
|-----------|-------------|
| `db_read` | Lecture base de données |
| `db_write` | Écriture base de données |
| `filesystem` | Accès système de fichiers |
| `network` | Appels réseau sortants |
| `email` | Envoi d'emails |

---

## Infrastructure PKI

### Hiérarchie

| Niveau | Rôle | Durée |
|--------|------|-------|
| Root CA production | Offline, air-gapped. `config/marketplace/roots/fronote-root.pub` | 10 ans |
| Root CA test | Distincte prod. Distribuée avec instances dev. | 2 ans |
| Intermediate CA | Signe certificats éditeurs. Révocable via CRL. | 18 mois |
| Certificat éditeur | Clé de l'éditeur, dans `SIGNATURE.json`. | 12 mois |

### Format certificat Fronote

Un certificat est un **JSON base64-encodé** :

```json
{
  "publisher_id": "fronote-team",
  "public_key_b64": "<base64-Ed25519-32-bytes>",
  "issuer_fp": "<sha256-hex-clé-émetteur>",
  "issued_at": "2026-01-01T00:00:00Z",
  "expires_at": "2027-01-01T00:00:00Z",
  "signature_b64": "<base64-signature-émetteur>"
}
```

Payload signé : lignes `key=value` triées alphabétiquement, jointes par `\n`, sans `signature_b64`. Défini par `FmodService::canonicalCertBytes()`.

### Clé publique Root CA (`.pub`)

```
# config/marketplace/roots/fronote-root.pub
<base64-32-bytes-Ed25519-pubkey>
```

Une ligne base64, 32 bytes une fois décodés.

---

## CLI et scripts

### Générer l'infrastructure PKI de test

```bash
bash scripts/pki/generate-test-ca.sh [output_dir]
# Génère Root CA test, Intermediate CA, cert éditeur fronote-team, keypair libsodium.
# Copie fronote-test-root.pub dans config/marketplace/roots/ automatiquement.
```

### Installer un module en CLI

```bash
php scripts/install-module.php ./module-1.0.0.fmod [--allow-test] [--dry-run]
# --allow-test  → autorise modules test_only
# --dry-run     → vérifie signature + scan sans installer
```

### Construire un `.fmod` (PHP)

```php
$fmod = new \API\Services\FmodService([]);
$fmod->buildPackage(
    './modules/mon_module',
    './dist/mon_module-1.0.0.fmod',
    trim(file_get_contents('pki-test/fmod-secret.key')),
    'fronote-team',
    [base64_encode(file_get_contents('pki-test/test-intermediate.crt'))]
);
```

### Vérifier un `.fmod` (PHP)

```php
$pubKeyB64 = trim(file_get_contents('config/marketplace/roots/fronote-test-root.pub'));
$roots     = [FmodService::fingerprint($pubKeyB64) => $pubKeyB64];
$fmod      = new \API\Services\FmodService($roots);
$result    = $fmod->verifyPackage('./module.fmod', '3.2.4');
// $result['ok'], $result['errors'], $result['manifest'], $result['signature']
```

### Scripts historiques

| Script | Rôle |
|--------|------|
| `scripts/fmod_keygen.php <nom> [dir]` | Génère paire Ed25519 |
| `scripts/fmod_cert.php <subject.pub> <issuer.sk> <publisher_id> <jours> [out]` | Émet un certificat |
| `scripts/fmod_build.php <src> <out.fmod> <editor.sk> <publisher_id> <cert…>` | Build + signature |
| `scripts/fmod_verify.php <pkg.fmod> [root.pub…]` | Vérification offline |

---

## Module de référence hello_world

Module test officiel (v1.0.0), distribué pré-signé par l'Intermediate CA de test.

**Objectif** : valider l'intégralité du pipeline .fmod sans logique métier.

```
modules/hello_world/
├── module.json                              ← test_only: true, channel: test
├── hello_world.php                          ← Page admin : log auto + bouton vider
├── Services/HelloWorldService.php           ← log(), getRecentLogs(), clearLogs(), getStats()
├── Providers/HelloWorldServiceProvider.php  ← Enregistre 'hello_world' dans le container
├── Database/install.sql                     ← CREATE TABLE hello_world_log
└── lang/{fr,en}.json
```

**Activation** :

```bash
# .env
ALLOW_TEST_MODULES=true

php scripts/install-module.php ./hello_world-1.0.0.fmod --allow-test
```

**API** :

```php
$hw = app('hello_world');
$hw->log('page_view', ['user_id' => 42]);
$logs  = $hw->getRecentLogs(20);
$stats = $hw->getStats(); // ['total' => N, 'by_event' => [...]]
$hw->clearLogs();
```

---

## Tables de base de données

Définies dans `modules/marketplace/Database/install.sql`. Non scopées `etablissement_id` : la marketplace est globale à l'installation.

| Table | Rôle | Nouveautés v1.5.2 |
|-------|------|------------------|
| `marketplace_sources` | Registres configurés (URL + Root CA attendue) | `root_public_key BINARY(32)`, `updated_at ON UPDATE` |
| `marketplace_installed` | Provenance vérifiée (hash paquet + cert + horodatage) | `COLLATE ascii_bin` sur colonnes SHA-256 |
| `marketplace_installs` | Installations catalog (`installModule`/`installTheme`) | Table ajoutée |
| `marketplace_cache` | Cache JSON des catalogues (TTL via `expires_at`) | — |
| `marketplace_consents` | Permissions consenties par version | `granted_by_name VARCHAR(200)` |
| `marketplace_advisories_seen` | Avis de sécurité reçus (hook `isYanked`) | `acknowledged_by INT + FK` |
| `marketplace_revocations` | CRL locale (hook `isRevoked`) | `cert_fingerprint COLLATE ascii_bin`, `KEY idx_fingerprint` |

---

## Sécurité

| Propriété | Garantie |
|-----------|---------|
| Intégrité | SHA-256 par fichier, vérifié en flux (pas de TOCTOU) |
| Authenticité | Chaîne Ed25519 → Root CA embarquée |
| Non-répudiation | Ed25519 est déterministe |
| Révocation | CRL via `marketplace_revocations` |
| Extraction sûre | Refus `..` et chemins absolus ; commit atomique `.part` |
| Post-signature | `ModuleScanner` tourne après signature valide |

**Ce que la signature ne garantit pas** : qualité du code, logique malveillante subtile, conformité RGPD. `ModuleScanner` + consentement des permissions sont des couches complémentaires.

---

## Phases suivantes

- Registre HTTP : `/v1/modules`, `/v1/test-catalog`, CRL publiée en ligne.
- Binaire `fmod-pack` (Go) pour signing + verification sans PHP.
- Portail éditeur et console de modération.
- Bac à sable d'exécution lors de la modération.
- Modules payants.
