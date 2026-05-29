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
  `categorie` enum('technique','pedagogique','administratif','compte','autre') NOT NULL DEFAULT 'technique',
  `priorite` enum('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  `statut` enum('ouvert','en_cours','resolu','ferme') NOT NULL DEFAULT 'ouvert',
  `reponse` text DEFAULT NULL,
  `traite_par` int(11) DEFAULT NULL,
  `date_reponse` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
