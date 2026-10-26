-- Module support — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `tickets_support` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `sujet` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `categorie` enum('technique','pedagogique','communication','administratif','compte','autre') NOT NULL DEFAULT 'communication',
  `priorite` enum('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  `statut` enum('ouvert','en_cours','resolu','ferme') NOT NULL DEFAULT 'ouvert',
  `reponse` text DEFAULT NULL,
  `traite_par` int(11) DEFAULT NULL,
  `date_reponse` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `first_response_at` datetime DEFAULT NULL,
  `satisfaction_note` tinyint(1) DEFAULT NULL,
  `satisfaction_commentaire` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_user` (`user_id`, `user_type`),
  KEY `idx_ticket_statut` (`statut`),
  KEY `idx_ticket_priorite` (`priorite`),
  KEY `idx_ticket_date` (`date_creation`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_tickets_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faq_articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `question` varchar(500) NOT NULL,
  `reponse` text NOT NULL,
  `categorie` varchar(100) NOT NULL DEFAULT 'general',
  `ordre` int(11) NOT NULL DEFAULT 0,
  `vues` int(11) NOT NULL DEFAULT 0,
  `utile_oui` int(11) NOT NULL DEFAULT 0,
  `utile_non` int(11) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `auteur_id` int(11) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faq_categorie` (`categorie`),
  KEY `idx_faq_actif` (`actif`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_faq_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fil de discussion d'un ticket (conversation multi-messages).
CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `auteur_id` int(11) NOT NULL,
  `auteur_type` varchar(20) NOT NULL,
  `contenu` text NOT NULL,
  `is_staff` tinyint(1) NOT NULL DEFAULT 0,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supportmsg_ticket` (`ticket_id`),
  CONSTRAINT `fk_supportmsg_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets_support` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes internes (visibles staff uniquement).
CREATE TABLE IF NOT EXISTS `support_notes_internes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `contenu` text NOT NULL,
  `auteur_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supportni_ticket` (`ticket_id`),
  CONSTRAINT `fk_supportni_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets_support` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réponses types (modèles réutilisables).
CREATE TABLE IF NOT EXISTS `support_reponses_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `categorie` varchar(50) DEFAULT NULL,
  `titre` varchar(255) NOT NULL,
  `contenu` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_supportrt_etab` (`etablissement_id`),
  CONSTRAINT `fk_etab_support_reponses_types` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLA par catégorie/priorité (objectifs de délai, en heures).
CREATE TABLE IF NOT EXISTS `support_sla` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `categorie` varchar(50) NOT NULL,
  `priorite` varchar(20) NOT NULL DEFAULT 'normale',
  `first_response_hours` int(11) NOT NULL DEFAULT 24,
  `resolution_hours` int(11) NOT NULL DEFAULT 72,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_support_sla` (`etablissement_id`, `categorie`, `priorite`),
  CONSTRAINT `fk_etab_support_sla` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demandes ESCALADÉES à la plateforme Fronote par l'équipe de direction (4.5).
-- File d'attente : envoyées dès que la plateforme répond (nouvel essai progressif).
CREATE TABLE IF NOT EXISTS `support_escalations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `ticket_id` int(11) DEFAULT NULL,
  `ref` varchar(64) NOT NULL,
  `sujet` varchar(255) NOT NULL,
  `categorie` varchar(40) NOT NULL DEFAULT 'technique',
  `priorite` enum('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  `envoi` enum('a_envoyer','envoye','refuse') NOT NULL DEFAULT 'a_envoyer',
  `statut` varchar(30) NOT NULL DEFAULT 'en_attente',
  `tentatives` int(11) NOT NULL DEFAULT 0,
  `prochain_essai` datetime DEFAULT NULL,
  `derniere_erreur` varchar(255) DEFAULT NULL,
  `cree_par_id` int(11) NOT NULL,
  `cree_par_type` varchar(20) NOT NULL,
  `cree_par_nom` varchar(160) NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_escalade_ref` (`ref`),
  KEY `idx_escalade_etab` (`etablissement_id`),
  KEY `idx_escalade_envoi` (`envoi`, `prochain_essai`),
  KEY `idx_escalade_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_escalation_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escalation_id` int(11) NOT NULL,
  `sens` enum('sortant','entrant') NOT NULL,
  `auteur` varchar(160) NOT NULL,
  `contenu` text NOT NULL,
  `a_envoyer` tinyint(1) NOT NULL DEFAULT 0,
  `plateforme_id` int(11) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_escmsg_escalade` (`escalation_id`),
  UNIQUE KEY `uk_escmsg_plateforme` (`escalation_id`, `plateforme_id`),
  CONSTRAINT `fk_escmsg_escalade` FOREIGN KEY (`escalation_id`) REFERENCES `support_escalations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_platform_state` (
  `k` varchar(64) NOT NULL,
  `v` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
