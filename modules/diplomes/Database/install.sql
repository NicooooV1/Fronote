-- Module diplomes — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `diplomes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `intitule` varchar(255) NOT NULL,
  `type` enum('brevet','bac','bts','licence','master','autre') NOT NULL,
  `mention` enum('sans','AB','B','TB','felicitations') DEFAULT NULL,
  `date_obtention` date NOT NULL,
  `numero_diplome` varchar(100) DEFAULT NULL,
  `fichier_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_diplomes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
