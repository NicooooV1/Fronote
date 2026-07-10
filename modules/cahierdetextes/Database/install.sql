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

-- [drift-fix] devoir_submissions
CREATE TABLE IF NOT EXISTS `devoir_submissions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `devoir_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `fichier_path` VARCHAR(512) DEFAULT NULL,
  `fichier_nom` VARCHAR(255) DEFAULT NULL,
  `note` DECIMAL(5,2) DEFAULT NULL,
  `commentaire_prof` TEXT DEFAULT NULL,
  `statut` VARCHAR(20) NOT NULL DEFAULT 'soumis',
  `soumis_at` DATETIME DEFAULT NULL,
  `evalue_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_devoir_eleve` (`devoir_id`, `eleve_id`),
  KEY `idx_devsub_eleve` (`eleve_id`),
  KEY `idx_devsub_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] cahier_templates
CREATE TABLE IF NOT EXISTS `cahier_templates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `etablissement_id` INT DEFAULT NULL,
  `professeur_id` INT NOT NULL,
  `matiere_id` INT DEFAULT NULL,
  `titre` VARCHAR(255) NOT NULL,
  `contenu` TEXT DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cahtpl_prof` (`professeur_id`),
  KEY `idx_cahtpl_matiere` (`matiere_id`),
  KEY `idx_cahtpl_etab` (`etablissement_id`),
  CONSTRAINT `fk_etab_cahier_templates` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] cahier_vues
CREATE TABLE IF NOT EXISTS `cahier_vues` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `devoir_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `date_vue` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vue` (`devoir_id`, `user_id`, `user_type`),
  KEY `idx_cahvue_devoir` (`devoir_id`),
  KEY `idx_cahvue_type` (`user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] devoirs_grilles
CREATE TABLE IF NOT EXISTS `devoirs_grilles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `devoir_id` INT NOT NULL,
  `titre` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_devgrille_devoir` (`devoir_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] devoirs_grille_criteres
CREATE TABLE IF NOT EXISTS `devoirs_grille_criteres` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `grille_id` INT NOT NULL,
  `critere` VARCHAR(255) NOT NULL,
  `points_max` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `description` TEXT DEFAULT NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_devcrit_grille` (`grille_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] devoirs_grille_notes
CREATE TABLE IF NOT EXISTS `devoirs_grille_notes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rendu_id` INT NOT NULL,
  `critere_id` INT NOT NULL,
  `note` DECIMAL(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rendu_critere` (`rendu_id`, `critere_id`),
  KEY `idx_devgnote_critere` (`critere_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [drift-fix] devoirs_peer_reviews
CREATE TABLE IF NOT EXISTS `devoirs_peer_reviews` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rendu_id` INT NOT NULL,
  `reviewer_eleve_id` INT NOT NULL,
  `note` DECIMAL(5,2) DEFAULT NULL,
  `commentaire` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rendu_reviewer` (`rendu_id`, `reviewer_eleve_id`),
  KEY `idx_devpr_reviewer` (`reviewer_eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
