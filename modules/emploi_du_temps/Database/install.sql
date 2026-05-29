-- Module emploi_du_temps — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `creneaux_horaires` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `label` varchar(30) NOT NULL COMMENT 'ex: M1, M2, S1, S2',
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  `type` enum('cours','pause','repas') NOT NULL DEFAULT 'cours',
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_creneaux_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emploi_du_temps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `classe_id` int(11) NOT NULL,
  `matiere_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `salle_id` int(11) DEFAULT NULL,
  `jour` enum('lundi','mardi','mercredi','jeudi','vendredi','samedi') NOT NULL,
  `creneau_id` int(11) NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `groupe` varchar(50) DEFAULT NULL COMMENT 'null = classe entière',
  `type_cours` enum('cours','td','tp','examen','autre') NOT NULL DEFAULT 'cours',
  `recurrence` enum('hebdomadaire','quinzaine_A','quinzaine_B','ponctuel') NOT NULL DEFAULT 'hebdomadaire',
  `date_debut_validite` date DEFAULT NULL,
  `date_fin_validite` date DEFAULT NULL,
  `couleur` varchar(7) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_edt_classe` (`classe_id`),
  KEY `idx_edt_prof` (`professeur_id`),
  KEY `idx_edt_salle` (`salle_id`),
  KEY `idx_edt_jour_creneau` (`jour`, `creneau_id`),
  CONSTRAINT `fk_edt_classe` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edt_matiere` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edt_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edt_salle` FOREIGN KEY (`salle_id`) REFERENCES `salles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_edt_creneau` FOREIGN KEY (`creneau_id`) REFERENCES `creneaux_horaires` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_edt_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edt_modifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `edt_id` int(11) NOT NULL,
  `date_cours` date NOT NULL,
  `type_modification` enum('annulation','deplacement','remplacement') NOT NULL,
  `nouveau_professeur_id` int(11) DEFAULT NULL,
  `nouvelle_salle_id` int(11) DEFAULT NULL,
  `nouvelle_heure_debut` time DEFAULT NULL,
  `nouvelle_heure_fin` time DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `createur_id` int(11) DEFAULT NULL,
  `createur_type` varchar(20) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_edtmod_edt` (`edt_id`),
  KEY `idx_edtmod_date` (`date_cours`),
  CONSTRAINT `fk_edtmod_edt` FOREIGN KEY (`edt_id`) REFERENCES `emploi_du_temps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enseignant_disponibilites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professeur_id` int(11) NOT NULL,
  `jour` enum('lundi','mardi','mercredi','jeudi','vendredi','samedi') NOT NULL,
  `creneau_id` int(11) NOT NULL,
  `type` enum('indisponible','reunion','temps_partiel','formation','autre') NOT NULL DEFAULT 'indisponible',
  `motif` varchar(255) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispo` (`professeur_id`, `jour`, `creneau_id`),
  KEY `idx_dispo_prof` (`professeur_id`),
  CONSTRAINT `fk_dispo_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dispo_creneau` FOREIGN KEY (`creneau_id`) REFERENCES `creneaux_horaires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enseignant_preferences` (
  `professeur_id` int(11) NOT NULL,
  `max_heures_jour` int(11) DEFAULT NULL COMMENT 'max créneaux de cours par jour',
  `max_heures_consecutives` int(11) DEFAULT NULL,
  `pas_avant` time DEFAULT NULL COMMENT 'pas de cours avant cette heure',
  `pas_apres` time DEFAULT NULL COMMENT 'pas de cours après cette heure',
  `eviter_mercredi_apresmidi` tinyint(1) NOT NULL DEFAULT 0,
  `prefere_matin` tinyint(1) NOT NULL DEFAULT 0,
  `prefere_journees_groupees` tinyint(1) NOT NULL DEFAULT 0,
  `extra` JSON DEFAULT NULL COMMENT 'préférences additionnelles extensibles',
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`professeur_id`),
  CONSTRAINT `fk_pref_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edt_maquette` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `classe_id` int(11) NOT NULL,
  `matiere_id` int(11) NOT NULL,
  `professeur_id` int(11) NOT NULL,
  `nb_creneaux` int(11) NOT NULL DEFAULT 1,
  `type_cours` enum('cours','td','tp','examen','autre') NOT NULL DEFAULT 'cours',
  `groupe` varchar(50) DEFAULT NULL,
  `salle_type` varchar(50) DEFAULT NULL COMMENT 'type de salle requis (info, labo…)',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maquette_classe` (`classe_id`),
  CONSTRAINT `fk_maquette_classe` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_maquette_matiere` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_maquette_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
