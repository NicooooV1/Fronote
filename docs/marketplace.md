# Marketplace — Guide implémenté (phase 1 « Hub »)

> **Statut.** Cette page décrit *ce qui est livré*, pas l'intégralité du CDC v3.0. La
> phase 1 fournit la **chaîne de confiance côté client** (signature Ed25519 + paquet
> `.fmod` + sideload vérifié). Le registre HTTP central, le portail éditeur, la console
> de modération et le bac à sable d'exécution sont des phases ultérieures.

## Modèle de confiance

```
Root CA  ──(signe)──▶  Cert intermédiaire (registre)  ──(signe)──▶  Cert éditeur  ──(signe)──▶  MANIFEST.sha256
   ▲                                                                                                   │
   │ clé publique embarquée                                                                            │
   │ dans config/marketplace/roots/*.pub                                                               │
   └────────────── le client vérifie la chaîne et chaque fichier ─────────────────────────────────────┘
```

- Algorithme de signature : **Ed25519** (`ext-sodium`, pas de dépendance Composer).
- Algorithme d'intégrité : **SHA-256** par fichier (déclaré dans `MANIFEST.sha256`),
  vérifié en flux pendant l'extraction (pas de TOCTOU entre verify et extract).
- Canonicalisation des certificats : payload signé = lignes `key=value` triées
  lexicographiquement et jointes par `\n`. Format stable cross-runtime, défini par
  `FmodService::canonicalCertBytes()`. **Ne pas** signer du JSON (escapes UTF-8 et
  ordre des clés non garantis cross-implémentations).
- Aucune confiance dans le réseau : TLS est requis mais jamais suffisant. La signature
  est validée hors-ligne contre la Root CA embarquée.

## Format `.fmod`

Une archive ZIP qui contient :

| Fichier | Rôle |
|---|---|
| `module.json` + arborescence | Code et manifeste du module (mêmes conventions que [module-sdk.md](module-sdk.md)). |
| `MANIFEST.sha256` | Liste de tous les fichiers du paquet : `<sha256_hex>  <chemin>` (un par ligne, triés par chemin). |
| `SIGNATURE.json` | Signature détachée Ed25519 du `MANIFEST.sha256` + chaîne de certificats de l'éditeur. |

Exemple `SIGNATURE.json` :

```json
{
  "alg": "Ed25519",
  "publisher_id": "fronote-team",
  "manifest_sha256": "9f2c…",
  "signature": "base64(Ed25519(sha256(MANIFEST.sha256)))",
  "certificate_chain": [
    "base64(cert éditeur)",
    "base64(cert intermédiaire)"
  ],
  "signed_at": "2026-05-29T10:00:00Z"
}
```

La Root CA **n'est pas** dans la chaîne ; elle est lue côté client depuis
`config/marketplace/roots/*.pub`.

## Manifeste — bloc `publish`

```jsonc
{
  "key": "cantine",
  "version": "2.3.1",
  "category": "logistique",
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

- la chaîne de certificats ne remonte pas à une Root CA reconnue
- un certificat de la chaîne est révoqué (`marketplace_revocations`)
- la signature Ed25519 ne valide pas `sha256(MANIFEST)`
- un fichier extrait ne correspond pas à son SHA-256 dans le manifeste
- `publish.publisher_id` ≠ identité du certificat éditeur (anti-usurpation)
- la version du cœur ne satisfait pas `min_core` / `max_core`
- la version est yankée (`marketplace_advisories_seen` haute/critique)

## CLI (scripts/)

| Script | Rôle |
|---|---|
| `fmod_keygen.php <nom> [dir]` | Génère une paire Ed25519 (clé privée à garder offline). |
| `fmod_cert.php <subject.pub> <issuer.sk> <publisher_id> <jours> [out]` | Émet un certificat éditeur signé par un émetteur (Root ou intermédiaire). |
| `fmod_build.php <src> <out.fmod> <editor.sk> <publisher_id> <cert…>` | Construit le `.fmod` (calcule `MANIFEST.sha256`, signe, embarque la chaîne). |
| `fmod_verify.php <pkg.fmod> [root.pub…]` | Vérifie un paquet hors-ligne (utile en CI et avant publication). |

Exemple complet (cérémonie de clés — `*.sk` jamais committés) :

```bash
# 1. Root CA (sur poste air-gappé)
php scripts/fmod_keygen.php fronote-root /secure/offline/

# 2. Copier la .pub dans le repo
cp /secure/offline/fronote-root.pub config/marketplace/roots/

# 3. Clé intermédiaire (online, dans config/marketplace/keys/, ignorée par git)
php scripts/fmod_keygen.php registry-intermediate

# 4. Cert intermédiaire signé par la Root
php scripts/fmod_cert.php \
    config/marketplace/keys/registry-intermediate.pub \
    /secure/offline/fronote-root.sk \
    fronote-intermediate 730 \
    config/marketplace/certs/registry-intermediate.cert

# 5. Clé éditeur + cert signé par l'intermédiaire
php scripts/fmod_keygen.php fronote-team
php scripts/fmod_cert.php \
    config/marketplace/keys/fronote-team.pub \
    config/marketplace/keys/registry-intermediate.sk \
    fronote-team 365 \
    config/marketplace/certs/fronote-team.cert

# 6. Build d'un .fmod
php scripts/fmod_build.php modules/cantine dist/cantine-2.3.1.fmod \
    config/marketplace/keys/fronote-team.sk fronote-team \
    config/marketplace/certs/fronote-team.cert \
    config/marketplace/certs/registry-intermediate.cert

# 7. Vérification hors-ligne
php scripts/fmod_verify.php dist/cantine-2.3.1.fmod
```

## Installation côté instance

- **UI** : Administration → Marketplace (`modules/marketplace/marketplace.php`). Le sideload
  est un formulaire d'upload protégé par CSRF + `requireRole('administrateur')`.
- **API** : `app('marketplace')->installFromFmod($path)` —
  - `FmodService::verifyPackage()` (chaîne + signature + intégrité + compat cœur + yank/CRL)
  - extraction en staging (refus des entrées avec `..` ou chemin absolu)
  - `ModuleScanner` statique → `QuarantineService` en cas de violation
  - bascule atomique (backup du dossier live) → `ModuleSDK::syncModule` + `provisionSql`
  - trace dans `marketplace_installed` : hash paquet + hash manifest + fingerprint cert + horodatage de vérification

## Tables ajoutées (`modules/marketplace/Database/install.sql`)

| Table | Rôle |
|---|---|
| `marketplace_sources` | Registres configurés (URL + clé racine attendue). Vide = sideload seulement. |
| `marketplace_installed` | Provenance vérifiée de chaque module installé. |
| `marketplace_cache` | Cache JSON des catalogues (TTL via `expires_at`). |
| `marketplace_consents` | Permissions consenties par version. |
| `marketplace_advisories_seen` | Avis de sécurité reçus (alimentent le hook `isYanked`). |
| `marketplace_revocations` | CRL locale (alimente le hook `isRevoked`). |

Aucune de ces tables n'est scopée par `etablissement_id` — la marketplace est globale à
l'installation Fronote, pas à un établissement.

## Sécurité

- **Single source of trust** : les `*.pub` de `config/marketplace/roots/`. Aucun fallback
  réseau, aucun TOFU.
- **Privates jamais committées** : `config/marketplace/keys/` est dans `.gitignore`, ainsi
  que `*.sk` et `dist/*.fmod`.
- **`ext-sodium` obligatoire** : requirement explicite dans `composer.json`.
- **Extraction** : refus de toute entrée ZIP contenant `..` ou démarrant par `/` ou `\`.
- **Signature ≠ innocuité** : `ModuleScanner` reste exécuté après vérification de signature ;
  un module signé peut être quarantainé s'il déclenche le scanner.
- **CI** : `.github/workflows/validate.yml` lance `tests/fmod_selftest.php` qui couvre
  happy-path, tamper-detection, et rejet d'une Root CA non reconnue.

## Phases suivantes (hors session)

- Registre HTTP : API `/v1/modules`, stockage objet, CRL signée publiée, télémétrie anonyme.
- Portail éditeur et console de modération.
- Bac à sable d'exécution (conteneur isolé) en amont de la modération.
- Modules payants (phase 4, voir CDC §16).
