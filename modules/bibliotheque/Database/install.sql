-- Module bibliotheque — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `livres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(255) NOT NULL,
  `auteur` varchar(255) DEFAULT NULL,
  `isbn` varchar(20) DEFAULT NULL,
  `editeur` varchar(255) DEFAULT NULL,
  `annee_publication` int(4) DEFAULT NULL,
  `categorie` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `exemplaires_total` int(11) NOT NULL DEFAULT 1,
  `exemplaires_disponibles` int(11) NOT NULL DEFAULT 1,
  `emplacement` varchar(100) DEFAULT NULL COMMENT 'Rayon, étagère, etc.',
  `couverture` varchar(255) DEFAULT NULL COMMENT 'Chemin image couverture',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_livre_titre` (`titre`),
  KEY `idx_livre_isbn` (`isbn`),
  KEY `idx_livre_categorie` (`categorie`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_livres_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emprunts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `livre_id` int(11) NOT NULL,
  `emprunteur_id` int(11) NOT NULL,
  `emprunteur_type` varchar(20) NOT NULL,
  `date_emprunt` date NOT NULL,
  `date_retour_prevue` date NOT NULL,
  `date_retour_effective` date DEFAULT NULL,
  `statut` enum('en_cours','rendu','en_retard','perdu') NOT NULL DEFAULT 'en_cours',
  `remarques` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emprunt_livre` (`livre_id`),
  KEY `idx_emprunt_user` (`emprunteur_id`, `emprunteur_type`),
  KEY `idx_emprunt_statut` (`statut`),
  KEY `idx_emprunt_retour` (`date_retour_prevue`),
  CONSTRAINT `fk_emprunt_livre` FOREIGN KEY (`livre_id`) REFERENCES `livres` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_emprunts_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
