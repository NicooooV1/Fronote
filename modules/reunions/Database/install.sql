-- Module reunions — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `reunions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('parents_profs','conseil_classe','reunion_equipe','individuel','autre') NOT NULL DEFAULT 'parents_profs',
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NOT NULL,
  `lieu` varchar(255) DEFAULT NULL,
  `classe_id` int(11) DEFAULT NULL,
  `organisateur_id` int(11) NOT NULL,
  `organisateur_type` varchar(20) NOT NULL,
  `statut` enum('planifiee','en_cours','terminee','annulee') NOT NULL DEFAULT 'planifiee',
  `pv_contenu` text DEFAULT NULL COMMENT 'Procès-verbal de la réunion',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reunion_date` (`date_debut`),
  KEY `idx_reunion_type` (`type`),
  KEY `idx_reunion_classe` (`classe_id`),
  KEY `idx_reunion_statut` (`statut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_reunions_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reunion_creneaux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reunion_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `duree_minutes` int(11) NOT NULL DEFAULT 15,
  `salle` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_creneau_reunion` (`reunion_id`),
  KEY `idx_creneau_prof` (`professeur_id`),
  CONSTRAINT `fk_creneau_reunion` FOREIGN KEY (`reunion_id`) REFERENCES `reunions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_creneau_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reunion_reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `creneau_id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `statut` enum('confirmee','annulee','en_attente') NOT NULL DEFAULT 'confirmee',
  `commentaire` text DEFAULT NULL,
  `date_reservation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reservation` (`creneau_id`),
  KEY `idx_resa_parent` (`parent_id`),
  KEY `idx_resa_eleve` (`eleve_id`),
  CONSTRAINT `fk_resa_creneau` FOREIGN KEY (`creneau_id`) REFERENCES `reunion_creneaux` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resa_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resa_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `convocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `reunion_id` int(11) DEFAULT NULL,
  `destinataire_id` int(11) NOT NULL,
  `destinataire_type` varchar(20) NOT NULL,
  `objet` varchar(255) NOT NULL,
  `contenu` text DEFAULT NULL,
  `date_convocation` date NOT NULL,
  `heure` time DEFAULT NULL,
  `lieu` varchar(255) DEFAULT NULL,
  `type` enum('reunion','conseil','disciplinaire','information','autre') NOT NULL DEFAULT 'reunion',
  `envoyee` tinyint(1) NOT NULL DEFAULT 0,
  `lue` tinyint(1) NOT NULL DEFAULT 0,
  `date_lecture` datetime DEFAULT NULL,
  `emetteur_id` int(11) NOT NULL,
  `emetteur_type` varchar(20) NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_dest` (`destinataire_id`, `destinataire_type`),
  KEY `idx_conv_reunion` (`reunion_id`),
  KEY `idx_conv_date` (`date_convocation`),
  CONSTRAINT `fk_conv_reunion` FOREIGN KEY (`reunion_id`) REFERENCES `reunions` (`id`) ON DELETE SET NULL,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_convocations_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
