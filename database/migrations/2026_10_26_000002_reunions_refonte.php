<?php
declare(strict_types=1);
/**
 * 4.6 — Réunions refondues : tout ce qui n'est pas un cours.
 *  - reunions.type devient ouvert (VARCHAR) : rencontres parents-professeurs, conseils de classe,
 *    conseils de discipline, commissions éducatives, équipes éducatives (ESS), conseil pédagogique,
 *    conseil d'administration, réunions d'équipe, entretiens…  (« individuel » → « entretien »)
 *  - reunions : salle, élève concerné, confidentialité, séance (début/fin, point en cours),
 *    compte rendu publié, lien vers la session de conseil de classe
 *  - reunion_participants : qui est convoqué, à quel titre, a-t-il confirmé, était-il présent
 *  - reunion_points : ordre du jour (durée, statut, notes, décision)
 * Idempotente.
 */
return new class {
    private function table(\PDO $pdo, string $t): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }

    private function column(\PDO $pdo, string $t, string $c): ?string
    {
        $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$t, $c]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function up(\PDO $pdo): void
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || !$this->table($pdo, 'reunions')) { return; }

        $type = $this->column($pdo, 'reunions', 'type');
        if ($type !== null && str_starts_with(strtolower($type), 'enum')) {
            $pdo->exec("ALTER TABLE reunions MODIFY type VARCHAR(40) NOT NULL DEFAULT 'autre'");
            $pdo->exec("UPDATE reunions SET type = 'entretien' WHERE type = 'individuel'");
        }
        $add = [
            'salle_id'           => 'INT NULL AFTER lieu',
            'eleve_id'           => 'INT NULL AFTER classe_id',
            'confidentiel'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'visio_url'          => 'VARCHAR(255) NULL',
            'started_at'         => 'DATETIME NULL',
            'ended_at'           => 'DATETIME NULL',
            'point_courant'      => 'INT NULL',
            'cr_publie'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            'conseil_session_id' => 'INT NULL',
            'periode_id'         => 'INT NULL',
            'duree_creneau'      => 'INT NULL',
        ];
        foreach ($add as $c => $def) {
            if ($this->column($pdo, 'reunions', $c) === null) { $pdo->exec("ALTER TABLE reunions ADD COLUMN `$c` $def"); }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reunion_id INT NOT NULL,
            user_id INT NOT NULL,
            user_type VARCHAR(20) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'membre',
            convocation ENUM('a_envoyer','envoyee','confirmee','excusee') NOT NULL DEFAULT 'a_envoyer',
            presence ENUM('present','absent','excuse','retard') NULL,
            arrive_at DATETIME NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_reunion_participant (reunion_id, user_type, user_id),
            KEY idx_participant_user (user_type, user_id),
            CONSTRAINT fk_participant_reunion FOREIGN KEY (reunion_id) REFERENCES reunions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reunion_id INT NOT NULL,
            ordre INT NOT NULL DEFAULT 1,
            titre VARCHAR(255) NOT NULL,
            description TEXT NULL,
            duree_minutes INT NOT NULL DEFAULT 10,
            statut ENUM('a_traiter','en_cours','traite','reporte') NOT NULL DEFAULT 'a_traiter',
            notes TEXT NULL,
            decision TEXT NULL,
            debut_at DATETIME NULL,
            KEY idx_point_reunion (reunion_id, ordre),
            CONSTRAINT fk_point_reunion FOREIGN KEY (reunion_id) REFERENCES reunions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
