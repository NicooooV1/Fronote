-- Module stages — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `eleve_id` int(11) NOT NULL,
  `type` enum('stage','alternance','immersion') NOT NULL DEFAULT 'stage',
  `entreprise_nom` varchar(255) NOT NULL,
  `entreprise_adresse` text DEFAULT NULL,
  `entreprise_contact` varchar(255) DEFAULT NULL,
  `tuteur_nom` varchar(255) DEFAULT NULL,
  `tuteur_email` varchar(255) DEFAULT NULL,
  `tuteur_telephone` varchar(20) DEFAULT NULL,
  `professeur_referent_id` int(11) DEFAULT NULL,
  `sujet` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `convention_path` varchar(500) DEFAULT NULL,
  `statut` enum('brouillon','soumis','valide','en_cours','termine','annule') NOT NULL DEFAULT 'brouillon',
  `evaluation_entreprise` text DEFAULT NULL,
  `evaluation_note` decimal(5,2) DEFAULT NULL,
  `rapport_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eleve` (`eleve_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_stages_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
