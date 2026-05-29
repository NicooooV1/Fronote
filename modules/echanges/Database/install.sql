-- Module echanges — schéma SQL (programmes, candidatures, familles, hébergements, partenariats, suivi CECRL).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, eleves). responsable_id/evaluateur_id NON FK (service passe 0).

CREATE TABLE IF NOT EXISTS `echanges_programmes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(200) NOT NULL,
  `type_programme` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `pays_destination` varchar(100) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `places_max` int(11) NOT NULL DEFAULT 0,
  `budget` decimal(10,2) NOT NULL DEFAULT 0,
  `responsable_id` int(11) DEFAULT NULL,
  `statut` varchar(30) NOT NULL DEFAULT 'planifie',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_ech_prog_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `echanges_candidatures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `programme_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `motivation` text DEFAULT NULL,
  `niveau_langue_declare` varchar(20) DEFAULT NULL,
  `statut` varchar(30) NOT NULL DEFAULT 'soumise',
  `commentaire` text DEFAULT NULL,
  `date_decision` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ech_cand_prog` (`programme_id`),
  KEY `idx_ech_cand_eleve` (`eleve_id`),
  CONSTRAINT `fk_ech_cand_prog` FOREIGN KEY (`programme_id`) REFERENCES `echanges_programmes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ech_cand_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `echanges_familles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom_famille` varchar(150) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `capacite` int(11) NOT NULL DEFAULT 1,
  `langues` json DEFAULT NULL,
  `preferences` text DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'disponible',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_ech_fam_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `echanges_hebergements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `candidature_id` int(11) NOT NULL,
  `famille_id` int(11) NOT NULL,
  `date_arrivee` date DEFAULT NULL,
  `date_depart` date DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'confirme',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ech_heb_cand` (`candidature_id`),
  KEY `idx_ech_heb_fam` (`famille_id`),
  CONSTRAINT `fk_ech_heb_cand` FOREIGN KEY (`candidature_id`) REFERENCES `echanges_candidatures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ech_heb_fam` FOREIGN KEY (`famille_id`) REFERENCES `echanges_familles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `echanges_partenariats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom_etablissement_partenaire` varchar(200) NOT NULL,
  `pays` varchar(100) DEFAULT NULL,
  `ville` varchar(120) DEFAULT NULL,
  `type_partenariat` varchar(60) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `contact_nom` varchar(150) DEFAULT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'actif',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_ech_part_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `echanges_suivi_linguistique` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `eleve_id` int(11) NOT NULL,
  `langue` varchar(60) NOT NULL,
  `niveau_cecrl` varchar(10) DEFAULT NULL,
  `type_evaluation` varchar(60) DEFAULT NULL,
  `evaluateur_id` int(11) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `date_evaluation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ech_ling_eleve` (`eleve_id`),
  CONSTRAINT `fk_ech_ling_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
