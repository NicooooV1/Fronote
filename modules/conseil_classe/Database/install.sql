-- Module conseil_classe — schéma SQL (sessions, discussions élèves, votes, synthèse, participants).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, eleves). classe_id est un varchar (eleves.classe).

CREATE TABLE IF NOT EXISTS `conseil_classe_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `classe_id` varchar(50) NOT NULL,
  `periode_id` int(11) DEFAULT NULL,
  `annee_scolaire` varchar(9) DEFAULT NULL,
  `date_conseil` datetime DEFAULT NULL,
  `lieu` varchar(150) DEFAULT NULL,
  `president_id` int(11) DEFAULT NULL,
  `president_type` varchar(30) DEFAULT NULL,
  `secretaire_id` int(11) DEFAULT NULL,
  `secretaire_type` varchar(30) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'planifie',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_cc_sess_classe` (`classe_id`),
  CONSTRAINT `fk_cc_sess_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conseil_classe_eleve_discussions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  `appreciation` text DEFAULT NULL,
  `avis_propose` varchar(40) NOT NULL DEFAULT 'aucun',
  `avis_final` varchar(40) DEFAULT NULL,
  `commentaire_delegue_eleve` text DEFAULT NULL,
  `commentaire_delegue_parent` text DEFAULT NULL,
  `avis_vote_pour` int(11) NOT NULL DEFAULT 0,
  `avis_vote_contre` int(11) NOT NULL DEFAULT 0,
  `avis_vote_abstention` int(11) NOT NULL DEFAULT 0,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cc_disc` (`session_id`, `eleve_id`),
  KEY `idx_cc_disc_eleve` (`eleve_id`),
  CONSTRAINT `fk_cc_disc_sess` FOREIGN KEY (`session_id`) REFERENCES `conseil_classe_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cc_disc_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conseil_classe_votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discussion_id` int(11) NOT NULL,
  `voter_id` int(11) NOT NULL,
  `voter_type` varchar(30) NOT NULL,
  `vote` varchar(20) NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cc_vote` (`discussion_id`, `voter_id`, `voter_type`),
  CONSTRAINT `fk_cc_vote_disc` FOREIGN KEY (`discussion_id`) REFERENCES `conseil_classe_eleve_discussions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conseil_classe_synthese` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `synthese_generale` text DEFAULT NULL,
  `points_positifs` text DEFAULT NULL,
  `points_amelioration` text DEFAULT NULL,
  `decisions` text DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cc_synth` (`session_id`),
  CONSTRAINT `fk_cc_synth_sess` FOREIGN KEY (`session_id`) REFERENCES `conseil_classe_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conseil_classe_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `present` tinyint(1) NOT NULL DEFAULT 0,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cc_part` (`session_id`, `user_id`, `user_type`),
  CONSTRAINT `fk_cc_part_sess` FOREIGN KEY (`session_id`) REFERENCES `conseil_classe_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
