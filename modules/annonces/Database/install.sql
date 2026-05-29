-- Module annonces — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `annonces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `contenu` text NOT NULL,
  `type` enum('info','urgent','evenement','sondage') NOT NULL DEFAULT 'info',
  `auteur_id` int(11) NOT NULL,
  `auteur_type` varchar(20) NOT NULL,
  `cible_roles` varchar(255) DEFAULT NULL COMMENT 'JSON: ["eleve","parent","professeur"]',
  `cible_classes` varchar(255) DEFAULT NULL COMMENT 'JSON: [1,2,3] (ids classes)',
  `cible_niveaux` varchar(255) DEFAULT NULL COMMENT 'JSON: ["6eme","5eme"]',
  `cible_matieres` varchar(255) DEFAULT NULL COMMENT 'JSON: [1,2,3] (ids matieres)',
  `publie` tinyint(1) NOT NULL DEFAULT 1,
  `notified` tinyint(1) NOT NULL DEFAULT 0,
  `epingle` tinyint(1) NOT NULL DEFAULT 0,
  `date_publication` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_expiration` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_annonce_date` (`date_publication`),
  KEY `idx_annonce_auteur` (`auteur_id`, `auteur_type`),
  KEY `idx_annonce_publie` (`publie`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_annonces_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `annonces_lues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `annonce_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `date_lecture` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_lecture` (`annonce_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_al_annonce` FOREIGN KEY (`annonce_id`) REFERENCES `annonces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `annonce_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `annonce_id` int(11) NOT NULL,
  `nom_fichier` varchar(255) NOT NULL COMMENT 'Stored filename (hashed)',
  `nom_original` varchar(255) NOT NULL COMMENT 'Original upload filename',
  `taille` int(11) NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
  `mime_type` varchar(100) NOT NULL DEFAULT 'application/octet-stream',
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_by_type` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_annonce` (`annonce_id`),
  CONSTRAINT `fk_attach_annonce` FOREIGN KEY (`annonce_id`) REFERENCES `annonces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sondages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `annonce_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `type_reponse` enum('choix_unique','choix_multiple','texte_libre') NOT NULL DEFAULT 'choix_unique',
  `anonyme` tinyint(1) NOT NULL DEFAULT 0,
  `date_fin` datetime DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_sondage_annonce` (`annonce_id`),
  CONSTRAINT `fk_sondage_annonce` FOREIGN KEY (`annonce_id`) REFERENCES `annonces` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_sondages_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sondage_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sondage_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_so_sondage` (`sondage_id`),
  CONSTRAINT `fk_so_sondage` FOREIGN KEY (`sondage_id`) REFERENCES `sondages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sondage_votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sondage_id` int(11) NOT NULL,
  `option_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `texte_libre` text DEFAULT NULL,
  `date_vote` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sv_sondage` (`sondage_id`),
  KEY `idx_sv_option` (`option_id`),
  CONSTRAINT `fk_sv_sondage` FOREIGN KEY (`sondage_id`) REFERENCES `sondages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sv_option` FOREIGN KEY (`option_id`) REFERENCES `sondage_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
