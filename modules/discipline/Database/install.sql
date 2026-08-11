-- Module discipline — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `date_incident` datetime NOT NULL,
  `lieu` varchar(100) DEFAULT NULL,
  `type_incident` varchar(50) NOT NULL COMMENT 'violence, insolence, fraude, retard_repete, autre',
  `gravite` enum('mineur','moyen','grave','tres_grave') NOT NULL DEFAULT 'moyen',
  `description` text NOT NULL,
  `temoins` text DEFAULT NULL,
  `signale_par_id` int(11) NOT NULL,
  `signale_par_type` varchar(20) NOT NULL,
  `classe_id` int(11) DEFAULT NULL,
  `statut` enum('signale','en_traitement','traite','classe') NOT NULL DEFAULT 'signale',
  `traite_par_id` int(11) DEFAULT NULL COMMENT 'utilisateur ayant traité l-incident',
  `traite_at` datetime DEFAULT NULL COMMENT 'horodatage du traitement',
  `commentaire_traitement` text DEFAULT NULL COMMENT 'observations/décisions lors du traitement',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incident_eleve` (`eleve_id`),
  KEY `idx_incident_date` (`date_incident`),
  KEY `idx_incident_statut` (`statut`),
  CONSTRAINT `fk_incident_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_incidents_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sanctions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `incident_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) NOT NULL,
  `type_sanction` varchar(50) NOT NULL COMMENT 'avertissement, blame, exclusion_cours, exclusion_temporaire, retenue, autre',
  `motif` text NOT NULL,
  `date_sanction` date NOT NULL,
  `date_debut` datetime DEFAULT NULL COMMENT 'pour exclusion',
  `date_fin` datetime DEFAULT NULL COMMENT 'pour exclusion',
  `duree` varchar(50) DEFAULT NULL,
  `lieu_retenue` varchar(100) DEFAULT NULL,
  `convocation_parent` tinyint(1) NOT NULL DEFAULT 0,
  `date_convocation` datetime DEFAULT NULL,
  `parent_notifie` tinyint(1) NOT NULL DEFAULT 0,
  `decide_par_id` int(11) NOT NULL,
  `decide_par_type` varchar(20) NOT NULL,
  `commentaire` text DEFAULT NULL,
  `statut` enum('prononcee','executee','annulee') NOT NULL DEFAULT 'prononcee',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sanction_eleve` (`eleve_id`),
  KEY `idx_sanction_incident` (`incident_id`),
  KEY `idx_sanction_date` (`date_sanction`),
  CONSTRAINT `fk_sanction_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sanction_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE SET NULL,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_sanctions_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retenues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `date_retenue` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `lieu` varchar(100) DEFAULT NULL,
  `surveillant_id` int(11) DEFAULT NULL,
  `surveillant_type` varchar(20) DEFAULT NULL,
  `capacite_max` int(11) DEFAULT 30,
  `commentaire` text DEFAULT NULL,
  `statut` enum('planifiee','en_cours','terminee','annulee') NOT NULL DEFAULT 'planifiee',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_retenue_date` (`date_retenue`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_retenues_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retenue_eleves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `retenue_id` int(11) NOT NULL,
  `sanction_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) NOT NULL,
  `present` tinyint(1) DEFAULT NULL COMMENT 'null=non pointé, 0=absent, 1=présent',
  `commentaire` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_retenue_eleve` (`retenue_id`, `eleve_id`),
  CONSTRAINT `fk_re_retenue` FOREIGN KEY (`retenue_id`) REFERENCES `retenues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
