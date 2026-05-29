-- Module evaluations — schéma SQL (banques, questions, évals en ligne, sessions, réponses, stats).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, professeurs, eleves, matieres). Push notes via table notes (core).

CREATE TABLE IF NOT EXISTS `evaluation_banques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `professeur_id` int(11) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_eval_banq_prof` (`professeur_id`),
  CONSTRAINT `fk_eval_banq_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_banq_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_banq_mat` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evaluation_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `banque_id` int(11) NOT NULL,
  `type_question` varchar(30) NOT NULL,
  `enonce` text NOT NULL,
  `reponses_possibles` json DEFAULT NULL,
  `reponse_correcte` json DEFAULT NULL,
  `points` decimal(6,2) NOT NULL DEFAULT 1.00,
  `difficulte` varchar(20) NOT NULL DEFAULT 'moyen',
  `explication` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eval_q_banq` (`banque_id`),
  CONSTRAINT `fk_eval_q_banq` FOREIGN KEY (`banque_id`) REFERENCES `evaluation_banques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evaluations_en_ligne` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `professeur_id` int(11) NOT NULL,
  `titre` varchar(200) NOT NULL,
  `matiere_id` int(11) DEFAULT NULL,
  `classe` varchar(50) DEFAULT NULL,
  `questions_config` json DEFAULT NULL,
  `duree_minutes` int(11) NOT NULL DEFAULT 0,
  `date_ouverture` datetime DEFAULT NULL,
  `date_fermeture` datetime DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'examen',
  `anti_triche` tinyint(1) NOT NULL DEFAULT 1,
  `statut` varchar(20) NOT NULL DEFAULT 'brouillon',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_eval_prof` (`professeur_id`),
  KEY `idx_eval_classe` (`classe`),
  CONSTRAINT `fk_eval_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_prof` FOREIGN KEY (`professeur_id`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_mat` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evaluation_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `date_debut` datetime DEFAULT NULL,
  `date_fin` datetime DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'en_cours',
  `score` decimal(8,2) NOT NULL DEFAULT 0,
  `note_sur` decimal(8,2) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_eval_sess` (`evaluation_id`, `eleve_id`),
  KEY `idx_eval_sess_eleve` (`eleve_id`),
  CONSTRAINT `fk_eval_sess_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations_en_ligne` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_sess_eleve` FOREIGN KEY (`eleve_id`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evaluation_reponses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `reponse_donnee` json DEFAULT NULL,
  `correct` tinyint(1) DEFAULT NULL,
  `points_obtenus` decimal(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_eval_rep` (`session_id`, `question_id`),
  KEY `idx_eval_rep_q` (`question_id`),
  CONSTRAINT `fk_eval_rep_sess` FOREIGN KEY (`session_id`) REFERENCES `evaluation_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_rep_q` FOREIGN KEY (`question_id`) REFERENCES `evaluation_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evaluation_statistiques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `nb_reponses` int(11) NOT NULL DEFAULT 0,
  `nb_correct` int(11) NOT NULL DEFAULT 0,
  `taux_reussite` decimal(5,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_eval_stat` (`evaluation_id`, `question_id`),
  CONSTRAINT `fk_eval_stat_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations_en_ligne` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_stat_q` FOREIGN KEY (`question_id`) REFERENCES `evaluation_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
