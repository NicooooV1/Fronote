-- Module besoins — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `plans_accompagnement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `type_plan` enum('PAP','PPS','PPRE','PAI','autre') NOT NULL,
  `intitule` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `amenagements` text DEFAULT NULL COMMENT 'tiers-temps, AVS, etc.',
  `objectifs` text DEFAULT NULL COMMENT 'objectifs du plan (texte ou JSON)',
  `evaluations` longtext DEFAULT NULL COMMENT 'évaluations périodiques sérialisées (JSON)',
  `responsable_id` int(11) DEFAULT NULL,
  `responsable_type` enum('professeur','vie_scolaire','administrateur') DEFAULT 'professeur',
  `createur_id` int(11) DEFAULT NULL COMMENT 'auteur de la création du plan',
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` enum('brouillon','actif','suspendu','termine','archive','expire') NOT NULL DEFAULT 'brouillon',
  `document_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_type` (`type_plan`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_plans_acc_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_suivis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `auteur_id` int(11) NOT NULL,
  `auteur_type` enum('professeur','vie_scolaire','administrateur') NOT NULL,
  `date_suivi` date NOT NULL,
  `observations` text NOT NULL,
  `progres` enum('insuffisant','en_cours','satisfaisant','acquis') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_plansuivi_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans_accompagnement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observations multi-intervenants sur un plan (ajouterObservation/getObservations).
CREATE TABLE IF NOT EXISTS `besoins_observations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `plan_id` int(11) NOT NULL,
  `auteur_id` int(11) NOT NULL,
  `auteur_type` enum('professeur','vie_scolaire','administrateur','parent','eleve') NOT NULL,
  `texte` text NOT NULL,
  `date_observation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_obs_plan` (`plan_id`),
  KEY `idx_obs_etab` (`etablissement_id`),
  CONSTRAINT `fk_obs_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans_accompagnement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Évaluations datées d'un plan, source du graphe de progression (getProgressionChart).
CREATE TABLE IF NOT EXISTS `plan_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `plan_id` int(11) NOT NULL,
  `date_evaluation` date NOT NULL,
  `progres` enum('insuffisant','en_difficulte','satisfaisant','tres_bien') DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `evaluateur_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eval_plan` (`plan_id`),
  KEY `idx_eval_etab` (`etablissement_id`),
  CONSTRAINT `fk_eval_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans_accompagnement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modèles de plan réutilisables (getModelesPlan/creerDepuisModele).
CREATE TABLE IF NOT EXISTS `besoins_modeles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `type_plan` enum('PAP','PPS','PPRE','PAI','autre') NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `amenagements_defaut` text DEFAULT NULL,
  `objectifs_defaut` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_modele_type` (`type_plan`),
  KEY `idx_modele_etab` (`etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
