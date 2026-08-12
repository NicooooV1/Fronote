# Mettre à jour Fronote & faire évoluer le schéma

Référence pour appliquer une mise à jour et **faire une modification de base de données
correctement**. À lire avant tout changement touchant le schéma.

## Modèle mental : le dépôt Git EST la source

Fronote n'utilise ni ZIP de release ni téléchargement de paquet : **le dépôt Git est la source
de vérité**. Une mise à jour = amener le serveur au dernier commit de la branche suivie, puis
réconcilier la base de façon idempotente. Pas de « réinitialisation » de base après un commit.

Deux mécanismes complémentaires réconcilient la base :

| Mécanisme | Rôle | Fait | Ne fait PAS |
|-----------|------|------|-------------|
| **`SchemaSyncService`** | Schéma **déclaratif**, additif | `CREATE TABLE` (table absente) + `ADD COLUMN` (colonne manquante), lus depuis `pronote.sql` + `modules/*/Database/install.sql` + `rgpd/Database/*.sql` | Renommage, changement de type, **index / clé étrangère sur une table existante**, migration de données, `DROP` |
| **`MigrationRunner`** | Migrations **versionnées** (`up()/down()`) | Tout ce que SchemaSync ne sait pas exprimer, un fichier à la fois, journalisé dans la table `schema_migrations` | Rien d'automatique — vous écrivez le `up()` |

> **Règle d'or.** Une **nouvelle table** ou une **nouvelle colonne** ne nécessite **aucune
> migration** : ajoutez-la au `.sql` déclaratif, `SchemaSyncService` l'applique. Réservez les
> migrations versionnées aux **petits cas** que le déclaratif ne couvre pas (rename, retype,
> index/FK sur une base déjà installée, backfill, suppression contrôlée).

> **Ce qui a disparu.** L'ancien système *par module* n'existe plus : plus de tables
> `module_migrations`/`core_migrations`, plus de `modules/*/Database/migrations/`, plus de clé
> `module.json["migrations"]`, plus de `CoreMigrator` ni de `scripts/migrate.php`. Le système
> **versionné actuel** est au niveau du dépôt (`database/migrations/` + `MigrationRunner`) et n'a
> **pas de wrapper CLI** : il s'exécute uniquement via le bouton de mise à jour.

## Appliquer une mise à jour

Deux points d'entrée, même flux : `admin/systeme/update.php` (rôle `administrateur`) et
`platform/updates.php` (portail plateforme). Tous deux appellent `UpdateService::applyUpdate()` :

1. **Garde-fous** — refus si la branche servie ≠ `GITHUB_BRANCH`, si l'arbre de travail est
   *dirty* (un `git reset --hard` détruirait les modifs non commitées), ou si le HEAD est illisible.
2. **Filet de sécurité** — passage en **mode maintenance** (refus si indisponible), **sauvegarde
   de la base**, capture du HEAD courant.
3. `git fetch` → `git reset --hard origin/<branche>` (+ restauration `.env` s'il a disparu).
4. **`SchemaSyncService::sync()`** — réconciliation déclarative du schéma.
5. **`MigrationRunner::migrate()`** — migrations versionnées en attente.
6. **Toute erreur schéma/migration ⇒ ROLLBACK COMPLET** : base restaurée depuis la sauvegarde +
   `git reset --hard` sur l'ancien HEAD.
7. `ModuleSDK::syncAll()` (manifestes de modules) → vidage
   du cache → sortie de maintenance.

Configuration (`.env`) :

```
GITHUB_BRANCH=main     # branche suivie (doit correspondre à la branche servie)
GIT_BINARY=git         # chemin de git si absent du PATH d'Apache
```

La version courante est lue dans `version.json` (`version`).

> **Attention branche.** Si le serveur est servi sur une autre branche que `GITHUB_BRANCH`
> (ex. `feat/...`), la mise à jour en un bouton est **refusée** tant que les deux ne coïncident pas.

### Équivalent manuel (CLI)

```bash
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
# la réconciliation schéma + migrations se rejoue au prochain applyUpdate ;
# SchemaSyncService::sync() et MigrationRunner::migrate() sont idempotents.
```

> ⚠️ **`.env`** ne doit jamais être commité et doit rester propriété de l'utilisateur web
> (`www-data`, mode `640`). L'éditer en tant que `root` casse ces droits → Apache ne peut plus le
> lire → 500. Après édition : `chown www-data:www-data .env && chmod 640 .env`.

## Faire une modification de schéma

### Cas 1 — nouvelle table / nouvelle colonne (le cas courant)

Éditez le `.sql` **déclaratif** concerné :

- table **du cœur** → `pronote.sql` ;
- table **d'un module** → `modules/<module>/Database/install.sql` ;
- table **RGPD** → `rgpd/Database/install.sql`.

Rien d'autre : au prochain `SchemaSyncService::sync()`, la table/colonne manquante est créée.
Aucune migration à écrire. Deux précautions :

- **Colonnes chiffrées au repos** (`\API\Core\Encryption`, AES-256-GCM, `KEY_VERSION=2` HKDF) : le
  chiffré fait ~88 caractères ou plus (format `version:b64(nonce):b64(chiffré):b64(tag)`). Une
  colonne chiffrée doit être `TEXT` ou au minimum `VARCHAR(255)` — sinon **troncature → corruption**
  (ex. `two_factor_secret` est `VARCHAR(255)`).
- **Cloisonnement multi-tenant** : toute table métier porte `etablissement_id`, avec l'index ET la
  clé étrangère `FOREIGN KEY (etablissement_id) REFERENCES etablissements(id)` déclarés **dans le
  `CREATE TABLE`** — car `SchemaSyncService` ne synchronise **pas** les index/FK (voir cas 2).

### Cas 2 — rename, retype, index/FK, backfill, suppression (petit cas)

`SchemaSyncService` est additif : il ne touche **ni** aux index, **ni** aux clés étrangères, **ni**
aux types existants. Pour ça, écrivez une **migration versionnée** :

`database/migrations/<AAAA_MM_JJ_HHMMSS>_<nom>.php` :

```php
<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        // idempotent : vérifiez avant d'agir (la migration doit être rejouable sans risque)
        if (!$this->hasColumn($pdo, 'ma_table', 'ma_colonne')) {
            $pdo->exec("ALTER TABLE `ma_table` ADD INDEX `idx_x` (`ma_colonne`)");
        }
    }

    public function down(\PDO $pdo): void { /* réversibilité si possible */ }

    private function hasColumn(\PDO $pdo, string $t, string $c): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
        $s->execute([$t, $c]);
        return (bool) $s->fetchColumn();
    }
};
```

- Ordre d'exécution = ordre **lexicographique** du nom de fichier (préfixe horodaté).
- Journalisé dans `schema_migrations` (une migration ne rejoue jamais).
- Le DDL MySQL committe implicitement (pas de transaction) : rendez chaque `up()` **idempotent**.
- En échec, `UpdateService` **annule toute la mise à jour** (base + code restaurés).

> Le produit n'étant pas encore publié en production, il n'existe **aucune donnée à migrer** :
> privilégiez le déclaratif (cas 1). Les migrations versionnées restent pour les rares
> transformations que le déclaratif ne sait pas faire.

### Le piège du « filet » pronote.sql

~40 tables de 9 modules (accessibilite, bourses, conseil_classe, echanges, enquetes, formations,
intelligence, inventaire, mediatheque) sont définies **à la fois** dans `pronote.sql` (ancienne
version, importée en premier → elle gagne) **et** dans le `install.sql` du module (version
actuelle). `SchemaSyncService` **fusionne** les colonnes des deux (le schéma de travail est
l'union). **Ne supprimez pas** une copie de `pronote.sql` sans vérifier que le `install.sql` du
module déclare bien toutes les colonnes que le code utilise — certaines n'existent que dans la copie
`pronote.sql`. À l'install, la réconciliation tourne **avant** les INSERT de données de référence
des modules, pour que ces INSERT voient les colonnes fusionnées.

## Ajouter un module

`modules/<clé>/` avec au minimum : `module.json` (manifeste : permissions, widgets, routes…) et
`Database/install.sql` (schéma idempotent, `CREATE TABLE IF NOT EXISTS`). Le schéma est provisionné
pour **tous** les modules découverts, même désactivés (activation = visibilité, pas schéma). Voir
[docs/module-sdk.md](module-sdk.md).

## Vérifier une mise à jour

```bash
php vendor/bin/phpunit --no-coverage      # suite de tests (voir tests/README.md)
composer audit                            # vulnérabilités dépendances
vendor/bin/phpstan analyse                # analyse statique (bloquante en CI)
```

La CI (`.github/workflows/validate.yml`) exécute PHPStan (bloquant, `phpstan-baseline.neon`), les
tests et `npm audit`.
