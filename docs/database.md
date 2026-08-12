# Base de données — Conventions, schéma déclaratif et scoping

Fronote n'utilise **pas** de framework ORM. Le schéma est **déclaratif** : des fichiers
`.sql` décrivent l'état désiré de la base, et un service de réconciliation idempotent
(`SchemaSyncService`) rend la base conforme à ces fichiers **de façon additive** lors des
mises à jour (`CREATE TABLE` + `ADD COLUMN` uniquement — jamais de `DROP`, ni de changement de
type, ni d'index/FK sur une table existante). Pour ces transformations hors périmètre additif,
un système de **migrations de données versionnées** subsiste : fichier
`database/migrations/<horodatage>_<nom>.php` (objet `up(\PDO)/down(\PDO)`, journalisé dans la
table `schema_migrations`), exécuté par `MigrationRunner` juste après `SchemaSyncService`.

L'ancien système de migrations **par module** n'existe **plus** (supprimé le 2026-06-17 : plus
de `ModuleSDK::migrate`, plus de tables `module_migrations`/`core_migrations`, plus de dossier
`modules/*/Database/migrations/`, plus de clé `module.json["migrations"]`, plus de `CoreMigrator`
ni de `scripts/migrate.php`). `MigrationRunner` n'a d'ailleurs **pas de wrapper CLI** : il tourne
via le bouton de mise à jour. Guide de référence : [`docs/UPDATING.md`](UPDATING.md).

Moteur : MySQL 8.0+ / MariaDB 10.3+, PHP ≥ 8.0 (PDO). Tout en `utf8mb4`.

---

## Connexion

```php
// Via le container DI (recommandé)
$pdo = app('db')->getConnection();

// Via le helper global (Bridge legacy)
$pdo = getPDO();
```

`app('db')` est un singleton enregistré dans `API/bootstrap.php`. Les deux renvoient
la même connexion `PDO` (mode exception, fetch assoc).

---

## Schéma déclaratif : deux couches de `.sql`

Le schéma désiré est l'**union** de deux sources, toutes deux importées telles quelles
à l'installation initiale :

| Couche | Fichier | Contenu |
|---|---|---|
| **Cœur** | `pronote.sql` (racine, ~3 600 lignes) | Tables socle : `etablissements`, `super_admins`, utilisateurs (`administrateurs`, `eleves`, `professeurs`, `parents`, `vie_scolaire`), référentiels (`classes`, `matieres`, `periodes`), liaisons (`parent_eleve`, `professeur_classes`), sécurité, `modules_config`, feature flags, RGPD, et de nombreuses tables métier « filet ». |
| **Par module** | `modules/<clé>/Database/install.sql` | Tables propres au module. ~50 modules sur 61 en possèdent un (les autres réutilisent des tables déjà présentes dans `pronote.sql`). |

Chaque `install.sql` est **idempotent** : `CREATE TABLE IF NOT EXISTS …`, schéma final
complet (et non incrémental). Il est exécuté par `ModuleSDK::provisionSql($key)` **à
l'activation du module** (et réexécutable sans dommage : les erreurs 1050 « table
existe », 1060 « colonne dupliquée » et 1061 « index dupliqué » sont ignorées ; les FK sont désactivées le
temps de l'exécution, car les modules se référencent mutuellement dans un ordre
d'activation arbitraire).

> Le chemin de l'`install.sql` est déclarable dans `module.json` → `database.install`
> (défaut : `Database/install.sql`). Il n'existe **plus** de clé `migrations` dans
> `module.json`.

---

## Mise à jour : réconciliation déclarative + migrations versionnées

La mise à jour de l'application se fait par **un seul bouton** (`admin/systeme/update.php`),
qui appelle `app('updates')->applyUpdate()` (`API\Services\UpdateService`). Le flux,
synchrone, est (voir aussi [`docs/UPDATING.md`](UPDATING.md)) :

1. **Garde-fous** : branche servie = `GITHUB_BRANCH`, arbre de travail propre, `HEAD` git
   lisible — sinon refus **avant** toute opération destructive.
2. **Mode maintenance** + **sauvegarde de la base** + capture du `HEAD` courant (pour rollback).
3. `git fetch` origin.
4. `git reset --hard origin/<branche>` — le serveur reflète exactement le dépôt.
5. **`SchemaSyncService::sync()`** — réconciliation déclarative (additive) du schéma.
6. **`MigrationRunner::migrate()`** — migrations de données versionnées.
7. `app('module_sdk')->syncAll()` — re-synchronise les manifestes (permissions, widgets, routes).
8. `RoleSync::sync()` — synchronise les rôles.
9. `app('cache')->flush()`, puis **sortie du mode maintenance**.

> Toute erreur de schéma ou de migration déclenche un **ROLLBACK COMPLET** : la base est
> restaurée depuis la sauvegarde et le code repositionné (`git reset`) sur l'ancien `HEAD`.

Configuration `.env` :

```
GITHUB_BRANCH=main      # branche suivie (défaut: main)
GIT_BINARY=git          # chemin de git si absent du PATH d'Apache (ex. Windows)
```

> Supprimés : `scripts/update.php`, `scripts/check_update.php`,
> `API/endpoints/webhook_update.php`, et l'ancien `UpdateService` (GitHub Releases + zip).
> L'`UpdateService` actuel ne télécharge **rien** : le dépôt Git EST la source.

### Ce que fait (et ne fait pas) `SchemaSyncService`

`SchemaSyncService` lit `pronote.sql` + tous les `modules/*/Database/install.sql` +
`rgpd/Database/*.sql`, en extrait les blocs `CREATE TABLE`, et **ajout seulement**, de façon
idempotente :

- table absente → `CREATE TABLE` (le statement complet du `.sql`) ;
- table présente → `ADD COLUMN` pour chaque colonne **manquante** (comparé à
  `information_schema.COLUMNS`).

Il **ne** supprime jamais une table/colonne, **ne** modifie jamais un type existant,
et **n'a aucune** table de suivi ni notion de version. C'est ce qui rend inutile toute
réinitialisation de base après un commit de schéma.

> ⚠️ **Limite à connaître** : `SchemaSyncService` ne parse **que** les `CREATE TABLE`.
> Il **ignore** les instructions `ALTER TABLE`, `INSERT`, `DROP`, index/contraintes
> ajoutés hors `CREATE`, etc. Or `pronote.sql` ajoute `etablissement_id` aux tables
> utilisateurs et référentiels via des **`ALTER TABLE`** (voir plus bas). Ces colonnes
> sont donc présentes sur une **installation neuve** (import complet de `pronote.sql`),
> mais elles **ne seraient pas** rétro-ajoutées par une simple MAJ sur une base
> ancienne qui ne les aurait pas. **Conséquence pratique pour le contributeur** : pour
> qu'une nouvelle colonne soit propagée par les MAJ, elle doit figurer **dans le bloc
> `CREATE TABLE` lui-même** (cas des `install.sql` de modules, qui déclarent
> `etablissement_id` directement dans le `CREATE`).
>
> **Remède pour les transformations non additives** (changement de type, index/FK sur une
> table déjà installée, backfill, `DROP` contrôlé, colonne ajoutée hors `CREATE`) : écrivez une
> **migration versionnée** `database/migrations/<horodatage>_<nom>.php` (objet `up(\PDO)/down(\PDO)`).
> `MigrationRunner` l'exécute à la MAJ suivante, juste après `SchemaSyncService`. Voir
> [`docs/UPDATING.md`](UPDATING.md).

---

## Conventions de nommage

Le projet est francophone et historiquement issu d'un import « façon Pronote » : les
conventions **ne sont pas uniformes**. Documentez/codez selon la table réelle.

| Élément | Convention dominante | Exemples réels |
|---|---|---|
| Tables | `snake_case` | `notes`, `tickets_support`, `audit_log`, `modules_config` |
| Clé primaire | `id` INT AUTO_INCREMENT | `id` |
| Mot de passe | **`mot_de_passe`** (jamais `password`) | colonne `mot_de_passe` hashée bcrypt cost 12 |
| Adresse e-mail | **`mail`** (⚠️ **pas** `email`) | `mail` sur `eleves`, `professeurs`, `parents`, `administrateurs`, `vie_scolaire`, `super_admins`, `etablissements` |
| Login utilisateur | **`identifiant`** = `nom.prenom` | colonne `identifiant`, UNIQUE par établissement |
| Timestamps | `date_creation` / `created_at` (mixte selon la table) | `date_creation DATETIME DEFAULT CURRENT_TIMESTAMP`, ou `created_at`/`updated_at` |
| Booléens | `tinyint(1)` ou `enum('oui','non')` | `actif`, `enabled`, `est_CPE enum('oui','non')` |
| Clés étrangères | souvent `id_<table>` (style FR), parfois `<table>_id` | `id_eleve`, `id_matiere`, `id_professeur` (table `notes`) ; mais `user_id`, `etablissement_id` |
| Index | `idx_<desc>` | `idx_etab`, `idx_classe`, `idx_trimestre` |
| Contraintes FK | `fk_<table>_<ref>` | `fk_notes_eleve`, `fk_eleves_etab` |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci` | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` |

### Pièges de colonnes (sources de bugs historiques)

- **`mail`, pas `email`.** Toutes les tables utilisateurs stockent l'e-mail dans `mail`.
  (Les `SELECT` d'auth font `SELECT mail AS email` pour exposer un alias `email` au code PHP.)
- **`eleves.classe` est un `varchar(50)`**, pas une FK vers `classes.id`. La classe d'un
  élève est stockée par **nom** (ex. `"3eB"`), indexée par `idx_classe`. De même,
  `professeurs.matiere` est un `varchar(100)` (nom de matière), pas une FK.
  `professeur_classes.nom_classe` et `professeurs.professeur_principal` (`'oui'`/`'non'`)
  suivent la même logique « par nom / par chaîne ».
- **`identifiant`** = login `nom.prenom`. Il est UNIQUE **par établissement**
  (`uk_eleve_ident_etab (identifiant, etablissement_id)`, etc.), pas globalement.

### Colonnes chiffrées au repos

Certaines colonnes sensibles sont **chiffrées au repos** via `\API\Core\Encryption`
(AES-256-GCM, `KEY_VERSION = 2`, dérivation HKDF-SHA256). Le format stocké est
`version:b64(nonce):b64(chiffré):b64(tag)`. Une colonne chiffrée doit donc être typée **`TEXT`**
ou au minimum **`VARCHAR(255)`** (ex. `two_factor_secret VARCHAR(255)`) : un type trop court
**tronque** le payload et **corrompt** le déchiffrement.

---

## Multi-établissement : scoping par `etablissement_id`

Fronote est **multi-établissement**. Le contexte courant est porté par
`\API\Core\EstablishmentContext` :

```php
use API\Core\EstablishmentContext;

$eid = EstablishmentContext::id();          // int — l'établissement courant
// Fragments SQL paramétrés (recommandés pour le nouveau code) :
$sql = "SELECT * FROM notes WHERE id_professeur = ?" . EstablishmentContext::placeholderAnd();
$stmt = $pdo->prepare($sql);
$stmt->execute([$profId, EstablishmentContext::scopeValue()]);
```

Points clés du contexte :

- `EstablishmentContext::set($id)` est appelé **après login** par
  `API\Auth\SessionGuard` (depuis `user.etablissement_id`).
- Si aucun scope n'est défini et qu'il existe **exactement un** établissement,
  `id()` retourne le sien. S'il en existe **zéro ou plusieurs**, `id()` **lève une
  exception** (sécurité de cloisonnement : pas de défaut silencieux à `1`).
- Au **login**, le contexte n'est pas encore connu (poule/œuf) : `UserProvider`
  recherche l'utilisateur cross-établissement (ou via un `etablissement_id` explicite
  du sélecteur de profil), puis le contexte est fixé depuis `user.etablissement_id`.
- Helpers SQL : `placeholderWhere()` / `placeholderAnd()` (avec `?`, à binder via
  `scopeValue()` — **préférés**) ; `sqlWhere()` / `sqlAnd()` (valeur inline, legacy).

### Quelles tables portent `etablissement_id` ?

1. **Tables utilisateurs et référentiels du cœur** — `administrateurs`, `eleves`,
   `professeurs`, `parents`, `vie_scolaire`, `periodes`, `matieres`, `classes` — reçoivent
   `etablissement_id INT NOT NULL DEFAULT 1` via des **`ALTER TABLE`** dans `pronote.sql`
   (présentes sur installation neuve). Les `UNIQUE KEY` `identifiant`/`code`/`nom` y sont
   redéfinies pour inclure `etablissement_id` (unicité **par établissement**).
2. **Tables métier de modules** — la colonne `etablissement_id` est déclarée
   **directement dans le `CREATE TABLE`** de l'`install.sql` (donc propagée par
   `SchemaSyncService`). Exemple `modules/notes/Database/install.sql` :

   ```sql
   CREATE TABLE IF NOT EXISTS `notes` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `etablissement_id` int(11) NOT NULL DEFAULT 1,
     `id_eleve` int(11) NOT NULL,
     ...
     KEY `idx_etab` (`etablissement_id`),
     CONSTRAINT `fk_notes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```

> **Règle d'or** : toute table contenant des données propres à un établissement
> **doit** porter `etablissement_id` (avec `idx_etab` + FK vers `etablissements`), et
> **toute requête** sur cette table (lecture, écriture, listes, agrégats) **doit**
> filtrer sur `etablissement_id = EstablishmentContext::id()`. Oublier ce filtre = fuite
> de données entre établissements.

### Onboarding obligatoire

Un établissement fraîchement créé a le code `'default'` (voir le `INSERT` initial de
`pronote.sql`). Tant qu'il porte ce code, `API/onboarding_gate.php` **force** la
configuration (redirection vers l'onboarding). Le `super_admin` peut gérer plusieurs
établissements.

---

## Requêtes préparées — obligatoire

Ne **jamais** interpoler de variable dans le SQL :

```php
// BON
$stmt = $pdo->prepare("SELECT * FROM notes WHERE id_eleve = ? AND trimestre = ?");
$stmt->execute([$eleveId, $trimestre]);

// MAUVAIS — injection SQL
$pdo->query("SELECT * FROM notes WHERE id_eleve = $eleveId");
```

---

## Tables clés par domaine

### Utilisateurs et référentiels (cœur, `pronote.sql`)

| Table | Description |
|---|---|
| `etablissements` | Établissements (PK `id`, `code` unique, `type`, `default_locale`…). Ligne 1 = `'default'`. |
| `super_admins` | Super-administrateurs globaux (gèrent plusieurs établissements). |
| `administrateurs`, `professeurs`, `eleves`, `parents`, `vie_scolaire` | Comptes par rôle. Colonnes communes : `nom`, `prenom`, `mail`, `identifiant`, `mot_de_passe`, `actif`, `etablissement_id`, 2FA, anti-bruteforce (`failed_login_attempts`, `locked_until`). |
| `classes` | Classes (`nom`, `niveau`, `annee_scolaire`, `professeur_principal_id`, `etablissement_id`). |
| `matieres` | Matières (`nom`, `code`, `coefficient`, `etablissement_id`). |
| `periodes` | Trimestres/semestres (`numero`, `type`, dates, `etablissement_id`). Périodes auto-septembre via `PeriodeService::defaultPeriodes`. |
| `parent_eleve` | Liaison parent↔élève (`id_parent`, `id_eleve`, `lien`). |
| `professeur_classes` | Liaison professeur↔classe (`id_professeur`, `nom_classe`). |

### Modules et configuration

| Table | Description |
|---|---|
| `modules_config` | Registre des modules : `module_key` (unique par établissement), `label`, `enabled`, `category`/`topbar_category`, `route_path`, `roles_autorises` (JSON), `is_core`, `establishment_types` (JSON), ordres de tri. |
| `module_settings_schema` | Schéma des réglages déclarés par les modules. |
| `dashboard_widgets`, `user_dashboard_config`, `dashboard_layouts` | Widgets de tableau de bord et layout par utilisateur. |
| `feature_flags` | Feature flags (scopés par établissement, unicité `uk_flag_etab`). |
| `marketplace_installs`, `themes`, `theme_token_overrides` | Marketplace et thèmes. |

> ⚠️ **Plus de table `module_migrations`** : ne pas la documenter ni s'y référer. Le seul
> journal de migrations est désormais `schema_migrations` (auto-créée par `MigrationRunner`,
> pour les migrations de données versionnées `database/migrations/*.php`).

### Sécurité

| Table | Description |
|---|---|
| `audit_log` | Journal d'audit (`action`, `model`/`model_id`, `user_id`/`user_type`, `old_values`/`new_values` JSON, IP, user-agent). Écrit via `app('audit')`. |
| `login_attempts` | Tentatives de connexion (par `ip` et `identifier`) — rate-limit IP + identifiant. |
| `rate_limits`, `api_rate_limits` | Compteurs de rate limiting (app et API). |
| `remember_tokens` | Tokens « se souvenir de moi » (hash SHA-256). |
| `session_security` | Sessions actives / détection d'anomalies. |
| `oauth_bindings` | Liaisons OAuth/SSO des comptes. |
| `demandes_reinitialisation` | Demandes de réinitialisation de mot de passe. |

### Données métier (exemples)

| Table | Module / fichier | Notes |
|---|---|---|
| `notes` | `modules/notes/Database/install.sql` | FK `id_eleve`/`id_matiere`/`id_professeur`, `etablissement_id`, `trimestre`. |
| `conversations`, `messages`, `message_attachments`… | `modules/messagerie/Database/install.sql` | Messagerie scopée (`etablissement_id` sur `conversations`). |
| `tickets_support`, `support_ticket_messages`, `support_sla` | `modules/support/Database/install.sql` | Tickets multi-messages (fil) + SLA + notifications (refonte juin 2026). |
| `import_export_logs` | `pronote.sql` | Traçabilité de l'import en masse (`admin/systeme/import_export.php`, `API/Services/Import/BulkImporter`). |

---

## Ajouter / modifier une table de module

1. Éditez le **`CREATE TABLE IF NOT EXISTS`** dans `modules/<m>/Database/install.sql`
   (schéma final complet, idempotent). Le chemin peut être personnalisé via
   `module.json` → `database.install`.
2. Si la table porte des données par établissement, déclarez `etablissement_id INT
   NOT NULL DEFAULT 1` **dans le `CREATE`** (+ `KEY idx_etab` + FK vers `etablissements`).
   **Ne pas** compter sur un `ALTER TABLE` séparé : `SchemaSyncService` ne le verrait pas.
3. Pour une **nouvelle colonne** sur une table existante : ajoutez-la **dans le bloc
   `CREATE TABLE`** du `.sql` canonique. `SchemaSyncService` la rétro-ajoutera via
   `ADD COLUMN` sur les bases existantes lors de la prochaine MAJ. (Prévoyez une valeur
   par défaut, car la colonne est ajoutée non destructivement à des lignes existantes.)
4. **Activation / re-sync** : réinstaller/activer le module (`provisionSql`) en local,
   ou laisser la MAJ (`SchemaSyncService`) réconcilier en prod.
5. Bumpez `version.json` à tout changement de schéma.

> `SchemaSyncService` est « ajout seulement » : **aucun** `DROP COLUMN`/`MODIFY` automatique.
> Pour un changement de type, une suppression de colonne ou l'ajout d'un index/FK sur une table
> existante, écrivez une **migration versionnée** `database/migrations/<horodatage>_<nom>.php`
> (objet `up(\PDO)/down(\PDO)`) : `MigrationRunner` l'exécute à la prochaine MAJ, juste après
> `SchemaSyncService`. **Ne pas** éditer la base de production à la main. Voir
> [`docs/UPDATING.md`](UPDATING.md).

---

## Bonnes pratiques

1. **`CREATE TABLE IF NOT EXISTS`** systématique dans les `.sql` (idempotence).
2. **Toujours filtrer `etablissement_id`** sur les tables scopées (via
   `EstablishmentContext::placeholderAnd()` + `scopeValue()`).
3. **Requêtes préparées** uniquement (aucune interpolation).
4. **Indexer** les colonnes de `WHERE`/`JOIN`/`ORDER BY` (au minimum `etablissement_id`,
   `id_eleve`, dates…).
5. **`LIMIT`** sur les listes ; éviter les N+1 (privilégier les `JOIN`/batch).
6. **Transactions** pour les opérations multi-tables :

   ```php
   $pdo->beginTransaction();
   try {
       // …opérations…
       $pdo->commit();
   } catch (\Throwable $e) {
       $pdo->rollBack();
       throw $e;
   }
   ```

   ⚠️ Le **DDL** (`CREATE`/`ALTER`) provoque un *commit implicite* en MySQL : ne
   l'enrobez pas dans une transaction en espérant pouvoir l'annuler.
