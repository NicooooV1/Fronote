-- Module internat — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `internat_chambres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `numero` varchar(20) NOT NULL,
  `batiment` varchar(100) DEFAULT NULL,
  `etage` int(11) DEFAULT NULL,
  `capacite` int(11) NOT NULL DEFAULT 2,
  `type` enum('simple','double','triple','dortoir') NOT NULL DEFAULT 'double',
  `equipements` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_internat_ch_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internat_affectations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `chambre_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` enum('actif','termine') NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eleve_annee` (`eleve_id`, `annee_scolaire`),
  CONSTRAINT `fk_intaffect_chambre` FOREIGN KEY (`chambre_id`) REFERENCES `internat_chambres` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_internat_aff_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internat_reglement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `eleve_id` int(11) NOT NULL,
  `chambre_id` int(11) NOT NULL,
  `type` enum('entree','sortie','absence','retard') NOT NULL,
  `date_heure` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motif` varchar(255) DEFAULT NULL,
  `signale_par` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_chambre` (`chambre_id`),
  CONSTRAINT `fk_intreg_chambre` FOREIGN KEY (`chambre_id`) REFERENCES `internat_chambres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internat_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chambre_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) DEFAULT NULL,
  `type` enum('bruit','degradation','absence','conflit','autre') NOT NULL DEFAULT 'autre',
  `description` text NOT NULL,
  `gravite` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=mineur, 2=moyen, 3=grave',
  `date_incident` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `traite` tinyint(1) NOT NULL DEFAULT 0,
  `traite_par` int(11) DEFAULT NULL,
  `suite_donnee` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chambre` (`chambre_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] internat_inspections
CREATE TABLE IF NOT EXISTS `internat_inspections` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `chambre_id` INT NOT NULL,
  `inspecteur_id` INT DEFAULT NULL,
  `proprete` TINYINT DEFAULT NULL,
  `rangement` TINYINT DEFAULT NULL,
  `equipement` TINYINT DEFAULT NULL,
  `note_globale` DECIMAL(4,1) DEFAULT NULL,
  `commentaire` TEXT DEFAULT NULL,
  `date_inspection` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inspect_chambre` (`chambre_id`),
  KEY `idx_inspect_inspecteur` (`inspecteur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] internat_appels_soir
CREATE TABLE IF NOT EXISTS `internat_appels_soir` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `affectation_id` INT NOT NULL,
  `date_appel` DATE NOT NULL,
  `present` TINYINT NOT NULL DEFAULT 1,
  `motif_absence` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_affectation_date` (`affectation_id`, `date_appel`),
  KEY `idx_appelsoir_date` (`date_appel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] internat_autorisations_sortie
CREATE TABLE IF NOT EXISTS `internat_autorisations_sortie` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `eleve_id` INT NOT NULL,
  `date_debut` DATETIME NOT NULL,
  `date_fin` DATETIME NOT NULL,
  `motif` VARCHAR(255) DEFAULT NULL,
  `autorise_par` INT DEFAULT NULL,
  `statut` VARCHAR(20) NOT NULL DEFAULT 'en_attente',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_autosortie_eleve` (`eleve_id`),
  KEY `idx_autosortie_statut` (`statut`),
  KEY `idx_autosortie_debut` (`date_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] internat_activites_weekend
CREATE TABLE IF NOT EXISTS `internat_activites_weekend` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `titre` VARCHAR(255) NOT NULL,
  `date_activite` DATE NOT NULL,
  `description` TEXT DEFAULT NULL,
  `responsable_id` INT DEFAULT NULL,
  `places_max` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_actwe_date` (`date_activite`),
  KEY `idx_actwe_resp` (`responsable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] internat_activites_inscriptions
CREATE TABLE IF NOT EXISTS `internat_activites_inscriptions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `activite_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `inscrit_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_activite_eleve` (`activite_id`, `eleve_id`),
  KEY `idx_actinsc_eleve` (`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
