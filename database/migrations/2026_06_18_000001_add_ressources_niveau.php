<?php
declare(strict_types=1);
/**
 * Migration : ajoute la colonne `niveau` (niveau scolaire) à ressources_pedagogiques.
 *
 * Exemple de migration versionnée — idempotente (vérifie l'existence de la colonne
 * avant l'ALTER, donc rejouable sans risque). Sert aussi de baseline : régularise la
 * colonne déjà ajoutée manuellement lors du diagnostic du module Ressources.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'ressources_pedagogiques', 'niveau')) {
            $pdo->exec("ALTER TABLE `ressources_pedagogiques` ADD COLUMN `niveau` VARCHAR(20) DEFAULT NULL AFTER `difficulte`");
        }
    }

    public function down(\PDO $pdo): void
    {
        if ($this->hasColumn($pdo, 'ressources_pedagogiques', 'niveau')) {
            $pdo->exec("ALTER TABLE `ressources_pedagogiques` DROP COLUMN `niveau`");
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
};
