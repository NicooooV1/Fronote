<?php
declare(strict_types=1);
/**
 * 4.5 — Support : demandes internes vs demandes escaladées à la plateforme Fronote.
 *  - tickets_support.categorie : + « communication » (écrire à l'établissement, utile sans messagerie)
 *  - support_escalations : demandes transmises par l'équipe de direction à la plateforme
 *    (file d'attente : envoyées dès que la plateforme répond, avec nouvel essai progressif)
 *  - support_escalation_messages : fil avec la plateforme (sortant en file / entrant relevé)
 *  - support_platform_state : curseur de relève des réponses
 * Idempotente.
 */
return new class {
    private function table(\PDO $pdo, string $t): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }

    public function up(\PDO $pdo): void
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        if ($this->table($pdo, 'tickets_support')) {
            $col = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tickets_support' AND column_name = 'categorie'")->fetchColumn();
            if ($col && !str_contains((string) $col, "'communication'")) {
                $pdo->exec("ALTER TABLE tickets_support MODIFY categorie ENUM('technique','pedagogique','communication','administratif','compte','autre') NOT NULL DEFAULT 'communication'");
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_escalations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            etablissement_id INT NOT NULL DEFAULT 1,
            ticket_id INT NULL,
            ref VARCHAR(64) NOT NULL,
            sujet VARCHAR(255) NOT NULL,
            categorie VARCHAR(40) NOT NULL DEFAULT 'technique',
            priorite ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
            envoi ENUM('a_envoyer','envoye','refuse') NOT NULL DEFAULT 'a_envoyer',
            statut VARCHAR(30) NOT NULL DEFAULT 'en_attente',
            tentatives INT NOT NULL DEFAULT 0,
            prochain_essai DATETIME NULL,
            derniere_erreur VARCHAR(255) NULL,
            cree_par_id INT NOT NULL,
            cree_par_type VARCHAR(20) NOT NULL,
            cree_par_nom VARCHAR(160) NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_escalade_ref (ref),
            KEY idx_escalade_etab (etablissement_id),
            KEY idx_escalade_envoi (envoi, prochain_essai),
            KEY idx_escalade_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_escalation_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escalation_id INT NOT NULL,
            sens ENUM('sortant','entrant') NOT NULL,
            auteur VARCHAR(160) NOT NULL,
            contenu TEXT NOT NULL,
            a_envoyer TINYINT(1) NOT NULL DEFAULT 0,
            plateforme_id INT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_escmsg_escalade (escalation_id),
            UNIQUE KEY uk_escmsg_plateforme (escalation_id, plateforme_id),
            CONSTRAINT fk_escmsg_escalade FOREIGN KEY (escalation_id) REFERENCES support_escalations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_platform_state (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
