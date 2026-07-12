<?php
declare(strict_types=1);
/**
 * Migration : régionalise la matrice RBAC (rbac_permissions) par établissement (finding #2).
 *
 * - garantit la colonne `etablissement_id` (déjà posée par le schéma de base, DEFAULT 1) ;
 * - remplace la clé unique (role, permission) par (role, permission, etablissement_id) : chaque
 *   établissement peut poser ses propres surcharges sans écraser celles des autres.
 *
 * Rétrocompatible : les lignes existantes gardent etablissement_id = 1 (établissement par défaut).
 * Les lectures (Authorization::roleGrants / RBAC::checkDynamicPermission) filtrent sur
 * l'établissement de l'utilisateur, avec repli non scopé tant que la migration n'est pas passée.
 * Idempotente (rejouable sans risque).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'rbac_permissions', 'etablissement_id')) {
            $pdo->exec("ALTER TABLE `rbac_permissions` ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `permission`");
        }
        if ($this->hasIndex($pdo, 'rbac_permissions', 'uk_role_permission')) {
            $pdo->exec("ALTER TABLE `rbac_permissions` DROP INDEX `uk_role_permission`");
        }
        if (!$this->hasIndex($pdo, 'rbac_permissions', 'uk_role_permission_etab')) {
            $pdo->exec("ALTER TABLE `rbac_permissions` ADD UNIQUE KEY `uk_role_permission_etab` (`role`, `permission`, `etablissement_id`)");
        }
    }

    public function down(\PDO $pdo): void
    {
        // On ne supprime pas la colonne (préserve les surcharges) ; on tente de rétablir l'ancienne
        // clé unique uniquement s'il n'existe pas de doublons (role, permission).
        if ($this->hasIndex($pdo, 'rbac_permissions', 'uk_role_permission_etab')) {
            $pdo->exec("ALTER TABLE `rbac_permissions` DROP INDEX `uk_role_permission_etab`");
        }
        if (!$this->hasIndex($pdo, 'rbac_permissions', 'uk_role_permission')) {
            try {
                $pdo->exec("ALTER TABLE `rbac_permissions` ADD UNIQUE KEY `uk_role_permission` (`role`, `permission`)");
            } catch (\Throwable $e) {
                // doublons multi-établissement → on n'impose pas.
            }
        }
    }

    private function hasColumn(\PDO $pdo, string $table, string $col): bool
    {
        $s = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1"
        );
        $s->execute([$table, $col]);
        return (bool) $s->fetchColumn();
    }

    private function hasIndex(\PDO $pdo, string $table, string $index): bool
    {
        $s = $pdo->prepare(
            "SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1"
        );
        $s->execute([$table, $index]);
        return (bool) $s->fetchColumn();
    }
};
