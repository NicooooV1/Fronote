-- Module orientation — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `orientation_fiches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `annee_scolaire` varchar(10) NOT NULL,
  `classe` varchar(50) DEFAULT NULL,
  `projet_professionnel` text DEFAULT NULL,
  `centres_interet` text DEFAULT NULL,
  `competences_cles` text DEFAULT NULL,
  `points_forts` text DEFAULT NULL,
  `points_amelioration` text DEFAULT NULL,
  `commentaire_pp` text DEFAULT NULL COMMENT 'Commentaire du professeur principal',
  `commentaire_cpe` text DEFAULT NULL,
  `avis_pp` enum('favorable','reserve','defavorable','en_attente') DEFAULT 'en_attente',
  `avis_conseil` enum('favorable','reserve','defavorable','en_attente') DEFAULT 'en_attente',
  `statut` enum('brouillon','soumise','validee','archivee') NOT NULL DEFAULT 'brouillon',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orient_eleve` (`eleve_id`),
  KEY `idx_orient_annee` (`annee_scolaire`),
  CONSTRAINT `fk_orient_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_orient_fiches_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orientation_voeux` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `fiche_id` int(11) NOT NULL,
  `rang` int(11) NOT NULL DEFAULT 1,
  `intitule` varchar(255) NOT NULL,
  `etablissement_vise` varchar(255) DEFAULT NULL,
  `filiere` varchar(100) DEFAULT NULL,
  `motivation` text DEFAULT NULL,
  `avis_pp` enum('favorable','reserve','defavorable','en_attente') DEFAULT 'en_attente',
  `avis_conseil` enum('favorable','reserve','defavorable','en_attente') DEFAULT 'en_attente',
  PRIMARY KEY (`id`),
  KEY `idx_voeu_fiche` (`fiche_id`),
  KEY `idx_voeu_rang` (`rang`),
  CONSTRAINT `fk_voeu_fiche` FOREIGN KEY (`fiche_id`) REFERENCES `orientation_fiches` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_orient_voeux_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] orientation_rdv
CREATE TABLE IF NOT EXISTS `orientation_rdv` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `date_rdv` DATE NOT NULL,
  `heure_rdv` TIME NULL,
  `motif` TEXT NULL,
  `statut` VARCHAR(30) NOT NULL DEFAULT 'planifie',
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date` (`date_rdv`,`heure_rdv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] orientation_entretiens
CREATE TABLE IF NOT EXISTS `orientation_entretiens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `pp_id` INT NOT NULL,
  `date_entretien` DATE NOT NULL,
  `motif` TEXT NULL,
  `statut` VARCHAR(30) NOT NULL DEFAULT 'planifie',
  `compte_rendu` TEXT NULL,
  `recommandations` TEXT NULL,
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_pp` (`pp_id`,`date_entretien`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
