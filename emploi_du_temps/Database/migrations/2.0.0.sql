-- Module emploi_du_temps — migration 2.0.0
-- Tables du moteur de génération / contraintes enseignants (CDC §6/§7).
-- Idempotent (IF NOT EXISTS) : sans effet si déjà créées par pronote.sql.
-- Une fois qu'une install neuve a confirmé l'exécution de cette migration,
-- ces 3 tables pourront être retirées de pronote.sql (split modulaire complet).

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
