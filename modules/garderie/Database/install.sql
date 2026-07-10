-- Module garderie — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `garderie_creneaux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL COMMENT 'Ex: Garderie matin, Garderie soir, Étude surveillée',
  `type` enum('matin','soir','mercredi','vacances') NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `places_max` int(11) DEFAULT NULL,
  `tarif` decimal(6,2) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_garderie_cren_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `garderie_inscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `creneau_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `jour` enum('lundi','mardi','mercredi','jeudi','vendredi') NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `inscrit_par` varchar(50) DEFAULT NULL COMMENT 'parent, admin',
  `statut` enum('actif','annule') NOT NULL DEFAULT 'actif',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_creneau_eleve_jour` (`creneau_id`, `eleve_id`, `jour`),
  CONSTRAINT `fk_gardeinsc_creneau` FOREIGN KEY (`creneau_id`) REFERENCES `garderie_creneaux` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_garderie_insc_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `garderie_presences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inscription_id` int(11) NOT NULL,
  `date_presence` date NOT NULL,
  `heure_arrivee` time DEFAULT NULL,
  `heure_depart` time DEFAULT NULL,
  `present` tinyint(1) NOT NULL DEFAULT 1,
  `remarques` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_insc_date` (`inscription_id`, `date_presence`),
  CONSTRAINT `fk_gardepres_insc` FOREIGN KEY (`inscription_id`) REFERENCES `garderie_inscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] garderie_activites
CREATE TABLE IF NOT EXISTS `garderie_activites` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `etablissement_id` INT NULL DEFAULT 1,
  `creneau_id` INT NOT NULL,
  `titre` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `date_activite` DATE NOT NULL,
  `animateur` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_creneau` (`creneau_id`),
  KEY `idx_date_activite` (`date_activite`),
  KEY `idx_etablissement` (`etablissement_id`),
  CONSTRAINT `fk_etab_garderie_activites` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
