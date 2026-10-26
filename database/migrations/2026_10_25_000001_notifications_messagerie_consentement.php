<?php
declare(strict_types=1);
/**
 * 4.3 — Centre de notifications, messagerie, consentement cookies & mesure d'audience.
 *  - notifications_globales : regroupement (group_key, group_count), auteur (acteur), index de lecture
 *  - conversations / participants : index de boîte de réception
 *  - cookie_consents : preuve du consentement (CNIL) ; audience_events : mesure d'audience interne
 * Idempotente : chaque étape vérifie l'existant.
 */
return new class {
    private function col(\PDO $pdo, string $t, string $c): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$t, $c]);
        return (bool) $st->fetchColumn();
    }
    private function idx(\PDO $pdo, string $t, string $i): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $st->execute([$t, $i]);
        return (bool) $st->fetchColumn();
    }
    private function table(\PDO $pdo, string $t): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }

    public function up(\PDO $pdo): void
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }

        if ($this->table($pdo, 'notifications_globales')) {
            if (!$this->col($pdo, 'notifications_globales', 'etablissement_id')) { $pdo->exec('ALTER TABLE notifications_globales ADD COLUMN etablissement_id INT NULL AFTER id'); }
            if (!$this->col($pdo, 'notifications_globales', 'group_key')) { $pdo->exec('ALTER TABLE notifications_globales ADD COLUMN group_key VARCHAR(120) NULL'); }
            if (!$this->col($pdo, 'notifications_globales', 'group_count')) { $pdo->exec('ALTER TABLE notifications_globales ADD COLUMN group_count INT NOT NULL DEFAULT 1'); }
            if (!$this->col($pdo, 'notifications_globales', 'digest_sent')) { $pdo->exec('ALTER TABLE notifications_globales ADD COLUMN digest_sent TINYINT(1) NOT NULL DEFAULT 0'); }
            if (!$this->col($pdo, 'notifications_globales', 'acteur')) { $pdo->exec('ALTER TABLE notifications_globales ADD COLUMN acteur VARCHAR(120) NULL'); }
            if (!$this->idx($pdo, 'notifications_globales', 'idx_notif_inbox')) {
                $pdo->exec('ALTER TABLE notifications_globales ADD INDEX idx_notif_inbox (user_id, user_type, etablissement_id, lu, date_creation)');
            }
            if (!$this->idx($pdo, 'notifications_globales', 'idx_notif_group')) {
                $pdo->exec('ALTER TABLE notifications_globales ADD INDEX idx_notif_group (user_id, user_type, group_key, lu)');
            }
        }
        if ($this->table($pdo, 'conversations') && !$this->idx($pdo, 'conversations', 'idx_conv_updated')) {
            $pdo->exec('ALTER TABLE conversations ADD INDEX idx_conv_updated (updated_at, id)');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS cookie_consents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            etablissement_id INT NULL,
            consent_id CHAR(32) NOT NULL COMMENT 'identifiant aléatoire stocké dans le cookie du visiteur',
            user_id INT NULL, user_type VARCHAR(30) NULL,
            preferences TINYINT(1) NOT NULL DEFAULT 0,
            analytics TINYINT(1) NOT NULL DEFAULT 0,
            policy_version VARCHAR(20) NOT NULL,
            action VARCHAR(20) NOT NULL COMMENT 'accept_all | reject_all | custom | withdraw',
            ip_hash CHAR(16) NULL COMMENT 'empreinte tronquée et salée, jamais l IP',
            user_agent VARCHAR(160) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cc_consent (consent_id), KEY idx_cc_etab_date (etablissement_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audience_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            etablissement_id INT NULL,
            day DATE NOT NULL, hour TINYINT NOT NULL, weekday TINYINT NOT NULL,
            path VARCHAR(160) NOT NULL COMMENT 'chemin normalisé, sans paramètres ni identifiants',
            module VARCHAR(60) NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'anonyme',
            device ENUM('mobile','tablette','ordinateur') NOT NULL DEFAULT 'ordinateur',
            visitor CHAR(16) NOT NULL COMMENT 'empreinte quotidienne non réversible (change chaque jour)',
            duration SMALLINT NULL COMMENT 'secondes passées sur la page (plafonné)',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ae_etab_day (etablissement_id, day), KEY idx_ae_module (etablissement_id, module, day), KEY idx_ae_visitor (etablissement_id, day, visitor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
