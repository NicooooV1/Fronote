-- Module formations — schéma SQL (catalogue, inscriptions, certifications, budgets, évals, plan annuel).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, personnel). valide_par NON FK (multi-type valideur).

CREATE TABLE IF NOT EXISTS `formations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(200) NOT NULL,
  `type` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `places_max` int(11) NOT NULL DEFAULT 0,
  `cout` decimal(10,2) NOT NULL DEFAULT 0,
  `organisme` varchar(200) DEFAULT NULL,
  `lieu` varchar(200) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'brouillon',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_form_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formation_inscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `formation_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `motivation` text DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'en_attente',
  `valide_par` int(11) DEFAULT NULL,
  `motif_refus` text DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form_insc_form` (`formation_id`),
  KEY `idx_form_insc_pers` (`personnel_id`),
  CONSTRAINT `fk_form_insc_form` FOREIGN KEY (`formation_id`) REFERENCES `formations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_insc_pers` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formation_certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `personnel_id` int(11) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `organisme` varchar(200) DEFAULT NULL,
  `date_obtention` date DEFAULT NULL,
  `date_expiration` date DEFAULT NULL,
  `numero_certificat` varchar(100) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form_cert_pers` (`personnel_id`),
  CONSTRAINT `fk_form_cert_pers` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formation_budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `personnel_id` int(11) NOT NULL,
  `annee` varchar(9) NOT NULL,
  `budget_alloue` decimal(10,2) NOT NULL DEFAULT 0,
  `budget_consomme` decimal(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_form_budget` (`etablissement_id`, `personnel_id`, `annee`),
  KEY `idx_form_budg_pers` (`personnel_id`),
  CONSTRAINT `fk_form_budg_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_budg_pers` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formation_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inscription_id` int(11) NOT NULL,
  `note_globale` int(11) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `points_forts` text DEFAULT NULL,
  `points_ameliorer` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form_eval_insc` (`inscription_id`),
  CONSTRAINT `fk_form_eval_insc` FOREIGN KEY (`inscription_id`) REFERENCES `formation_inscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formation_plan_annuel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `annee` varchar(9) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `axe` varchar(120) DEFAULT NULL,
  `priorite` int(11) NOT NULL DEFAULT 99,
  `statut` varchar(20) NOT NULL DEFAULT 'prevu',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_form_plan_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
