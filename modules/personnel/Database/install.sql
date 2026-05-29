-- Module personnel — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `personnel_absences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `personnel_id` int(11) NOT NULL,
  `personnel_type` enum('professeur','vie_scolaire','administrateur') NOT NULL,
  `type_absence` enum('maladie','formation','personnel','maternite','autre') NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `motif` text DEFAULT NULL,
  `justificatif_path` varchar(500) DEFAULT NULL,
  `statut` enum('declaree','validee','refusee') NOT NULL DEFAULT 'declaree',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_personnel` (`personnel_id`, `personnel_type`),
  KEY `idx_dates` (`date_debut`, `date_fin`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_perso_abs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remplacements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `absence_id` int(11) DEFAULT NULL,
  `professeur_absent_id` int(11) NOT NULL,
  `professeur_remplacant_id` int(11) DEFAULT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `classe_id` int(11) DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` enum('a_pourvoir','pourvu','annule') NOT NULL DEFAULT 'a_pourvoir',
  `commentaire` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  CONSTRAINT `fk_rempl_absence` FOREIGN KEY (`absence_id`) REFERENCES `personnel_absences` (`id`) ON DELETE SET NULL,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_remplacements_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
