-- Module cantine — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `menus_cantine` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `date_menu` date NOT NULL,
  `entree` varchar(255) DEFAULT NULL,
  `plat_principal` varchar(255) DEFAULT NULL,
  `accompagnement` varchar(255) DEFAULT NULL,
  `dessert` varchar(255) DEFAULT NULL,
  `allergenes` text DEFAULT NULL,
  `regime_special` varchar(100) DEFAULT NULL COMMENT 'végétarien, sans porc, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date` (`date_menu`, `regime_special`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_menus_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cantine_reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `date_repas` date NOT NULL,
  `type_repas` enum('dejeuner','gouter') NOT NULL DEFAULT 'dejeuner',
  `regime` varchar(50) DEFAULT NULL COMMENT 'normal, végétarien, sans porc, sans gluten, halal',
  `allergenes_declares` text DEFAULT NULL,
  `statut` enum('reserve','annule','consomme') NOT NULL DEFAULT 'reserve',
  `reserve_par` varchar(50) DEFAULT NULL COMMENT 'eleve, parent, admin',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eleve_date_type` (`eleve_id`, `date_repas`, `type_repas`),
  KEY `idx_date` (`date_repas`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_cantine_resa_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cantine_pointage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `heure_passage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pointe_par` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reservation` (`reservation_id`),
  CONSTRAINT `fk_cantpoint_reserv` FOREIGN KEY (`reservation_id`) REFERENCES `cantine_reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cantine_tarifs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `tranche` varchar(100) NOT NULL COMMENT 'Tranche QF ou catégorie',
  `tarif_repas` decimal(6,2) NOT NULL,
  `type_repas` enum('dejeuner','gouter') NOT NULL DEFAULT 'dejeuner',
  `annee_scolaire` varchar(9) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_cantine_tarifs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] cantine_evaluations
CREATE TABLE IF NOT EXISTS `cantine_evaluations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `etablissement_id` INT NULL DEFAULT 1,
  `menu_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `note` TINYINT NOT NULL,
  `commentaire` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_menu_eleve` (`menu_id`, `eleve_id`),
  KEY `idx_menu` (`menu_id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_etablissement` (`etablissement_id`),
  CONSTRAINT `fk_etab_cantine_evaluations` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] cantine_gaspillage
CREATE TABLE IF NOT EXISTS `cantine_gaspillage` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `etablissement_id` INT NULL DEFAULT 1,
  `date_mesure` DATE NOT NULL,
  `quantite_kg` DECIMAL(8,2) NOT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'preparation',
  `commentaire` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date_mesure` (`date_mesure`),
  KEY `idx_type` (`type`),
  KEY `idx_etablissement` (`etablissement_id`),
  CONSTRAINT `fk_etab_cantine_gaspillage` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
