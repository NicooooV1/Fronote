-- Module clubs — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `clubs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `categorie` varchar(100) DEFAULT NULL COMMENT 'sport, culture, sciences, art, autre',
  `responsable_id` int(11) DEFAULT NULL,
  `responsable_type` varchar(20) DEFAULT NULL,
  `jour` varchar(20) DEFAULT NULL,
  `horaire_debut` time DEFAULT NULL,
  `horaire_fin` time DEFAULT NULL,
  `horaires` varchar(255) DEFAULT NULL COMMENT 'Horaires en texte libre (saisis via l-UI creer/modifier)',
  `lieu` varchar(255) DEFAULT NULL,
  `places_max` int(11) DEFAULT NULL,
  `places_restantes` int(11) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_club_categorie` (`categorie`),
  KEY `idx_club_actif` (`actif`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_clubs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `club_inscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `club_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `statut` enum('inscrit','en_attente','refuse','desiste') NOT NULL DEFAULT 'inscrit',
  `date_inscription` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_club_eleve` (`club_id`, `eleve_id`),
  KEY `idx_clubinsc_eleve` (`eleve_id`),
  CONSTRAINT `fk_clubinsc_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_clubinsc_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_club_insc_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] club_seances
CREATE TABLE IF NOT EXISTS `club_seances` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `club_id` INT NOT NULL,
  `date_seance` DATE NOT NULL,
  `heure_debut` TIME DEFAULT NULL,
  `heure_fin` TIME DEFAULT NULL,
  `lieu` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clubseance_club` (`club_id`),
  KEY `idx_clubseance_date` (`date_seance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] club_seances_presences
CREATE TABLE IF NOT EXISTS `club_seances_presences` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `seance_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `present` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seance_eleve` (`seance_id`, `eleve_id`),
  KEY `idx_clubpres_eleve` (`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] club_budget
CREATE TABLE IF NOT EXISTS `club_budget` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `club_id` INT NOT NULL,
  `libelle` VARCHAR(255) NOT NULL,
  `montant` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `type` VARCHAR(20) NOT NULL DEFAULT 'depense',
  `date_operation` DATE NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clubbudget_club` (`club_id`),
  KEY `idx_clubbudget_date` (`date_operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] club_photos
CREATE TABLE IF NOT EXISTS `club_photos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `club_id` INT NOT NULL,
  `chemin` VARCHAR(512) NOT NULL,
  `legende` VARCHAR(255) DEFAULT NULL,
  `seance_id` INT DEFAULT NULL,
  `uploaded_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clubphoto_club` (`club_id`),
  KEY `idx_clubphoto_seance` (`seance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] club_waitlist
CREATE TABLE IF NOT EXISTS `club_waitlist` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `club_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_waitlist_club_eleve` (`club_id`, `eleve_id`),
  KEY `idx_waitlist_club_pos` (`club_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
