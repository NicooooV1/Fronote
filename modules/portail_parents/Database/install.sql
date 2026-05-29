-- Module portail_parents — schéma SQL (documents à signer, signatures, autorisations sortie, préférences).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, parents, eleves). Lit aussi notes/absences/agenda/factures (autres modules) sans table propre.

CREATE TABLE IF NOT EXISTS `portail_parents_documents_a_signer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `fichier_path` varchar(255) DEFAULT NULL,
  `obligatoire` tinyint(1) NOT NULL DEFAULT 0,
  `date_limite` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_pp_doc_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portail_parents_signatures_doc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `signature_id` int(11) DEFAULT NULL,
  `signe_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pp_sig` (`document_id`, `parent_id`, `eleve_id`),
  KEY `idx_pp_sig_parent` (`parent_id`),
  CONSTRAINT `fk_pp_sig_doc` FOREIGN KEY (`document_id`) REFERENCES `portail_parents_documents_a_signer` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_sig_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_sig_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portail_parents_autorisations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'sortie_anticipee',
  `motif` text DEFAULT NULL,
  `date_debut` datetime DEFAULT NULL,
  `date_fin` datetime DEFAULT NULL,
  `qr_token` varchar(64) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'demandee',
  `approuve_par` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pp_qr` (`qr_token`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_pp_aut_parent` (`parent_id`),
  KEY `idx_pp_aut_eleve` (`eleve_id`),
  CONSTRAINT `fk_pp_aut_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_aut_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_aut_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portail_parents_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL,
  `notifications_resume_quotidien` tinyint(1) NOT NULL DEFAULT 1,
  `notifications_notes` tinyint(1) NOT NULL DEFAULT 1,
  `notifications_absences` tinyint(1) NOT NULL DEFAULT 1,
  `langue` varchar(5) NOT NULL DEFAULT 'fr',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pp_pref_parent` (`parent_id`),
  CONSTRAINT `fk_pp_pref_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
