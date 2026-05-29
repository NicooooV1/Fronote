-- Module bourses — schéma SQL (types, demandes, documents, versements, fonds sociaux).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, eleves, parents) présentes avant activation.

CREATE TABLE IF NOT EXISTS `bourses_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `code` varchar(50) NOT NULL,
  `libelle` varchar(150) NOT NULL,
  `echelon_min` int(11) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_bourses_types_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bourses_demandes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) DEFAULT NULL,
  `revenu_fiscal` decimal(12,2) NOT NULL DEFAULT 0,
  `nb_enfants` int(11) NOT NULL DEFAULT 1,
  `echelon_simule` int(11) NOT NULL DEFAULT 0,
  `montant_simule` decimal(10,2) NOT NULL DEFAULT 0,
  `echelon_final` int(11) NOT NULL DEFAULT 0,
  `montant_final` decimal(10,2) NOT NULL DEFAULT 0,
  `statut` varchar(30) NOT NULL DEFAULT 'brouillon',
  `instructeur_id` int(11) DEFAULT NULL,
  `commentaire_instruction` text DEFAULT NULL,
  `date_soumission` datetime DEFAULT NULL,
  `date_instruction` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_bourses_dem_eleve` (`eleve_id`),
  KEY `idx_bourses_dem_parent` (`parent_id`),
  KEY `idx_bourses_dem_type` (`type_id`),
  KEY `idx_bourses_dem_statut` (`statut`),
  CONSTRAINT `fk_bourses_dem_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bourses_dem_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bourses_dem_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bourses_dem_type` FOREIGN KEY (`type_id`) REFERENCES `bourses_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bourses_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `demande_id` int(11) NOT NULL,
  `type_document` varchar(80) NOT NULL,
  `fichier_path` varchar(255) NOT NULL,
  `nom_original` varchar(255) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bourses_doc_dem` (`demande_id`),
  CONSTRAINT `fk_bourses_doc_dem` FOREIGN KEY (`demande_id`) REFERENCES `bourses_demandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bourses_versements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `demande_id` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL DEFAULT 0,
  `date_versement` date DEFAULT NULL,
  `trimestre` varchar(20) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'planifie',
  `date_effectif` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bourses_vers_dem` (`demande_id`),
  CONSTRAINT `fk_bourses_vers_dem` FOREIGN KEY (`demande_id`) REFERENCES `bourses_demandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fonds_sociaux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `motif` text DEFAULT NULL,
  `montant_demande` decimal(10,2) NOT NULL DEFAULT 0,
  `montant_accorde` decimal(10,2) NOT NULL DEFAULT 0,
  `statut` varchar(30) NOT NULL DEFAULT 'soumise',
  `commentaire` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_fonds_eleve` (`eleve_id`),
  KEY `idx_fonds_parent` (`parent_id`),
  CONSTRAINT `fk_fonds_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fonds_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fonds_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Types de bourses nationales (référence). INSERT IGNORE = idempotent.
INSERT IGNORE INTO `bourses_types` (`id`, `etablissement_id`, `code`, `libelle`, `echelon_min`, `actif`) VALUES
  (1, 1, 'COLLEGE', 'Bourse nationale de collège', 1, 1),
  (2, 1, 'LYCEE', 'Bourse nationale de lycée', 1, 1),
  (3, 1, 'FONDS_SOCIAL', 'Fonds social (aide ponctuelle)', NULL, 1);
