-- Module documents — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `categorie` varchar(100) NOT NULL DEFAULT 'general' COMMENT 'general, administratif, pedagogique, reglementaire',
  `fichier_nom` varchar(255) NOT NULL,
  `fichier_chemin` varchar(255) NOT NULL,
  `fichier_type` varchar(100) NOT NULL,
  `fichier_taille` int(11) NOT NULL DEFAULT 0,
  `visibilite` varchar(255) DEFAULT NULL COMMENT 'JSON: ["eleve","parent","professeur"]',
  `auteur_id` int(11) NOT NULL,
  `auteur_type` varchar(20) NOT NULL,
  `telechargements` int(11) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_categorie` (`categorie`),
  KEY `idx_doc_auteur` (`auteur_id`, `auteur_type`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_documents_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
