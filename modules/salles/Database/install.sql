-- Module salles — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `salles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(50) NOT NULL,
  `batiment` varchar(100) DEFAULT NULL,
  `capacite` int(11) DEFAULT NULL,
  `type` varchar(50) DEFAULT 'standard' COMMENT 'standard, labo, gymnase, info, amphi',
  `equipements` text DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_salles_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservations_salles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `salle_id` int(11) NOT NULL,
  `reserveur_id` int(11) NOT NULL,
  `reserveur_type` enum('professeur','vie_scolaire','administrateur') NOT NULL,
  `objet` varchar(255) NOT NULL,
  `date_reservation` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `statut` enum('en_attente','confirmee','annulee') NOT NULL DEFAULT 'en_attente',
  `recurrence` enum('aucune','hebdomadaire','mensuelle') DEFAULT 'aucune',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_salle_date` (`salle_id`, `date_reservation`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_resa_salles_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `materiels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `categorie` enum('informatique','science','sport','audiovisuel','mobilier','autre') NOT NULL DEFAULT 'autre',
  `reference` varchar(100) DEFAULT NULL,
  `etat` enum('neuf','bon','usage','en_panne','reforme') NOT NULL DEFAULT 'bon',
  `salle_id` int(11) DEFAULT NULL COMMENT 'salle de stockage',
  `quantite` int(11) NOT NULL DEFAULT 1,
  `date_acquisition` date DEFAULT NULL,
  `valeur` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categorie` (`categorie`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_materiels_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prets_materiels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `materiel_id` int(11) NOT NULL,
  `emprunteur_id` int(11) NOT NULL,
  `emprunteur_type` enum('professeur','eleve','vie_scolaire') NOT NULL,
  `quantite` int(11) NOT NULL DEFAULT 1,
  `date_pret` date NOT NULL,
  `date_retour_prevue` date NOT NULL,
  `date_retour_effective` date DEFAULT NULL,
  `etat_retour` varchar(255) DEFAULT NULL,
  `statut` enum('en_cours','retourne','en_retard') NOT NULL DEFAULT 'en_cours',
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  CONSTRAINT `fk_pret_materiel` FOREIGN KEY (`materiel_id`) REFERENCES `materiels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
