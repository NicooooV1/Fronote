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
  `pai` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Projet d''Accueil Individualisé',
  `pai_details` text DEFAULT NULL,
  `observations` text DEFAULT NULL,
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
