# Marketplace — Guide (v3.2.4)

Module `marketplace` v1.5.2 — installation et désinstallation de modules Fronote.

Deux chemins d'installation coexistent :

1. **Sideload `.fmod` (recommandé)** — téléversement d'un paquet local signé Ed25519, vérifié hors-ligne contre les Root CA embarquées. UI : page du module (`modules/marketplace/marketplace.php`). Seul chemin autorisé en production.
2. **Catalogue distant** — installation d'un module ou thème depuis un registre JSON HTTP. UI admin : `admin/modules/marketplace.php`. **Désactivé en production** (non signé) sauf `MARKETPLACE_ALLOW_UNSIGNED=true`.

> ⚠️ **Pas de migrations.** À l'activation, `ModuleSDK::provisionSql($key)` exécute uniquement le `Database/install.sql` du module (schéma final, `CREATE TABLE IF NOT EXISTS`). Il n'y a aucun système de migration incrémentale dans ce module.

---

## Table des matières

- [Les deux UI](#les-deux-ui)
- [Modèle de confiance (.fmod)](#modèle-de-confiance-fmod)
- [Format `.fmod`](#format-fmod)
- [Manifeste `publish`](#manifeste-publish)
- [Modules de test (test_only)](#modules-de-test-test_only)
- [Pipeline d'installation `.fmod`](#pipeline-dinstallation-fmod)
- [Consentement des permissions](#consentement-des-permissions)
- [Quarantaine](#quarantaine)
- [Installation depuis le catalogue distant](#installation-depuis-le-catalogue-distant)
- [Désinstallation et rollback](#désinstallation-et-rollback)
- [Infrastructure PKI](#infrastructure-pki)
- [CLI et scripts](#cli-et-scripts)
- [Module de référence hello_world](#module-de-référence-hello_world)
- [Tables de base de données](#tables-de-base-de-données)
- [Variables d'environnement](#variables-denvironnement)
- [Sécurité](#sécurité)
- [Phases suivantes](#phases-suivantes)

---

## Les deux UI

| Page | Fichier | Rôle | Méthodes service |
|------|---------|------|------------------|
| **Sideload `.fmod`** | `modules/marketplace/marketplace.php` | Téléverser un `.fmod`, écran de consentement, liste des installs signées | `installFromFmod`, `confirmInstall`, `getInstalled`, `isTestModulesAllowed` |
| **Catalogue distant** | `admin/modules/marketplace.php` | Parcourir/installer/désinstaller un module ou thème distant, rollback | `getCatalog`, `search`, `installModule`, `uninstallModule`, `rollback`, `checkUpdates` |

Les deux exigent le rôle `administrateur` (`requireRole('administrateur')`). Le service unique derrière les deux est `app('marketplace')` (`\API\Services\MarketplaceService`).

---

## Modèle de confiance (.fmod)

```
Root CA  ──(signe)──▶  Cert intermédiaire  ──(signe)──▶  Cert éditeur  ──(signe)──▶  MANIFEST.sha256
   ▲                                                                                          │
   │ clé publique (32 bytes Ed25519)                                                          │
   │ config/marketplace/roots/*.pub                                                           │
   └────────────── client vérifie la chaîne + chaque fichier ────────────────────────────────┘
```

- **Signature** : Ed25519 via `ext-sodium` (pas de dépendance Composer).
- **Intégrité** : SHA-256 par fichier, vérifié en flux pendant l'extraction (`FmodService::verifyAndExtract` — une seule ouverture du ZIP, pas de fenêtre TOCTOU entre `verify` et `extract`).
- **Aucune confiance réseau** : TLS requis, jamais suffisant. Vérification offline contre une Root CA embarquée.
- Root CA **production** hors-ligne (air-gapped). Root CA **test** distincte, distribuée avec les instances dev.
- Tant qu'aucun `*.pub` n'est présent sous `config/marketplace/roots/`, `installFromFmod()` refuse tout sideload (`no Root CA configured`).

---

## Format `.fmod`

Spec publique complète : [`fmod-format.md`](../fmod-format.md). Construit/vérifié par `\API\Services\FmodService`.

```
{module_key}-{version}.fmod   (ZIP)
├── MANIFEST.sha256      ← SHA-256 par fichier `<sha256>␣␣<chemin>` (trié par chemin, signé)
├── SIGNATURE.json       ← Signature Ed25519 détachée + chaîne de certificats
├── module.json          ← Métadonnées du module
└── <arborescence source>
```

`MANIFEST.sha256` et `SIGNATURE.json` ainsi que `.git`, `.gitignore`, `.DS_Store`, `node_modules`, `.idea`, `.vscode` sont exclus du manifeste (`FmodService::EXCLUDE_NAMES`).

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

La signature couvre `sha256(MANIFEST.sha256)` — le hash du fichier de hashes. La Root CA n'est **pas** dans la chaîne ; elle est lue depuis `config/marketplace/roots/*.pub`. Le certificat éditeur est à l'index 0, les intermédiaires ensuite.

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

`installFromFmod()` (via `FmodService::verifyAndExtract`) refuse l'installation si :

- La chaîne de certificats ne remonte pas à une Root CA reconnue, ou un cert de la chaîne est expiré
- Le fingerprint du cert éditeur est dans `marketplace_revocations` (hook `isRevoked`)
- La signature Ed25519 ne valide pas `sha256(MANIFEST.sha256)`, ou le hash réclamé ne correspond pas
- Un fichier extrait ne correspond pas à son SHA-256 dans le manifeste, ou un fichier déclaré manque, ou un fichier hors manifeste est présent
- `publisher_id` n'est pas identique dans le manifeste **ET** la signature **ET** le certificat éditeur (vérif stricte)
- La version du cœur ne satisfait pas `min_core`/`max_core` (`FmodService::semverSatisfies` : `>=`, `<=`, `>`, `<`, `=`, `^`, `~`, wildcards `X.Y.*`, conjonctions séparées par espace)
- La version est yankée (`marketplace_advisories_seen`, sévérité `high`/`critical` — hook `isYanked`)

La contrainte du semver du cœur est lue depuis le manifeste (`publish.min_core`/`max_core`). La version courante vient de `version.json` (`3.2.4`).

---

## Modules de test (test_only)

Les modules test valident le pipeline `.fmod` sans logique métier.

**Activation sur une instance dev/staging** :

```env
# .env
ALLOW_TEST_MODULES=true
```

`MarketplaceService::isTestModulesAllowed()` accepte `true` ou `1`. Sans ce flag, un module dont `module.json` porte `test_only: true` est refusé dès le pipeline avec le message *« Ce module est marqué test_only … ALLOW_TEST_MODULES=true … »*.

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

**Catalogue test** : `GET /API/endpoints/test_catalog.php` (requiert auth + `ALLOW_TEST_MODULES=true`, sinon HTTP 403). Source : registre distant `MARKETPLACE_TEST_REGISTRY_URL` si défini, sinon énumération locale de `config/marketplace/test-catalog/*.json` + inclusion automatique de `modules/hello_world/module.json` s'il existe.

---

## Pipeline d'installation `.fmod`

`MarketplaceService::installFromFmod(string $fmodPath): array`

Verrou global `storage/tmp/marketplace.install.lock` (`flock LOCK_EX|LOCK_NB`) : deux installations marketplace concurrentes sont rejetées (*« Une autre installation est déjà en cours »*). Le verrou est libéré en `finally`.

| # | Étape | Mécanisme | Échec → |
|---|-------|-----------|---------|
| 1 | Upload : fichier reçu, extension `.fmod`, ≤ **50 Mo** | Vérifié dans `modules/marketplace/marketplace.php` avant l'appel service (limite codée en dur, non configurable) | rejet upload |
| 2 | Au moins une Root CA chargée | `loadRootKeys()` lit `config/marketplace/roots/*.pub` | rejet (`no Root CA configured`) |
| 3 | `MANIFEST.sha256` + `SIGNATURE.json` présents, `alg = Ed25519` | `FmodService::verifyInternal` | rejet |
| 4 | Chaîne de certificats → Root CA configurée, non expirée | `verifyCertChain()` | rejet signature |
| 5 | Fingerprint du cert éditeur absent de `marketplace_revocations` | hook `isRevoked` | rejet |
| 6 | Signature Ed25519 valide sur `sha256(MANIFEST)` | `sodium_crypto_sign_verify_detached` | rejet |
| 7 | SHA-256 de **chaque** fichier = MANIFEST ; extraction atomique (`.part` → rename après digest OK) ; noms d'archive sûrs (pas de `..`, chemin absolu, drive letter, backslash, NUL) | streaming dans `verifyAndExtract` | rejet + nettoyage staging |
| 8 | `publisher_id` cohérent (manifeste ∩ signature ∩ certificat) + compat `min_core`/`max_core` + non yanké | `verifyInternal` | rejet |
| 9 | `test_only` vs `ALLOW_TEST_MODULES` | `isTestModulesAllowed()` | rejet |
| 10 | `ModuleScanner` statique sur le code extrait (défense en profondeur) | `scanDirectory()` | **quarantaine** si violations |
| 11 | Consentement admin des permissions requises | si non vide → retour `pending_consent` | suspend |
| 12 | Bascule atomique : backup live (`storage/backups/modules/<key>_<date>`) → `rename(staging, modules/<key>/)` | `deployFromStaging()` | rejet |
| 13 | `ModuleSDK::syncModule` + `provisionSql($key)` (= exécution de `install.sql`) | `deployFromStaging()` | erreur loguée, install non bloquée |
| 14 | Insertion dans `marketplace_installed` (hash paquet + manifeste + fingerprint cert + `signature_verified_at`) | `deployFromStaging()` | erreur loguée |

> **Note** : la vérification de signature/intégrité (étapes 3-8) et l'extraction (étape 7) se font dans une **seule ouverture du ZIP** (`verifyAndExtract`). C'est ce qui élimine la fenêtre TOCTOU de l'ancienne approche `verify` puis `extract`.

---

## Consentement des permissions

Si le manifeste déclare des permissions (`permissions_requested` ou `publish.required_permissions`), `installFromFmod()` **suspend** l'installation et retourne :

```php
[
  'success'         => false,
  'pending_consent' => true,
  'staging'         => '/storage/tmp/_staging_abc123…',  // staging conservé
  'manifest'        => [...],
  'permissions'     => ['db_read', 'db_write'],
  'sig_data'        => [...],                            // signature vérifiée
  'fmod_path'       => '/storage/tmp/sideload_xyz.fmod',
]
```

La page `modules/marketplace/marketplace.php` stocke ce contexte en session (`$_SESSION['marketplace_pending']`, TTL 600 s) et affiche un écran où l'admin coche **chaque** permission. À la validation (`action=confirm_install`), toutes les permissions manquantes bloquent ; sinon :

1. Insertion dans `marketplace_consents` (`granted_by` = ID admin, `granted_by_name` = `prenom nom` dénormalisé pour traçabilité RGPD après suppression de l'admin).
2. `MarketplaceService::confirmInstall($staging, $manifest, $fmodPath, $sigData)` → `deployFromStaging()` (étapes 12-14).

```php
// Finalisation après consentement
$marketplace->confirmInstall($staging, $manifest, $fmodPath, $sigData);
```

**Permissions reconnues** (libellés dans `modules/marketplace/marketplace.php`, `$PERM_LABELS`) :

| Permission | Libellé UI |
|-----------|-----------|
| `db_read` | Lecture BDD |
| `db_write` | Écriture BDD |
| `filesystem` | Accès fichiers |
| `network` | Appels réseau |
| `email` | Envoi emails |

La permission `network` a aussi un effet sur `ModuleScanner` : sans elle, les fonctions réseau (`curl_exec`, `fsockopen`, …) sont des violations bloquantes.

---

## Quarantaine

`\API\Services\QuarantineService` (`API/Services/QuarantineService.php`). Quand `ModuleScanner` détecte une **violation** (pas un simple warning), le staging est déplacé vers `storage/quarantine/<key>/` au lieu d'être installé, et un rapport `_quarantine_report.json` y est écrit (horodatage + violations/warnings).

| Méthode | Effet |
|---------|-------|
| `quarantine($key, $sourcePath, $scanResults)` | Déplace le dossier vers `storage/quarantine/<key>/` + écrit le rapport |
| `approve($key)` | Déplace `storage/quarantine/<key>/` → `modules/<key>/` (supprime le rapport) |
| `reject($key)` | Supprime `storage/quarantine/<key>/` |
| `getAll()` | Liste les modules en quarantaine (clé, nom, version, auteur, violations, warnings) |

Un module en quarantaine n'est **pas** routable tant qu'un admin ne l'a pas approuvé. Le retour d'install signale alors `quarantined: true` avec la liste des `violations`.

### Ce que `ModuleScanner` détecte

`\API\Security\ModuleScanner` (`API/Security/ModuleScanner.php`) fait une analyse statique par `token_get_all()` (pas de regex fragile). Il tourne **après** la vérification de signature.

- **Bloquant** (`violations` → quarantaine) : `eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`, `pcntl_exec`, `dl`, `putenv`, `apache_setenv`, indirection (`call_user_func[_array]`, `create_function`, `assert`, `forward_static_call[_array]`), opérateur backtick, variable-variable `$$var`, appel de fonction variable `$fn(...)`, `base64_decode` près d'`eval` (obfuscation), `preg_replace` avec modificateur `/e`.
- **Réseau, bloquant sauf permission `network`** : `curl_exec`, `curl_multi_exec`, `fsockopen`, `pfsockopen`, `stream_socket_client`.
- **Avertissement** (`warnings`, non bloquant) : `file_get_contents`, `file_put_contents`, `fopen`, `fwrite`, `unlink`, `rmdir`, `rename`, `chmod`, `chown`, `chgrp`, `symlink`, `link`, `mkdir`, `include`/`require` à chemin variable (LFI potentielle).

---

## Installation depuis le catalogue distant

UI : `admin/modules/marketplace.php`. Service : `installModule()` / `installTheme()`.

Source : registre JSON distant configurable via `MARKETPLACE_REGISTRY_URL` (défaut `https://raw.githubusercontent.com/fronote/marketplace/main/registry.json`). Le catalogue est mis en cache 1 h (`app('cache')`).

```php
$catalog = $marketplace->getCatalog('module');     // ou 'theme'
$results = $marketplace->search('cantine');
$item    = $marketplace->getItem('cantine');
```

### Garde-fou production

Ce flux **ne vérifie pas de signature Ed25519** (contrairement au sideload `.fmod`). En production (`APP_ENV=production|prod`), `installModule()` refuse :

> *« Installation depuis le registre distant désactivée en production : utilisez un paquet .fmod signé (sideload), ou définissez MARKETPLACE_ALLOW_UNSIGNED=true en connaissance de cause. »*

`MARKETPLACE_ALLOW_UNSIGNED=true|1|yes|on` lève le garde-fou (échappatoire opérateur, à éviter).

### Pré-vérification (`preflight`)

`installModule()` appelle d'abord `preflight($key)`, qui contrôle **sans rien télécharger** : module non déjà installé, compat version Fronote (`fronote_min`/`fronote_max`), version PHP (`requires_php`), dépendances présentes (`modules/<dep>/module.json`). Retour structuré `['ok', 'checks', 'blockers']`, réutilisable pour afficher un rapport.

### Étapes de `installModule()`

1. `preflight()` OK.
2. Téléchargement du ZIP (`download_url`, TLS `verify_peer`).
3. **Vérif d'intégrité SHA-256** si `sha256` présent dans l'item du catalogue (`hash_equals`) — sinon ignorée.
4. Extraction en staging (`storage/tmp/_staging_<key>_<rand>`), jamais directement dans le dossier live.
5. `module.json` présent et valide ; revalidation compat depuis le manifeste.
6. `ModuleScanner` ; violations → quarantaine.
7. Bascule atomique (backup du live précédent) → `modules/<key>/`.
8. `ModuleSDK::syncModule($manifest)` puis enregistrement dans `marketplace_installs`.

> ⚠️ Le chemin catalogue n'appelle pas `provisionSql()` directement dans `installModule()` ; le schéma est provisionné à l'activation du module par `ModuleSDK`. Le sideload `.fmod`, lui, appelle explicitement `provisionSql($key)` dans `deployFromStaging()`.

### Thèmes

`installTheme($key)` télécharge un ZIP, copie le premier `*.css` vers `assets/css/theme-<key>.css` (+ preview éventuelle), insère dans la table `themes` (`actif = 0`) et `marketplace_installs`. `uninstallTheme($key)` supprime les fichiers et les lignes — refusé pour les thèmes intégrés `classic` et `glass`.

### Mises à jour

`checkUpdates()` compare la version de chaque item installé à celle du catalogue (`version_compare`) et liste les mises à jour disponibles. La page admin les affiche en bandeau (purement informatif : pas de bouton « tout mettre à jour »).

---

## Désinstallation et rollback

Disponibles depuis l'UI admin catalogue (boutons par carte) :

| Action | Méthode | Comportement |
|--------|---------|--------------|
| Désinstaller | `uninstallModule($key)` | Refus si `app('modules')->isCore($key)` ; désactive, déplace `modules/<key>/` → `storage/backups/modules/<key>_<date>` (sauvegarde restaurable), supprime la ligne `marketplace_installs`. |
| Restaurer | `rollback($key)` | Restaure la sauvegarde horodatée la plus récente (`storage/backups/modules/<key>_<date>`), re-`syncModule`, ré-enregistre l'install. Réversible si la restauration échoue. |

Toutes les actions admin sont auditées : `logAudit('marketplace.install' | '.uninstall' | '.rollback', …)` côté `admin/modules/marketplace.php`, et `app('audit')->log('marketplace.install', …)` côté sideload.

---

## Infrastructure PKI

### Hiérarchie

| Niveau | Rôle |
|--------|------|
| Root CA production | Offline, air-gapped. Clé publique sous `config/marketplace/roots/*.pub`. Clé privée jamais en ligne. |
| Root CA test | Distincte de la prod, distribuée avec les instances dev (`fronote-test-root.pub`). |
| Intermediate CA | Signe les certificats éditeurs. |
| Certificat éditeur | Clé de l'éditeur, présente dans `SIGNATURE.json` (index 0 de `certificate_chain`). |

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

Payload signé : lignes `key=value` triées alphabétiquement (`SORT_STRING`), jointes par `\n`, sans `signature_b64`. Défini par `FmodService::canonicalCertBytes()` (évite la non-déterminisme de `json_encode`).

### Clé publique Root CA (`.pub`)

```
# config/marketplace/roots/fronote-root.pub
<base64-32-bytes-Ed25519-pubkey>
```

Une ligne base64, 32 bytes une fois décodés. Fichier public, destiné à être committé. Le répertoire est vide par défaut dans le dépôt : tant qu'il l'est, tout sideload est refusé (cf. `config/marketplace/roots/README.md`).

---

## CLI et scripts

### Générer l'infrastructure PKI de test

```bash
bash scripts/pki/generate-test-ca.sh [output_dir]   # défaut: ./pki-test
# Génère Root CA test, Intermediate CA, cert éditeur fronote-team, keypair libsodium.
# Copie fronote-test-root.pub dans config/marketplace/roots/ automatiquement.
# Prérequis : OpenSSL 3.x + PHP 8.0+ avec ext-sodium.
```

### Installer un module en CLI

```bash
php scripts/install-module.php ./module-1.0.0.fmod [--allow-test] [--dry-run]
# --allow-test  → équivaut à ALLOW_TEST_MODULES=true (autorise les modules test_only)
# --dry-run     → vérifie signature + intégrité sans installer
```

Le script charge les Root CA depuis `config/marketplace/roots/*.pub` et délègue à `MarketplaceService::installFromFmod()` (consentement demandé en interactif si nécessaire).

### Construire un `.fmod` (PHP)

```php
$fmod = new \API\Services\FmodService([]);  // aucune Root CA requise pour signer
$fmod->buildPackage(
    './modules/mon_module',
    './dist/mon_module-1.0.0.fmod',
    trim(file_get_contents('pki-test/fmod-secret.key')),   // clé éditeur Ed25519 base64
    'fronote-team',
    [base64_encode(file_get_contents('pki-test/publisher.crt'))]  // chaîne éditeur→intermédiaire
);
```

### Vérifier un `.fmod` (PHP)

```php
$pubKeyB64 = trim(file_get_contents('config/marketplace/roots/fronote-test-root.pub'));
$roots     = [\API\Services\FmodService::fingerprint($pubKeyB64) => $pubKeyB64];
$fmod      = new \API\Services\FmodService($roots);
$result    = $fmod->verifyPackage('./module.fmod', '3.2.4');
// $result['ok'], $result['errors'], $result['manifest'], $result['signature']
```

### Scripts bas niveau

| Script | Rôle |
|--------|------|
| `scripts/fmod_keygen.php <nom> [out_dir]` | Génère une paire Ed25519 (`.sk`, `.pub`, `.fp`) ; défaut `config/marketplace/keys/` |
| `scripts/fmod_cert.php <subject.pub> <issuer.sk> <publisher_id> <jours> [out]` | Émet un certificat Fronote signé |
| `scripts/fmod_build.php <src> <out.fmod> <editor.sk> <publisher_id> <cert1> [cert2…]` | Build + signature (cert éditeur en premier) |
| `scripts/fmod_verify.php <pkg.fmod> [root.pub…]` | Vérification offline |

---

## Module de référence hello_world

Module test officiel (`modules/hello_world/`, v1.0.0), `test_only: true`, `channel: test`.

**Objectif** : valider l'intégralité du pipeline `.fmod` sans logique métier.

```
modules/hello_world/
├── module.json                              ← test_only: true, channel: test
├── hello_world.php                          ← Page admin
├── Services/HelloWorldService.php
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
$stats = $hw->getStats();
$hw->clearLogs();
```

---

## Tables de base de données

Définies dans `modules/marketplace/Database/install.sql`, provisionnées par `ModuleSDK::provisionSql()` à l'installation/activation. **Non scopées `etablissement_id`** : la marketplace est globale à l'installation. Aucune donnée nominative (hors `granted_by`/`granted_by_name` dans les consentements).

| Table | Rôle |
|-------|------|
| `marketplace_sources` | Registres configurés (nom, URL, `root_public_key BINARY(32)`, canal) |
| `marketplace_installed` | Provenance des installs **signées** (`.fmod`) : `package_sha256`, `manifest_sha256`, `cert_fingerprint` (tous `CHAR(64) COLLATE ascii_bin`), `signature_verified_at`, `channel` ∈ `stable|beta|sideload` |
| `marketplace_installs` | Installs **catalogue** (`installModule`/`installTheme`) : `item_key`, `item_type` ∈ `module|theme`, `version`, `author` |
| `marketplace_cache` | Cache JSON des catalogues distants (TTL via `expires_at`) |
| `marketplace_consents` | Permissions consenties par version : `permissions_granted JSON`, `granted_by` (FK `administrateurs`, `ON DELETE SET NULL`), `granted_by_name` |
| `marketplace_advisories_seen` | Avis de sécurité reçus (hook `isYanked` ; sévérité `low|medium|high|critical`) |
| `marketplace_revocations` | CRL locale (hook `isRevoked` ; `cert_fingerprint COLLATE ascii_bin`) |

`getInstalled()` lit **les deux** tables d'installs (`marketplace_installs` et `marketplace_installed`) indépendamment, normalisées sur `item_key`/`item_type`/`version`, pour qu'une table absente n'en masque pas l'autre.

---

## Variables d'environnement

| Variable | Défaut | Effet |
|----------|--------|-------|
| `APP_ENV` | `production` | `production`/`prod` → bloque l'install catalogue non signée |
| `MARKETPLACE_ALLOW_UNSIGNED` | (non défini) | `1|true|yes|on` → autorise l'install catalogue en production |
| `MARKETPLACE_REGISTRY_URL` | `…/fronote/marketplace/main/registry.json` | URL du registre catalogue |
| `MARKETPLACE_TEST_REGISTRY_URL` | (non défini) | Registre du catalogue de test (`test_catalog.php`) |
| `ALLOW_TEST_MODULES` | (non défini) | `true`/`1` → autorise les modules `test_only` |

> Ces variables ne figurent pas dans `.env.example` ; ce sont des réglages optionnels. La limite de taille du `.fmod` (50 Mo) est **codée en dur** dans `modules/marketplace/marketplace.php` et n'est pas configurable.

---

## Sécurité

| Propriété | Garantie |
|-----------|---------|
| Intégrité | SHA-256 par fichier, vérifié en flux pendant l'extraction (pas de TOCTOU) |
| Authenticité | Chaîne Ed25519 → Root CA embarquée, vérifiée hors-ligne |
| Révocation | CRL via `marketplace_revocations` (hook `isRevoked`) + yank via `marketplace_advisories_seen` |
| Extraction sûre | Refus de `..`, chemins absolus, drive letters, backslashes, NUL ; commit atomique `.part` → rename après digest |
| Concurrence | Verrou `flock` global pendant l'install sideload |
| Post-signature | `ModuleScanner` (analyse statique) tourne après une signature valide ; violations → quarantaine |
| Catalogue distant | Non signé → bloqué en production sauf `MARKETPLACE_ALLOW_UNSIGNED` ; SHA-256 vérifié si fourni dans l'item |

**Ce que la signature ne garantit pas** : la qualité du code, une logique malveillante subtile, la conformité RGPD. `ModuleScanner` + le consentement des permissions sont des couches complémentaires.

---

## Phases suivantes

- Registre HTTP : `/v1/modules`, `/v1/test-catalog`, CRL publiée en ligne.
- Binaire `fmod-pack` (Go) pour signing + verification sans PHP.
- Portail éditeur et console de modération.
- Bac à sable d'exécution lors de la modération.
- Modules payants.
