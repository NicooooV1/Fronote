-- Module devoirs — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `devoirs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `classe` varchar(50) NOT NULL,
  `nom_matiere` varchar(100) NOT NULL,
  `nom_professeur` varchar(100) NOT NULL,
  `date_ajout` date NOT NULL,
  `date_rendu` date NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_classe` (`classe`),
  KEY `idx_date_rendu` (`date_rendu`),
  KEY `idx_nom_professeur` (`nom_professeur`),
  KEY `idx_nom_matiere` (`nom_matiere`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_devoirs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devoirs_fichiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `devoir_id` int(11) NOT NULL,
  `nom_original` varchar(255) NOT NULL,
  `nom_stockage` varchar(255) NOT NULL COMMENT 'Chemin relatif dans uploads/',
  `type_mime` varchar(100) NOT NULL,
  `taille` int(11) NOT NULL DEFAULT 0,
  `date_upload` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_devoir_id` (`devoir_id`),
  CONSTRAINT `fk_fichiers_devoir` FOREIGN KEY (`devoir_id`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devoirs_statuts_eleve` (
  `eleve_id` int(11) NOT NULL,
  `devoir_id` int(11) NOT NULL,
  `fait` tinyint(1) NOT NULL DEFAULT 0,
  `date_marque` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`eleve_id`, `devoir_id`),
  KEY `idx_devoir_statut` (`devoir_id`),
  CONSTRAINT `fk_statut_devoir` FOREIGN KEY (`devoir_id`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_statut_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devoirs_rendus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `devoir_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `contenu` text DEFAULT NULL,
  `fichier_nom` varchar(255) DEFAULT NULL,
  `fichier_chemin` varchar(255) DEFAULT NULL,
  `fichier_type` varchar(100) DEFAULT NULL,
  `fichier_taille` int(11) DEFAULT 0,
  `date_rendu` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `en_retard` tinyint(1) NOT NULL DEFAULT 0,
  `note` decimal(4,2) DEFAULT NULL,
  `note_sur` decimal(4,2) DEFAULT 20.00,
  `commentaire_prof` text DEFAULT NULL,
  `date_correction` datetime DEFAULT NULL,
  `statut` enum('rendu','corrige','a_refaire') NOT NULL DEFAULT 'rendu',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rendu` (`devoir_id`, `eleve_id`),
  KEY `idx_rendu_eleve` (`eleve_id`),
  CONSTRAINT `fk_rendu_devoir` FOREIGN KEY (`devoir_id`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rendu_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('creation','rappel','correction') NOT NULL,
  `id_devoir` int(11) NOT NULL,
  `statut` enum('en_attente','envoye','erreur') NOT NULL DEFAULT 'en_attente',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_envoi` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_devoir` (`id_devoir`),
  CONSTRAINT `fk_notif_devoir` FOREIGN KEY (`id_devoir`) REFERENCES `devoirs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
