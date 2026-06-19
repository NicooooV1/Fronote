-- Module ressources — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `ressources_pedagogiques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('exercice','cours','video','document','lien','qcm') NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `classe_id` int(11) DEFAULT NULL,
  `auteur_id` int(11) NOT NULL,
  `contenu` longtext DEFAULT NULL COMMENT 'contenu HTML ou JSON du QCM',
  `fichier_path` varchar(500) DEFAULT NULL,
  `url_externe` varchar(500) DEFAULT NULL,
  `difficulte` enum('facile','moyen','difficile') DEFAULT 'moyen',
  `niveau` varchar(20) DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `publie` tinyint(1) NOT NULL DEFAULT 0,
  `vues` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_matiere` (`matiere_id`),
  KEY `idx_type` (`type`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_ress_peda_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
