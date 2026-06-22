-- Module examens — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `examens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `type` enum('brevet','bac','bts','partiel','controle','autre') NOT NULL DEFAULT 'autre',
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `statut` enum('planifie','en_cours','termine','annule') NOT NULL DEFAULT 'planifie',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`date_debut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_examens_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `epreuves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `examen_id` int(11) NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `intitule` varchar(255) NOT NULL,
  `date_epreuve` datetime NOT NULL,
  `duree_minutes` int(11) NOT NULL DEFAULT 120,
  `salle_id` int(11) DEFAULT NULL,
  `coefficient` decimal(4,2) DEFAULT 1.00,
  `type` enum('ecrit','oral','pratique','tp') NOT NULL DEFAULT 'ecrit',
  `consignes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_examen` (`examen_id`),
  CONSTRAINT `fk_epreuve_examen` FOREIGN KEY (`examen_id`) REFERENCES `examens` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_epreuves_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `epreuve_surveillants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `epreuve_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `role` enum('surveillant','responsable') NOT NULL DEFAULT 'surveillant',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_epreuve_prof` (`epreuve_id`, `professeur_id`),
  CONSTRAINT `fk_esurv_epreuve` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `epreuve_convocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `epreuve_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `place` varchar(50) DEFAULT NULL COMMENT 'numéro de place',
  `present` tinyint(1) DEFAULT NULL,
  `note` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_epreuve_eleve` (`epreuve_id`, `eleve_id`),
  CONSTRAINT `fk_econvoc_epreuve` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] examen_places
CREATE TABLE IF NOT EXISTS `examen_places` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `etablissement_id` INT NULL DEFAULT 1,
  `epreuve_id` INT NOT NULL,
  `convocation_id` INT NOT NULL,
  `numero_place` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_epreuve_convocation` (`epreuve_id`, `convocation_id`),
  KEY `idx_epreuve` (`epreuve_id`),
  KEY `idx_convocation` (`convocation_id`),
  KEY `idx_etablissement` (`etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
