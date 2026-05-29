-- Module appel — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `appels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `edt_id` int(11) DEFAULT NULL COMMENT 'Lien optionnel avec un cours EDT',
  `classe_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `date_appel` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `type_appel` enum('cours','demi_journee','journee') NOT NULL DEFAULT 'cours',
  `statut` enum('en_cours','valide','cloture') NOT NULL DEFAULT 'en_cours',
  `commentaire` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appel_classe` (`classe_id`),
  KEY `idx_appel_prof` (`professeur_id`),
  KEY `idx_appel_date` (`date_appel`),
  KEY `idx_appel_edt` (`edt_id`),
  CONSTRAINT `fk_appel_classe` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appel_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_appels_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appel_eleves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appel_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `statut` enum('present','absent','retard','dispense','exclu') NOT NULL DEFAULT 'present',
  `heure_arrivee` time DEFAULT NULL COMMENT 'si retard',
  `duree_retard` int(11) DEFAULT NULL COMMENT 'minutes',
  `motif` varchar(255) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `notifie` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'parent notifié ?',
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_appel_eleve` (`appel_id`, `eleve_id`),
  KEY `idx_ae_eleve` (`eleve_id`),
  KEY `idx_ae_statut` (`statut`),
  CONSTRAINT `fk_ae_appel` FOREIGN KEY (`appel_id`) REFERENCES `appels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ae_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
