-- Module accessibilite — schéma SQL (aménagements, AESH, MDPH, ESS, audit numérique).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, eleves) présentes avant activation.

CREATE TABLE IF NOT EXISTS `accessibilite_aesh` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `quotite_horaire` decimal(5,2) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_acc_aesh_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accessibilite_amenagements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `type_amenagement` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `prescripteur` varchar(150) DEFAULT NULL,
  `document_reference` varchar(255) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'actif',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_acc_amen_eleve` (`eleve_id`),
  CONSTRAINT `fk_acc_amen_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acc_amen_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accessibilite_aesh_affectations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aesh_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `heures_semaine` decimal(5,2) NOT NULL DEFAULT 0,
  `type_accompagnement` varchar(50) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'actif',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_aff_aesh` (`aesh_id`),
  KEY `idx_acc_aff_eleve` (`eleve_id`),
  CONSTRAINT `fk_acc_aff_aesh` FOREIGN KEY (`aesh_id`) REFERENCES `accessibilite_aesh` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acc_aff_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accessibilite_mdph` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `eleve_id` int(11) NOT NULL,
  `type_decision` varchar(100) NOT NULL,
  `date_decision` date DEFAULT NULL,
  `date_expiration` date DEFAULT NULL,
  `contenu` text DEFAULT NULL,
  `numero_dossier` varchar(100) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'actif',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_mdph_eleve` (`eleve_id`),
  CONSTRAINT `fk_acc_mdph_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accessibilite_ess` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `eleve_id` int(11) NOT NULL,
  `date_ess` datetime DEFAULT NULL,
  `lieu` varchar(150) DEFAULT NULL,
  `participants_ids` json DEFAULT NULL,
  `objectifs` text DEFAULT NULL,
  `compte_rendu` text DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'planifie',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_ess_eleve` (`eleve_id`),
  CONSTRAINT `fk_acc_ess_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accessibilite_audit_numerique` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `module_key` varchar(100) NOT NULL,
  `auditeur_id` int(11) DEFAULT NULL,
  `criteres_json` json DEFAULT NULL,
  `nb_conformes` int(11) NOT NULL DEFAULT 0,
  `nb_total` int(11) NOT NULL DEFAULT 0,
  `score` decimal(5,2) NOT NULL DEFAULT 0,
  `date_audit` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_acc_audit_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
