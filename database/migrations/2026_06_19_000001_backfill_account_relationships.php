<?php
declare(strict_types=1);
/**
 * Backfill de la table `account_relationships` (modèle de relations unifié) à partir
 * des liens hérités, de façon IDEMPOTENTE (INSERT IGNORE sur la clé unique uk_rel) :
 *   - parent_eleve            → parent_of / legal_guardian_of / financial_responsible_of
 *   - professeur_classes      → teacher_of (nom de classe résolu en id)
 *   - classes.prof_principal  → main_teacher_of
 *
 * La table elle-même est définie dans pronote.sql (créée à l'install et par
 * SchemaSyncService lors d'une mise à jour) ; on la crée ici en filet IF NOT EXISTS
 * au cas où cette migration serait jouée seule (scripts/migrate.php) avant la synchro
 * de schéma. Chaque instruction est protégée : un échec de backfill (table/colonne
 * hétérogène) est journalisé sans interrompre la mise à jour.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $driver = '';
        try { $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME); } catch (\Throwable $e) {}

        // Filet : créer account_relationships si la synchro de schéma n'est pas passée.
        if ($driver === 'mysql') {
            $this->run($pdo,
                "CREATE TABLE IF NOT EXISTS `account_relationships` (
                    `id`                INT AUTO_INCREMENT PRIMARY KEY,
                    `source_type`       VARCHAR(30)  NOT NULL,
                    `source_id`         INT          NOT NULL,
                    `target_type`       VARCHAR(30)  NOT NULL,
                    `target_id`         INT          NOT NULL,
                    `relationship_type` VARCHAR(40)  NOT NULL,
                    `etablissement_id`  INT          DEFAULT NULL,
                    `starts_at`         DATETIME     DEFAULT NULL,
                    `expires_at`        DATETIME     DEFAULT NULL,
                    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
                    `created_by_type`   VARCHAR(30)  DEFAULT NULL,
                    `created_by_id`     INT          DEFAULT NULL,
                    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_rel` (`source_type`, `source_id`, `target_type`, `target_id`, `relationship_type`),
                    KEY `idx_rel_source` (`source_type`, `source_id`),
                    KEY `idx_rel_target` (`target_type`, `target_id`),
                    KEY `idx_rel_type` (`relationship_type`),
                    KEY `idx_rel_active` (`is_active`, `expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // parent_eleve → relations parentales (le libellé `lien` affine le type).
        $this->run($pdo,
            "INSERT IGNORE INTO account_relationships
                (source_type, source_id, target_type, target_id, relationship_type, etablissement_id, created_at)
             SELECT 'parent', pe.id_parent, 'eleve', pe.id_eleve,
                    CASE pe.lien
                        WHEN 'tuteur_legal'           THEN 'legal_guardian_of'
                        WHEN 'responsable_financier'  THEN 'financial_responsible_of'
                        ELSE 'parent_of'
                    END,
                    p.etablissement_id, NOW()
               FROM parent_eleve pe
               JOIN parents p ON p.id = pe.id_parent"
        );

        // Idempotence sur changement de `lien` : une exécution antérieure a pu refléter un
        // autre type (ex. parent_of) pour ce couple ; si pe.lien a changé depuis, on désactive
        // l'ancien type — UNIQUEMENT les lignes issues du backfill (created_by_id IS NULL),
        // jamais une relation saisie à la main via RelationshipService.
        $this->run($pdo,
            "UPDATE account_relationships ar
               JOIN parent_eleve pe
                 ON pe.id_parent = ar.source_id AND pe.id_eleve = ar.target_id
              SET ar.is_active = 0
              WHERE ar.source_type = 'parent' AND ar.target_type = 'eleve'
                AND ar.created_by_id IS NULL
                AND ar.relationship_type IN ('parent_of','legal_guardian_of','financial_responsible_of')
                AND ar.relationship_type <> CASE pe.lien
                        WHEN 'tuteur_legal'           THEN 'legal_guardian_of'
                        WHEN 'responsable_financier'  THEN 'financial_responsible_of'
                        ELSE 'parent_of'
                    END"
        );

        // professeur_classes (lien par NOM) → teacher_of (résolu en id de classe).
        $this->run($pdo,
            "INSERT IGNORE INTO account_relationships
                (source_type, source_id, target_type, target_id, relationship_type, etablissement_id, created_at)
             SELECT 'professeur', pc.id_professeur, 'classe', c.id, 'teacher_of', c.etablissement_id, NOW()
               FROM professeur_classes pc
               JOIN professeurs pr ON pr.id = pc.id_professeur
               JOIN classes c ON c.nom = pc.nom_classe AND c.etablissement_id = pr.etablissement_id"
        );

        // classes.professeur_principal_id → main_teacher_of.
        $this->run($pdo,
            "INSERT IGNORE INTO account_relationships
                (source_type, source_id, target_type, target_id, relationship_type, etablissement_id, created_at)
             SELECT 'professeur', c.professeur_principal_id, 'classe', c.id, 'main_teacher_of', c.etablissement_id, NOW()
               FROM classes c
              WHERE c.professeur_principal_id IS NOT NULL"
        );
    }

    public function down(\PDO $pdo): void
    {
        // No-op volontaire : la table est gérée déclarativement (pronote.sql/SchemaSyncService)
        // et les relations saisies manuellement ne se distinguent pas du backfill → on ne
        // supprime rien (additif et réversible sans perte de données métier).
    }

    private function run(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log('[migration backfill_account_relationships] ' . $e->getMessage());
        }
    }
};
