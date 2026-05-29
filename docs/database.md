# Database — Conventions et Structure

## Connexion

```php
// Via le container (recommandé)
$pdo = app('db')->getConnection();

// Via le helper global
$pdo = getPDO();
```

## Conventions de nommage

| Élément | Convention | Exemple |
|---|---|---|
| Tables | snake_case, pluriel | `notes`, `user_settings`, `audit_log` |
| Colonnes | snake_case | `user_id`, `created_at`, `mot_de_passe` |
| Clé primaire | `id` (INT AUTO_INCREMENT) | `id` |
| Clés étrangères | `{table_singulier}_id` | `eleve_id`, `classe_id` |
| Timestamps | `created_at`, `updated_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` |
| Booléens | Préfixe `is_` ou `has_` ou descriptif | `is_active`, `traite`, `lu` |
| Index | `idx_{description}` | `idx_user_type`, `idx_created_at` |
| Foreign keys | `fk_{table}_{ref}` | `fk_notes_eleve` |

## Charset

Toujours UTF-8 MB4 :

```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## Requêtes préparées

**Obligatoire**. Ne jamais interpoler de variables dans le SQL :

```php
// BON
$stmt = $pdo->prepare("SELECT * FROM notes WHERE eleve_id = ? AND trimestre = ?");
$stmt->execute([$eleveId, $trimestre]);

// MAUVAIS — injection SQL possible
$stmt = $pdo->query("SELECT * FROM notes WHERE eleve_id = $eleveId");
```

## Tables principales

### Utilisateurs

| Table | Description |
|---|---|
| `administrateurs` | Comptes administrateur |
| `professeurs` | Comptes enseignant |
| `eleves` | Comptes élève |
| `parents` | Comptes parent |
| `vie_scolaire` | Comptes CPE / AED |
| `user_settings` | Préférences utilisateur (thème, langue, notifications) |

### Modules

| Table | Description |
|---|---|
| `modules_config` | Configuration des modules (`enabled`, catégorie, `route_path`, `roles_autorises`, `is_core`, types d'établissement) |
| `module_permissions` | Permissions RBAC par module et rôle (lignes `module_key` × `role`, colonnes `can_*`) |
| `module_migrations` | Suivi des migrations SQL par module (statut, checksum, durée, déclencheur) |
| `module_settings_schema` | Schéma des réglages déclarés par les modules |
| `dashboard_widgets` | Définitions de widgets |
| `user_dashboard_config` | Layout de widgets par utilisateur |

### Sécurité

| Table | Description |
|---|---|
| `audit_log` | Journal d'audit avec sévérité et contexte HTTP |
| `api_rate_limits` | Compteurs de rate limiting |
| `api_tokens` | Tokens d'authentification API (hashés) |
| `session_security` | Métadonnées de session pour détection d'anomalies |

### Établissement

| Table | Description |
|---|---|
| `etablissement_info` | Informations de l'établissement (nom, type, locale) |
| `feature_flags` | Feature flags par type d'établissement |

## Schéma modulaire

Le schéma est en deux couches :

- **Socle** : `pronote.sql` (tables core — utilisateurs, classes, matières, périodes, `etablissements`, `modules_config`, sécurité…). À modifier directement pour le core.
- **Par module** : `modules/<m>/Database/install.sql` (idempotent, `CREATE TABLE IF NOT EXISTS`), exécuté par `ModuleSDK::provisionSql()` à l'installation **et** à chaque activation. FK désactivées pendant l'exécution (références croisées inter-modules).

Pour ajouter une table à un module :

1. Ajoutez le `CREATE TABLE IF NOT EXISTS` dans `modules/<m>/Database/install.sql` (chemin déclarable via `module.json` → `database.install`, défaut `Database/install.sql`).
2. Incluez la colonne `etablissement_id` + un index/FK si la table porte des données scopées par établissement.
3. Re-synchronisez (Admin → Modules) ou réinstallez : le SDK provisionne le SQL.
4. Pour une évolution incrémentale d'une base déjà en service, ajoutez plutôt un fichier de **migration** déclaré dans `module.json` → `migrations[]` (exécuté une fois, tracé dans `module_migrations`).
5. Bumpez `version.json` à tout changement de schéma.

> Multi-établissement : les services filtrent leurs requêtes par `\API\Core\EstablishmentContext::id()`. Toute nouvelle table métier doit porter `etablissement_id` et les requêtes globales/listes doivent le filtrer.

## Bonnes pratiques

1. **Toujours utiliser `IF NOT EXISTS`** pour les `CREATE TABLE` et `ADD COLUMN IF NOT EXISTS` pour les `ALTER TABLE`
2. **Indexer les colonnes** utilisées dans les `WHERE`, `JOIN`, et `ORDER BY`
3. **Éviter les requêtes N+1** : utilisez des `JOIN` ou des requêtes batch
4. **Limiter les résultats** : toujours utiliser `LIMIT` pour les listes
5. **Transactions** pour les opérations multi-tables :

```php
$pdo->beginTransaction();
try {
    // Opérations...
    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```
