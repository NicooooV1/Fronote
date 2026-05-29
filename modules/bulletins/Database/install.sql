-- Module bulletins — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `bulletins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `classe_id` int(11) NOT NULL,
  `periode_id` int(11) NOT NULL,
  `annee_scolaire` varchar(10) NOT NULL DEFAULT '2025-2026',
  `moyenne_generale` decimal(4,2) DEFAULT NULL,
  `rang` int(11) DEFAULT NULL,
  `appreciation_generale` text DEFAULT NULL,
  `appreciation_vie_scolaire` text DEFAULT NULL,
  `avis_conseil` enum('felicitations','compliments','encouragements','avertissement_travail','avertissement_conduite','aucun') DEFAULT 'aucun',
  `nb_absences` int(11) DEFAULT 0,
  `nb_retards` int(11) DEFAULT 0,
  `statut` enum('brouillon','valide','publie','archive') NOT NULL DEFAULT 'brouillon',
  `competences_bilan` json DEFAULT NULL,
  `valide_par` int(11) DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `date_publication` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bulletin` (`eleve_id`, `periode_id`, `annee_scolaire`),
  KEY `idx_bulletin_classe` (`classe_id`),
  KEY `idx_bulletin_periode` (`periode_id`),
  CONSTRAINT `fk_bulletin_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bulletin_classe` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`periode_id`) REFERENCES `periodes` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_bulletins_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bulletin_matieres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bulletin_id` int(11) NOT NULL,
  `matiere_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `moyenne_eleve` decimal(4,2) DEFAULT NULL,
  `moyenne_classe` decimal(4,2) DEFAULT NULL,
  `moyenne_min` decimal(4,2) DEFAULT NULL,
  `moyenne_max` decimal(4,2) DEFAULT NULL,
  `appreciation` text DEFAULT NULL,
  `coefficient` decimal(3,2) DEFAULT 1.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bm` (`bulletin_id`, `matiere_id`),
  KEY `idx_bm_matiere` (`matiere_id`),
  CONSTRAINT `fk_bm_bulletin` FOREIGN KEY (`bulletin_id`) REFERENCES `bulletins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_matiere` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
