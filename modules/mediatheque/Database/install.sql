-- Module mediatheque — schéma SQL (contenus, playlists, vues, notes/avis, favoris, quotas).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, professeurs, matieres). user_id NON FK (multi-type).

CREATE TABLE IF NOT EXISTS `mediatheque_contenus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `professeur_id` int(11) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `type_contenu` varchar(30) DEFAULT NULL,
  `fichier_path` varchar(255) DEFAULT NULL,
  `taille_mo` int(11) NOT NULL DEFAULT 0,
  `matiere_id` int(11) DEFAULT NULL,
  `niveau` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `duree_secondes` int(11) NOT NULL DEFAULT 0,
  `statut` varchar(20) NOT NULL DEFAULT 'publie',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_med_cont_prof` (`professeur_id`),
  KEY `idx_med_cont_mat` (`matiere_id`),
  CONSTRAINT `fk_med_cont_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_med_cont_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_med_cont_mat` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_playlists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professeur_id` int(11) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `niveau` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_med_pl_prof` (`professeur_id`),
  CONSTRAINT `fk_med_pl_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_med_pl_mat` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_playlists_contenus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `playlist_id` int(11) NOT NULL,
  `contenu_id` int(11) NOT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_med_pl_cont` (`playlist_id`, `contenu_id`),
  KEY `idx_med_plc_cont` (`contenu_id`),
  CONSTRAINT `fk_med_plc_pl` FOREIGN KEY (`playlist_id`) REFERENCES `mediatheque_playlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_med_plc_cont` FOREIGN KEY (`contenu_id`) REFERENCES `mediatheque_contenus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_vues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contenu_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `duree_visionnee` int(11) NOT NULL DEFAULT 0,
  `progression` decimal(5,2) NOT NULL DEFAULT 0,
  `date_vue` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_med_vue` (`contenu_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_med_vue_cont` FOREIGN KEY (`contenu_id`) REFERENCES `mediatheque_contenus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_notes_avis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contenu_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `note` int(11) NOT NULL DEFAULT 0,
  `commentaire` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_med_avis` (`contenu_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_med_avis_cont` FOREIGN KEY (`contenu_id`) REFERENCES `mediatheque_contenus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_favoris` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contenu_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_med_fav` (`contenu_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_med_fav_cont` FOREIGN KEY (`contenu_id`) REFERENCES `mediatheque_contenus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mediatheque_quotas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professeur_id` int(11) NOT NULL,
  `quota_mo` int(11) NOT NULL DEFAULT 500,
  `utilise_mo` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_med_quota_prof` (`professeur_id`),
  CONSTRAINT `fk_med_quota_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
