-- Module parcours_educatifs — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `parcours_educatifs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `type_parcours` enum('avenir','sante','citoyen','PEAC') NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `date_activite` date NOT NULL,
  `competences_visees` text DEFAULT NULL,
  `validation` enum('non_valide','en_cours','valide') NOT NULL DEFAULT 'non_valide',
  `valide_par` int(11) DEFAULT NULL,
  `pieces_jointes` text DEFAULT NULL COMMENT 'JSON array de fichiers',
  `annee_scolaire` varchar(9) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_type` (`type_parcours`),
  KEY `idx_annee` (`annee_scolaire`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_parcours_educ_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `parcours_educatifs_modeles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_parcours` enum('avenir','sante','citoyen','PEAC') NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `niveau` varchar(50) DEFAULT NULL COMMENT 'niveau scolaire cible',
  `competences` text DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
