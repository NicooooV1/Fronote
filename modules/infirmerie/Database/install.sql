-- Module infirmerie — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `fiches_sante` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `allergies` text DEFAULT NULL,
  `traitements` text DEFAULT NULL,
  `antecedents` text DEFAULT NULL,
  `medecin_traitant` varchar(255) DEFAULT NULL,
  `telephone_urgence` varchar(20) DEFAULT NULL,
  `contact_urgence` varchar(255) DEFAULT NULL,
  `groupe_sanguin` varchar(5) DEFAULT NULL,
  `pai` TEXT DEFAULT NULL COMMENT 'Projet d''Accueil Individualisé',
  `pai_details` text DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `vaccinations` text DEFAULT NULL,
  `pathologies` text DEFAULT NULL,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_fiche_sante` (`eleve_id`),
  CONSTRAINT `fk_fiche_sante_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_fiches_sante_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `passages_infirmerie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `date_passage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motif` varchar(255) NOT NULL,
  `symptomes` text DEFAULT NULL,
  `soins_prodigues` text DEFAULT NULL,
  `medicaments_donnes` text DEFAULT NULL,
  `orientation` enum('retour_classe','repos','domicile','urgences','autre') NOT NULL DEFAULT 'retour_classe',
  `parent_prevenu` tinyint(1) NOT NULL DEFAULT 0,
  `heure_sortie` time DEFAULT NULL,
  `infirmier_id` int(11) DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_passage_eleve` (`eleve_id`),
  KEY `idx_passage_date` (`date_passage`),
  CONSTRAINT `fk_passage_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_passages_inf_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `protocoles_urgence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `pathologie` varchar(255) DEFAULT NULL,
  `conduite_a_tenir` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_protocole_nom` (`nom`),
  KEY `idx_protocole_pathologie` (`pathologie`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_protocoles_urgence_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `infirmerie_traitements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `medicament` varchar(255) NOT NULL,
  `posologie` varchar(255) DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `pai` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_traitement_eleve` (`eleve_id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_inf_traitement_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inf_traitement_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `infirmerie_prises` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `traitement_id` int(11) NOT NULL,
  `date_prise` date NOT NULL,
  `heure_prise` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `commentaire` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prise_traitement` (`traitement_id`),
  CONSTRAINT `fk_inf_prise_traitement` FOREIGN KEY (`traitement_id`) REFERENCES `infirmerie_traitements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
