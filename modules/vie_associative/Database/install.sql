-- Module vie_associative — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `associations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `type` enum('MDL','FSE','association','autre') NOT NULL DEFAULT 'MDL',
  `description` text DEFAULT NULL,
  `president_eleve_id` int(11) DEFAULT NULL,
  `referent_adulte_id` int(11) DEFAULT NULL,
  `budget_annuel` decimal(10,2) DEFAULT NULL,
  `statut` enum('active','inactive','en_creation') NOT NULL DEFAULT 'active',
  `logo_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_associations_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `association_membres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `association_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `role_membre` enum('president','vice_president','tresorier','secretaire','membre') NOT NULL DEFAULT 'membre',
  `date_adhesion` date NOT NULL,
  `cotisation_payee` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asso_eleve` (`association_id`, `eleve_id`),
  CONSTRAINT `fk_assomembre_asso` FOREIGN KEY (`association_id`) REFERENCES `associations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `association_activites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `association_id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `date_activite` datetime NOT NULL,
  `lieu` varchar(255) DEFAULT NULL,
  `budget_alloue` decimal(10,2) DEFAULT NULL,
  `budget_depense` decimal(10,2) DEFAULT NULL,
  `nb_participants` int(11) DEFAULT NULL,
  `statut` enum('planifie','en_cours','termine','annule') NOT NULL DEFAULT 'planifie',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_assoact_asso` FOREIGN KEY (`association_id`) REFERENCES `associations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `association_tresorerie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `association_id` int(11) NOT NULL,
  `type` enum('recette','depense') NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `categorie` varchar(100) DEFAULT NULL COMMENT 'cotisations, vente, achat, etc.',
  `date_operation` date NOT NULL,
  `justificatif_path` varchar(500) DEFAULT NULL,
  `saisi_par` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_assotres_asso` FOREIGN KEY (`association_id`) REFERENCES `associations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
