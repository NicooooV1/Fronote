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
