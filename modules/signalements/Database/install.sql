-- Module signalements — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `signalements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `auteur_id` int(11) NOT NULL,
  `auteur_type` varchar(20) NOT NULL,
  `type` enum('harcelement','violence','discrimination','danger','autre') NOT NULL DEFAULT 'autre',
  `description` text NOT NULL,
  `personnes_impliquees` text DEFAULT NULL,
  `lieu` varchar(255) DEFAULT NULL,
  `date_faits` date DEFAULT NULL,
  `anonyme` tinyint(1) NOT NULL DEFAULT 0,
  `urgence` enum('basse','moyenne','haute','critique') NOT NULL DEFAULT 'moyenne',
  `statut` enum('nouveau','en_cours','traite','clos','escalade') NOT NULL DEFAULT 'nouveau',
  `traite_par` int(11) DEFAULT NULL,
  `actions_prises` text DEFAULT NULL,
  `suivi` text DEFAULT NULL,
  `confidentiel` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signal_auteur` (`auteur_id`, `auteur_type`),
  KEY `idx_signal_type` (`type`),
  KEY `idx_signal_statut` (`statut`),
  KEY `idx_signal_urgence` (`urgence`),
  KEY `idx_signal_date` (`date_creation`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_signalements_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
