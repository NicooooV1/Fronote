-- Module notes — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `id_eleve` int(11) NOT NULL,
  `id_matiere` int(11) NOT NULL,
  `id_professeur` int(11) NOT NULL,
  `note` decimal(4,2) NOT NULL,
  `note_sur` decimal(4,2) NOT NULL DEFAULT 20.00,
  `coefficient` decimal(3,2) NOT NULL DEFAULT 1.00,
  `type_evaluation` varchar(50) DEFAULT 'Contrôle',
  `date_note` date NOT NULL,
  `commentaire` text DEFAULT NULL,
  `trimestre` int(1) NOT NULL DEFAULT 1,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eleve_matiere` (`id_eleve`, `id_matiere`),
  KEY `idx_matiere` (`id_matiere`),
  KEY `idx_professeur` (`id_professeur`),
  KEY `idx_date` (`date_note`),
  KEY `idx_trimestre` (`trimestre`),
  KEY `idx_eleve_matiere_trimestre` (`id_eleve`, `id_matiere`, `trimestre`),
  KEY `idx_prof_trimestre` (`id_professeur`, `trimestre`),
  CONSTRAINT `fk_notes_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_matiere` FOREIGN KEY (`id_matiere`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_prof` FOREIGN KEY (`id_professeur`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_notes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] notes_verrous
CREATE TABLE IF NOT EXISTS `notes_verrous` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `etablissement_id` INT NULL,
  `matiere_id` INT NOT NULL,
  `trimestre` INT NOT NULL,
  `classe` VARCHAR(50) NOT NULL,
  `verrouille_par` INT NULL,
  `date_verrouillage` DATETIME NULL,
  UNIQUE KEY `uq_verrou` (`matiere_id`,`trimestre`,`classe`,`etablissement_id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_lookup` (`matiere_id`,`classe`,`trimestre`,`etablissement_id`),
  CONSTRAINT `fk_etab_notes_verrous` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] notes_config_ponderation
CREATE TABLE IF NOT EXISTS `notes_config_ponderation` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `etablissement_id` INT NULL,
  `matiere_id` INT NOT NULL,
  `type_evaluation` VARCHAR(100) NOT NULL,
  `poids` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  UNIQUE KEY `uq_ponderation` (`matiere_id`,`etablissement_id`,`type_evaluation`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_matiere` (`matiere_id`,`etablissement_id`),
  CONSTRAINT `fk_etab_notes_config_ponderation` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] note_history
CREATE TABLE IF NOT EXISTS `note_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `note_id` INT NOT NULL,
  `note_value` DECIMAL(6,2) NULL,
  `note_sur` DECIMAL(6,2) NULL,
  `coefficient` DECIMAL(5,2) NULL,
  `commentaire` TEXT NULL,
  `modified_by` INT NULL,
  `modified_at` DATETIME NULL,
  `reason` VARCHAR(255) NULL,
  KEY `idx_note` (`note_id`),
  KEY `idx_modified_at` (`modified_at`),
  KEY `idx_modified_by` (`modified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
