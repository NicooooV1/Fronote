-- Module intelligence — schéma SQL (analyse prédictive / risque décrochage).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers tables core (etablissements, eleves) présentes avant activation.

CREATE TABLE IF NOT EXISTS `intelligence_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `poids_absences` decimal(3,2) NOT NULL DEFAULT 0.30,
  `poids_notes` decimal(3,2) NOT NULL DEFAULT 0.35,
  `poids_discipline` decimal(3,2) NOT NULL DEFAULT 0.20,
  `poids_engagement` decimal(3,2) NOT NULL DEFAULT 0.15,
  `seuil_jaune` int(11) NOT NULL DEFAULT 40,
  `seuil_orange` int(11) NOT NULL DEFAULT 60,
  `seuil_rouge` int(11) NOT NULL DEFAULT 80,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_intel_config_etab` (`etablissement_id`),
  CONSTRAINT `fk_intel_config_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `intelligence_config` (`etablissement_id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `intelligence_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `annee_scolaire` varchar(10) NOT NULL DEFAULT '',
  `score_risque` decimal(5,2) NOT NULL DEFAULT 0,
  `score_absences` decimal(5,2) NOT NULL DEFAULT 0,
  `score_notes` decimal(5,2) NOT NULL DEFAULT 0,
  `score_discipline` decimal(5,2) NOT NULL DEFAULT 0,
  `score_engagement` decimal(5,2) NOT NULL DEFAULT 0,
  `niveau_alerte` enum('vert','jaune','orange','rouge') NOT NULL DEFAULT 'vert',
  `facteurs_json` json DEFAULT NULL,
  `recommandations` json DEFAULT NULL,
  `date_calcul` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_intel_score` (`etablissement_id`, `eleve_id`, `annee_scolaire`),
  KEY `idx_intel_niveau` (`etablissement_id`, `niveau_alerte`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_intel_score_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_intel_score_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intelligence_alertes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `type_alerte` varchar(50) NOT NULL,
  `niveau` enum('jaune','orange','rouge') NOT NULL DEFAULT 'orange',
  `message` varchar(255) NOT NULL,
  `details_json` json DEFAULT NULL,
  `score_declencheur` decimal(5,2) DEFAULT NULL,
  `statut` enum('active','traitee') NOT NULL DEFAULT 'active',
  `action_prise` varchar(255) DEFAULT NULL,
  `traite_par` int(11) DEFAULT NULL,
  `date_traitement` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intel_alerte` (`etablissement_id`, `statut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_intel_alerte_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_intel_alerte_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
