-- Module enquetes — schéma SQL (enquêtes, pages, questions, participations, réponses).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements). creee_par/user_id NON FK (multi-type d'utilisateur).

CREATE TABLE IF NOT EXISTS `enquetes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'custom',
  `cible_roles` json DEFAULT NULL,
  `cible_classes` json DEFAULT NULL,
  `anonyme` tinyint(1) NOT NULL DEFAULT 0,
  `date_ouverture` datetime DEFAULT NULL,
  `date_fermeture` datetime DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'brouillon',
  `creee_par` int(11) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_enq_statut` (`statut`),
  CONSTRAINT `fk_enq_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enquete_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enquete_id` int(11) NOT NULL,
  `titre` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_enq_page_enq` (`enquete_id`),
  CONSTRAINT `fk_enq_page_enq` FOREIGN KEY (`enquete_id`) REFERENCES `enquetes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enquete_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL,
  `enonce` text NOT NULL,
  `obligatoire` tinyint(1) NOT NULL DEFAULT 0,
  `options` json DEFAULT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_enq_q_page` (`page_id`),
  CONSTRAINT `fk_enq_q_page` FOREIGN KEY (`page_id`) REFERENCES `enquete_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enquete_participations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enquete_id` int(11) NOT NULL,
  `participant_hash` varchar(64) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_type` varchar(30) DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT 0,
  `date_soumission` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enq_part_enq` (`enquete_id`),
  KEY `idx_enq_part_user` (`user_id`, `user_type`),
  CONSTRAINT `fk_enq_part_enq` FOREIGN KEY (`enquete_id`) REFERENCES `enquetes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enquete_reponses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `valeur_texte` text DEFAULT NULL,
  `valeur_numero` decimal(10,2) DEFAULT NULL,
  `valeur_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enq_rep_part` (`participation_id`),
  KEY `idx_enq_rep_q` (`question_id`),
  CONSTRAINT `fk_enq_rep_part` FOREIGN KEY (`participation_id`) REFERENCES `enquete_participations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enq_rep_q` FOREIGN KEY (`question_id`) REFERENCES `enquete_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
