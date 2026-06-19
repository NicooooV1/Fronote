<?php
/**
 * @deprecated Table ORPHELINE. Elle servait aux anciens « matricules numériques »
 * (1 séquence atomique par établissement). Depuis le passage des identifiants de
 * connexion au format `nom.prenom` (voir API/Services/IdentifierGenerator), plus
 * aucun code ne lit/écrit cette table. Conservée telle quelle pour ne pas modifier
 * une migration déjà jouée en production ; une migration de suppression
 * (DROP TABLE IF EXISTS identifiant_counters) pourra être ajoutée ultérieurement.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `identifiant_counters` (
                `etablissement_id` INT NOT NULL,
                `next_value` BIGINT NOT NULL DEFAULT 1,
                PRIMARY KEY (`etablissement_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS `identifiant_counters`");
    }
};
