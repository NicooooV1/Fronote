-- Module projets_pedagogiques — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `projets_pedagogiques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `objectifs` text DEFAULT NULL,
  `type` enum('EPI','projet_classe','sortie','voyage','autre') NOT NULL DEFAULT 'projet_classe',
  `responsable_id` int(11) NOT NULL COMMENT 'professeur responsable',
  `classes` varchar(500) DEFAULT NULL COMMENT 'classes concernées, CSV',
  `matieres` varchar(500) DEFAULT NULL COMMENT 'matières impliquées, CSV',
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `budget` decimal(10,2) DEFAULT NULL,
  `statut` enum('brouillon','soumis','valide','en_cours','termine','annule') NOT NULL DEFAULT 'brouillon',
  `pieces_jointes` text DEFAULT NULL COMMENT 'JSON array de fichiers',
  `bilan` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_responsable` (`responsable_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_projets_peda_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projets_pedagogiques_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projet_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('professeur','eleve') NOT NULL,
  `role_projet` varchar(100) DEFAULT NULL COMMENT 'Ex: co-responsable, participant',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_projet_user` (`projet_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_projpart_projet` FOREIGN KEY (`projet_id`) REFERENCES `projets_pedagogiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projets_pedagogiques_etapes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projet_id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `date_echeance` date DEFAULT NULL,
  `statut` enum('a_faire','en_cours','termine') NOT NULL DEFAULT 'a_faire',
  `ordre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_projetape_projet` FOREIGN KEY (`projet_id`) REFERENCES `projets_pedagogiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
