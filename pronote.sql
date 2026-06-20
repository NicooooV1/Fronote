-- ============================================================
-- Pronote — Schéma complet de la base de données
-- Version définitive — Aucune migration requise
-- Intègre les modules : base, EDT, appel, discipline, annonces,
-- bulletins, devoirs rendus, compétences, documents, paramètres,
-- notifications, réunions, support, archivage, inscriptions,
-- orientation, signalements, bibliothèque, clubs, santé,
-- RGPD, examens, besoins, personnel, salles, périscolaire,
-- stages, transports, facturation, ressources, diplômes
-- ============================================================
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET SESSION FOREIGN_KEY_CHECKS = 0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- NOTE : La création de la base et USE sont gérés par install.php.
-- Ne PAS ajouter CREATE DATABASE / USE ici.

-- ============================================================
-- Drop (ordre inverse des FK)
-- ============================================================
DROP VIEW  IF EXISTS `v_users`;

DROP TABLE IF EXISTS `event_exceptions`;

-- Tables Marketplace & Themes (v2.1+)
DROP TABLE IF EXISTS `theme_token_overrides`;
DROP TABLE IF EXISTS `themes`;
DROP TABLE IF EXISTS `marketplace_installs`;
-- Tables Sécurité (v2.4+)
DROP TABLE IF EXISTS `ip_blocklist`;
-- Tables Push Notifications (v2.2+)
DROP TABLE IF EXISTS `push_subscriptions`;
-- Tables SMS (v2.3+)
DROP TABLE IF EXISTS `sms_log`;
DROP TABLE IF EXISTS `sms_config`;
-- Tables Email amélioré (v2.3+)
DROP TABLE IF EXISTS `email_templates`;
DROP TABLE IF EXISTS `email_log`;
-- Tables Paiement (v2.7+)
DROP TABLE IF EXISTS `payments`;
-- Tables Signatures (v2.7+)
DROP TABLE IF EXISTS `signatures`;

-- Tables ajoutées (phases 2+)
DROP TABLE IF EXISTS `app_metrics`;
DROP TABLE IF EXISTS `module_settings_schema`;
DROP TABLE IF EXISTS `job_queue`;
DROP TABLE IF EXISTS `oauth_bindings`;
DROP TABLE IF EXISTS `webhooks`;
DROP TABLE IF EXISTS `api_tokens`;
DROP TABLE IF EXISTS `feature_flags`;
DROP TABLE IF EXISTS `translations`;
DROP TABLE IF EXISTS `dashboard_layouts`;
DROP TABLE IF EXISTS `user_dashboard_config`;
DROP TABLE IF EXISTS `dashboard_widgets`;
DROP TABLE IF EXISTS `import_export_logs`;
DROP TABLE IF EXISTS `user_profiles`;
DROP TABLE IF EXISTS `technicien_audit_log`;
DROP TABLE IF EXISTS `technicien_access`;
DROP TABLE IF EXISTS `module_permissions`;
DROP TABLE IF EXISTS `rbac_permissions`;

-- Système : modules, SMTP, PDF templates
DROP TABLE IF EXISTS `pdf_templates`;
DROP TABLE IF EXISTS `modules_config`;
DROP TABLE IF EXISTS `smtp_config`;

-- M44 Diplômes
-- M36 Ressources pédagogiques
-- M33 Facturation
-- M32 Transports / Internat
-- M17 Stages
-- M16 Périscolaire
-- M40 Salles & Matériels
-- M39 Personnel
-- M37 Besoins particuliers
-- M27 Examens
-- M23 RGPD
DROP TABLE IF EXISTS `rgpd_demandes`;
DROP TABLE IF EXISTS `rgpd_consentements`;
-- M31 Santé / Infirmerie
-- M30 Clubs
-- M29 Bibliothèque
-- M45 Signalements
-- M28 Orientation
-- M26 Inscriptions
-- M35 Archivage
-- M34 Support
-- M14 Réunions
-- M12 Notifications
DROP TABLE IF EXISTS `notification_preferences`;
DROP TABLE IF EXISTS `notifications_globales`;
-- Paramètres utilisateur
DROP TABLE IF EXISTS `user_settings`;
-- Documents administratifs
-- M38 Compétences
-- M08 Devoirs rendus
-- M07 Bulletins
-- M11 Annonces / Sondages
-- M06 Discipline
-- M04 Appel
-- M03 Emploi du temps
-- Base (messagerie, cahier de textes, notes, absences, etc.)
DROP TABLE IF EXISTS `professeur_classes`;
DROP TABLE IF EXISTS `parent_eleve`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `api_rate_limits`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `remember_tokens`;
DROP TABLE IF EXISTS `user_notification_preferences`;
DROP TABLE IF EXISTS `demandes_reinitialisation`;
DROP TABLE IF EXISTS `session_security`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `matieres`;
DROP TABLE IF EXISTS `classes`;
DROP TABLE IF EXISTS `vie_scolaire`;
DROP TABLE IF EXISTS `parents`;
DROP TABLE IF EXISTS `professeurs`;
DROP TABLE IF EXISTS `eleves`;
DROP TABLE IF EXISTS `administrateurs`;
DROP TABLE IF EXISTS `super_admins`;
DROP TABLE IF EXISTS `etablissements`;
DROP TABLE IF EXISTS `etablissement_info`;
DROP TABLE IF EXISTS `periodes`;

-- ============================================================
-- 1. TABLES RÉFÉRENTIELLES
-- ============================================================

CREATE TABLE `etablissements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL DEFAULT 'Établissement Scolaire',
  `code` varchar(50) NOT NULL COMMENT 'Code unique court (ex: lycee-hugo)',
  `type` enum('college','lycee','superieur','primaire','polyvalent') NOT NULL DEFAULT 'college',
  `adresse` varchar(255) DEFAULT NULL,
  `code_postal` varchar(10) DEFAULT NULL,
  `ville` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `chef_etablissement` varchar(150) DEFAULT NULL,
  `academie` varchar(100) DEFAULT NULL,
  `code_uai` varchar(20) DEFAULT NULL,
  `annee_scolaire` varchar(10) DEFAULT '2025-2026',
  `logo` varchar(255) DEFAULT NULL,
  `couleur_primaire` varchar(7) DEFAULT '#003366',
  `couleur_secondaire` varchar(7) DEFAULT '#0066cc',
  `css_personnalise` text DEFAULT NULL,
  `favicon` varchar(255) DEFAULT NULL,
  `pied_de_page` text DEFAULT NULL,
  `default_locale` varchar(10) NOT NULL DEFAULT 'fr',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `slug` varchar(120) DEFAULT NULL,
  `status` enum('draft','onboarding','active','suspended','archived','deleted','purged') NOT NULL DEFAULT 'active',
  `created_from_invite_id` int(11) DEFAULT NULL,
  `created_by_tenant_account_id` int(11) DEFAULT NULL,
  `created_by_platform_account_id` int(11) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_etab_slug` (`slug`),
  KEY `idx_etab_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `etablissements` (`id`, `nom`, `code`, `type`) VALUES (1, 'Établissement Scolaire', 'default', 'college');

CREATE TABLE `super_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(32) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant` (`identifiant`),
  UNIQUE KEY `mail` (`mail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `periodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `numero` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'trimestre',
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `annee_scolaire` varchar(10) DEFAULT '2025-2026',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `matieres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `coefficient` decimal(3,2) DEFAULT 1.00,
  `couleur` varchar(7) DEFAULT '#3498db',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(50) NOT NULL,
  `niveau` varchar(20) NOT NULL,
  `annee_scolaire` varchar(10) NOT NULL,
  `professeur_principal_id` int(11) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom_annee` (`nom`, `annee_scolaire`),
  KEY `idx_professeur_principal` (`professeur_principal_id`),
  CONSTRAINT `fk_classes_prof_principal` FOREIGN KEY (`professeur_principal_id`) REFERENCES `professeurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. TABLES UTILISATEURS
-- ============================================================

CREATE TABLE `administrateurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'administrateur',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  `failed_login_attempts` int(3) DEFAULT 0,
  `locked_until` datetime NULL DEFAULT NULL,
  `password_changed_at` datetime NULL DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant` (`identifiant`),
  UNIQUE KEY `mail` (`mail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eleves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `date_naissance` date NOT NULL,
  `classe` varchar(50) NOT NULL,
  `lieu_naissance` varchar(100) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  `failed_login_attempts` int(3) DEFAULT 0,
  `locked_until` datetime NULL DEFAULT NULL,
  `password_changed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mail` (`mail`),
  UNIQUE KEY `identifiant` (`identifiant`),
  KEY `idx_classe` (`classe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `professeurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `professeur_principal` varchar(50) NOT NULL DEFAULT 'non',
  `matiere` varchar(100) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  `failed_login_attempts` int(3) DEFAULT 0,
  `locked_until` datetime NULL DEFAULT NULL,
  `password_changed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mail` (`mail`),
  UNIQUE KEY `identifiant` (`identifiant`),
  KEY `idx_matiere` (`matiere`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `metier` varchar(100) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `est_parent_eleve` enum('oui','non') NOT NULL DEFAULT 'non',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  `failed_login_attempts` int(3) DEFAULT 0,
  `locked_until` datetime NULL DEFAULT NULL,
  `password_changed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mail` (`mail`),
  UNIQUE KEY `identifiant` (`identifiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vie_scolaire` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `est_CPE` enum('oui','non') NOT NULL DEFAULT 'non',
  `est_infirmerie` enum('oui','non') NOT NULL DEFAULT 'non',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime NULL DEFAULT NULL,
  `failed_login_attempts` int(3) DEFAULT 0,
  `locked_until` datetime NULL DEFAULT NULL,
  `password_changed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mail` (`mail`),
  UNIQUE KEY `identifiant` (`identifiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parent_eleve` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_parent` int(11) NOT NULL,
  `id_eleve` int(11) NOT NULL,
  `lien` varchar(50) DEFAULT 'parent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_lien` (`id_parent`, `id_eleve`),
  KEY `idx_parent` (`id_parent`),
  KEY `idx_eleve` (`id_eleve`),
  CONSTRAINT `fk_pe_parent` FOREIGN KEY (`id_parent`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `professeur_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_professeur` int(11) NOT NULL,
  `nom_classe` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_prof_class` (`id_professeur`, `nom_classe`),
  CONSTRAINT `fk_pc_prof` FOREIGN KEY (`id_professeur`) REFERENCES `professeurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. MODULE NOTES
-- ============================================================

-- ============================================================
-- 4. MODULE ABSENCES / RETARDS / JUSTIFICATIFS
-- ============================================================

-- ============================================================
-- 5. MODULE CAHIER DE TEXTES
-- ============================================================

-- ============================================================
-- 6. MODULE AGENDA
-- ============================================================

-- ============================================================
-- 7. MODULE MESSAGERIE
-- ============================================================

CREATE TABLE `user_notification_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 0,
  `browser_notifications` tinyint(1) DEFAULT 1,
  `notification_sound` tinyint(1) DEFAULT 1,
  `mention_notifications` tinyint(1) DEFAULT 1,
  `reply_notifications` tinyint(1) DEFAULT 1,
  `important_notifications` tinyint(1) DEFAULT 1,
  `digest_frequency` enum('never','daily','weekly') DEFAULT 'never',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. SÉCURITÉ & ADMINISTRATION
-- ============================================================

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(50) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rate_user_action` (`user_id`, `user_type`, `action_type`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(64) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_identifier` (`identifier`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `token_hash` varchar(64) NOT NULL COMMENT 'SHA-256 du token',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token_hash`),
  KEY `idx_user` (`user_id`, `user_type`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `identifier` varchar(190) DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`, `attempted_at`),
  KEY `idx_identifier_time` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- (oauth_bindings est défini plus bas dans ce schéma — pas de duplication ici.)

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action` varchar(100) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `model_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_type` varchar(20) DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_model` (`model`, `model_id`),
  KEY `idx_user` (`user_id`, `user_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `session_security` (
  `id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `user_type`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `demandes_reinitialisation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `date_demande` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `date_traitement` datetime DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. MODULE EMPLOI DU TEMPS (M03)
-- ============================================================

-- Indisponibilités enseignants — contrainte DURE du moteur EDT (CDC §7.3).
-- Une ligne = un créneau récurrent où le prof ne peut pas être planifié.
-- Préférences pédagogiques enseignants — contraintes SOUPLES (CDC §7.1/7.2).
-- Maquette horaire : besoins de cours à planifier — entrée du moteur de génération (CDC §6).
-- Une ligne = "classe X a besoin de N créneaux de matière M avec prof P".
-- ============================================================
-- 10. MODULE APPEL / PRÉSENCE (M04)
-- ============================================================

-- ============================================================
-- 11. MODULE DISCIPLINE / SANCTIONS (M06)
-- ============================================================

-- ============================================================
-- 12. MODULE ANNONCES / SONDAGES (M11)
-- ============================================================

-- ============================================================
-- 13. MODULE BULLETINS / BILANS PÉRIODIQUES (M07)
-- ============================================================

-- ============================================================
-- 14. MODULE DEVOIRS EN LIGNE — rendus élèves (M08)
-- ============================================================

-- ============================================================
-- 15. MODULE COMPÉTENCES / ÉVALUATIONS (M38)
-- ============================================================

-- ============================================================
-- 16. MODULE DOCUMENTS ADMINISTRATIFS
-- ============================================================

-- ============================================================
-- 17. MODULE PARAMÈTRES UTILISATEUR
-- ============================================================

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `theme` varchar(20) NOT NULL DEFAULT 'classic',
  `langue` varchar(5) NOT NULL DEFAULT 'fr',
  `notifications_email` tinyint(1) NOT NULL DEFAULT 1,
  `notifications_web` tinyint(1) NOT NULL DEFAULT 1,
  `taille_police` varchar(10) NOT NULL DEFAULT 'normal',
  `sidebar_collapsed` tinyint(1) NOT NULL DEFAULT 0,
  `avatar_chemin` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `banner_color` VARCHAR(7) DEFAULT NULL COMMENT 'Couleur de bannière profil (hex)',
  `banner_image` VARCHAR(255) DEFAULT NULL COMMENT 'Image de bannière profil',
  `accueil_config` JSON DEFAULT NULL COMMENT 'Configuration widgets accueil (JSON)',
  `keybindings` TEXT DEFAULT NULL COMMENT 'Raccourcis clavier personnalisés (JSON)',
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_settings` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modules épinglés par utilisateur (accès rapide topbar)
CREATE TABLE `user_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `module_key` varchar(64) NOT NULL COMMENT 'clé module, ou page:<sha1(url)> pour un favori de page',
  `target_type` enum('module','page') NOT NULL DEFAULT 'module',
  `target_url` varchar(255) DEFAULT NULL COMMENT 'chemin app interne pour les favoris de page',
  `label` varchar(150) DEFAULT NULL,
  `icon` varchar(64) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_favorite` (`user_id`, `user_type`, `module_key`),
  KEY `idx_user_favorites_user` (`user_id`, `user_type`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codes de secours 2FA (récupération si l'appareil d'authentification est perdu).
-- code_hash = SHA-256 du code normalisé. Codes à usage unique (used_at).
CREATE TABLE `user_backup_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backup_codes_user` (`user_id`, `user_type`),
  KEY `idx_backup_codes_lookup` (`user_id`, `user_type`, `code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. MODULE NOTIFICATIONS GLOBALES (M12)
-- ============================================================

CREATE TABLE `notifications_globales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'nouvelle_note, absence, message, devoir, bulletin, incident, annonce, reunion, general',
  `titre` varchar(255) NOT NULL,
  `contenu` text DEFAULT NULL,
  `lien` varchar(500) DEFAULT NULL COMMENT 'URL relative vers la ressource concernée',
  `icone` varchar(50) DEFAULT 'fa-bell',
  `importance` enum('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  `lu` tinyint(1) NOT NULL DEFAULT 0,
  `date_lecture` datetime DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL COMMENT 'Module source: notes, absences, messagerie...',
  `source_id` int(11) DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `user_type`),
  KEY `idx_notif_lu` (`lu`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_date` (`date_creation`),
  KEY `idx_notif_importance` (`importance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `type_notification` varchar(50) NOT NULL COMMENT 'nouvelle_note, absence, message, devoir, bulletin, incident',
  `canal_email` tinyint(1) NOT NULL DEFAULT 1,
  `canal_web` tinyint(1) NOT NULL DEFAULT 1,
  `canal_push` tinyint(1) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pref` (`user_id`, `user_type`, `type_notification`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. MODULE RÉUNIONS / RDV / CONVOCATIONS (M14)
-- ============================================================

-- ============================================================
-- 20. MODULE SUPPORT & AIDE (M34)
-- ============================================================

-- ============================================================
-- 21. MODULE ARCHIVAGE FIN D'ANNÉE (M35)
-- ============================================================

-- ============================================================
-- 22. MODULE INSCRIPTIONS / RÉINSCRIPTIONS (M26)
-- ============================================================

-- ============================================================
-- 23. MODULE ORIENTATION & PARCOURS (M28)
-- ============================================================

-- ============================================================
-- 24. MODULE SIGNALEMENTS — anti-harcèlement (M45)
-- ============================================================

-- ============================================================
-- 25. MODULE BIBLIOTHÈQUE / CDI (M29)
-- ============================================================

-- ============================================================
-- 26. MODULE CLUBS & ASSOCIATIONS (M30)
-- ============================================================

-- ============================================================
-- 27. MODULE SANTÉ / INFIRMERIE (M31)
-- ============================================================

-- ============================================================
-- 28. MODULE RGPD & AUDIT (M23)
-- ============================================================

CREATE TABLE `rgpd_consentements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` enum('administrateur','professeur','eleve','parent','vie_scolaire') NOT NULL,
  `type_consentement` varchar(100) NOT NULL COMMENT 'ex: sms, photo, donnees_medicales',
  `consenti` tinyint(1) NOT NULL DEFAULT 0,
  `date_consentement` datetime DEFAULT NULL,
  `date_retrait` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `user_type`),
  KEY `idx_type` (`type_consentement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rgpd_demandes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` enum('administrateur','professeur','eleve','parent','vie_scolaire') NOT NULL,
  `type_demande` enum('acces','rectification','suppression','portabilite','opposition') NOT NULL,
  `description` text DEFAULT NULL,
  `statut` enum('en_attente','en_cours','traitee','refusee') NOT NULL DEFAULT 'en_attente',
  `reponse` text DEFAULT NULL,
  `traite_par` int(11) DEFAULT NULL,
  `date_demande` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_traitement` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 29. MODULE EXAMENS & ÉPREUVES (M27)
-- ============================================================

-- ============================================================
-- 30. MODULE BESOINS PARTICULIERS — PPS/PAP/PPRE (M37)
-- ============================================================

-- ============================================================
-- 31. MODULE GESTION DU PERSONNEL (M39)
-- ============================================================

-- ============================================================
-- 32. MODULE SALLES & MATÉRIELS (M40)
-- ============================================================

-- ============================================================
-- 33. MODULE SERVICES PÉRISCOLAIRES — Cantine, Garderie (M16bis)
-- ============================================================

-- ============================================================
-- 34. MODULE STAGES & ALTERNANCE (M17bis)
-- ============================================================

-- ============================================================
-- 35. MODULE TRANSPORTS / INTERNAT (M32)
-- ============================================================

-- ============================================================
-- 36. MODULE FACTURATION (M33)
-- ============================================================

-- ============================================================
-- 37. MODULE CONTENUS PÉDAGOGIQUES AVANCÉS (M36)
-- ============================================================

-- ============================================================
-- 38. MODULE DIPLÔMES & RELEVÉS (M44)
-- ============================================================

-- ============================================================
-- 38. CONFIGURATION SYSTÈME (SMTP, MODULES, PDF)
-- ============================================================

CREATE TABLE `smtp_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL DEFAULT '',
  `port` int(11) NOT NULL DEFAULT 587,
  `username` varchar(255) NOT NULL DEFAULT '',
  `password` varchar(500) NOT NULL DEFAULT '',
  `encryption` enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `from_address` varchar(255) NOT NULL DEFAULT '',
  `from_name` varchar(255) NOT NULL DEFAULT '',
  `reply_to` varchar(255) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `smtp_config` (`id`, `enabled`) VALUES (1, 0);

CREATE TABLE `modules_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) NOT NULL DEFAULT 'fas fa-puzzle-piece',
  `route_path` varchar(100) DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'general',
  `enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Désactivé par défaut ; activé via l''admin (injecte+vérifie le SQL du module)',
  `establishment_types` JSON DEFAULT NULL COMMENT 'null = tous types d''établissement',
  `config_json` text DEFAULT NULL,
  `roles_autorises` json DEFAULT NULL COMMENT 'Rôles autorisés à voir ce module (null = tous)',
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `sidebar_sort` int(11) NOT NULL DEFAULT 100,
  `is_core` tinyint(1) NOT NULL DEFAULT 0,
  `sidebar_hidden` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Module installé mais masqué de la navigation (onboarding, marketplace technique...)',
  `topbar_category` varchar(50) DEFAULT NULL COMMENT 'Catégorie dans la topbar (Pédagogie, Vie scol., etc.)',
  `topbar_sort_order` int(11) NOT NULL DEFAULT 50 COMMENT 'Ordre de tri dans la catégorie topbar',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modules système (ne peuvent pas être désactivés)
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('accueil',         'Accueil',                'Page d''accueil et tableau de bord',                 'fas fa-home',               'navigation', 1, 1,  1),
('messagerie',      'Messagerie',             'Messagerie interne entre utilisateurs',              'fas fa-envelope',           'navigation', 0, 2,  0),
('parametres',      'Paramètres',             'Paramètres du compte utilisateur',                   'fas fa-cog',                'navigation', 1, 3,  1),
('notifications',   'Notifications',          'Centre de notifications',                            'fas fa-bell',               'navigation', 1, 4,  1);

-- Modules scolaires
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('notes',           'Notes',                  'Gestion des notes et évaluations',                   'fas fa-chart-bar',          'scolaire', 1, 10, 0),
('agenda',          'Agenda',                 'Agenda et événements scolaires',                     'fas fa-calendar',           'scolaire', 1, 11, 0),
('cahierdetextes',  'Cahier de textes',       'Cahier de textes numérique',                         'fas fa-book',               'scolaire', 1, 12, 0),
('emploi_du_temps', 'Emploi du temps',        'Emploi du temps et modifications',                   'fas fa-table',              'scolaire', 1, 13, 0),
('bulletins',       'Bulletins',              'Bulletins scolaires par période',                     'fas fa-file-alt',           'scolaire', 1, 14, 0),
('competences',     'Compétences',            'Évaluation par compétences (socle commun)',           'fas fa-clipboard-list',     'scolaire', 1, 15, 0),
('devoirs',         'Devoirs en ligne',       'Remise de devoirs en ligne',                         'fas fa-tasks',              'scolaire', 1, 16, 0),
('examens',         'Examens',                'Organisation des examens et épreuves',                'fas fa-file-signature',     'scolaire', 1, 17, 0);

-- Modules vie scolaire
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('absences',        'Absences',               'Suivi des absences et justificatifs',                'fas fa-calendar-times',     'vie_scolaire', 1, 20, 0),
('appel',           'Appel',                  'Faire l''appel en classe',                           'fas fa-clipboard-check',    'vie_scolaire', 1, 21, 0),
('discipline',      'Discipline',             'Incidents, sanctions et retenues',                   'fas fa-gavel',              'vie_scolaire', 1, 22, 0),
('vie_scolaire',    'Vie scolaire',           'Tableau de bord vie scolaire',                       'fas fa-user-shield',        'vie_scolaire', 1, 23, 0),
('reporting',       'Reporting',              'Rapports et statistiques',                           'fas fa-chart-line',         'vie_scolaire', 1, 24, 0),
('signalements',    'Signalements',           'Signalements anonymes (harcèlement...)',             'fas fa-shield-alt',         'vie_scolaire', 1, 25, 0),
('besoins',         'Besoins particuliers',   'Suivi des élèves à besoins spécifiques (PAP, PPS)', 'fas fa-hand-holding-heart', 'vie_scolaire', 1, 26, 0);

-- Modules communication
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('annonces',        'Annonces',               'Annonces et sondages',                              'fas fa-bullhorn',           'communication', 1, 30, 0),
('reunions',        'Réunions',               'Organisation des réunions parents-profs',            'fas fa-handshake',          'communication', 1, 31, 0),
('documents',       'Documents',              'Documents administratifs',                           'fas fa-folder-open',        'communication', 1, 32, 0);

-- Modules établissement
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('trombinoscope',   'Trombinoscope',          'Annuaire avec photos',                              'fas fa-users',              'etablissement', 1, 40, 0),
('bibliotheque',    'Bibliothèque',           'Catalogue et gestion des emprunts',                 'fas fa-book-reader',        'etablissement', 1, 41, 0),
('clubs',           'Clubs',                  'Clubs et activités parascolaires',                  'fas fa-users',              'etablissement', 1, 42, 0),
('orientation',     'Orientation',            'Fiches d''orientation et vœux',                     'fas fa-compass',            'etablissement', 1, 43, 0),
('inscriptions',    'Inscriptions',           'Inscriptions et réinscriptions en ligne',           'fas fa-user-plus',          'etablissement', 1, 44, 0),
('infirmerie',      'Infirmerie',             'Passages infirmerie et fiches santé',               'fas fa-heartbeat',          'etablissement', 1, 45, 0),
('ressources',      'Ressources',             'Ressources pédagogiques partagées',                 'fas fa-book-open',          'etablissement', 1, 46, 0),
('diplomes',        'Diplômes',               'Gestion et délivrance des diplômes',                'fas fa-graduation-cap',     'etablissement', 1, 47, 0);

-- Modules logistique
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('periscolaire',    'Périscolaire',           'Cantine, garderie, activités périscolaires',        'fas fa-utensils',           'logistique', 1, 50, 0),
('stages',          'Stages',                 'Conventions de stage (3e, lycée...)',                'fas fa-briefcase',          'logistique', 1, 51, 0),
('transports',      'Transports',             'Lignes de transport et inscriptions',               'fas fa-bus',                'logistique', 1, 52, 0),
('facturation',     'Facturation',            'Factures et paiements en ligne',                    'fas fa-file-invoice-dollar','logistique', 1, 53, 0),
('salles',          'Salles & Matériels',     'Réservation de salles et prêt de matériel',         'fas fa-door-open',          'logistique', 1, 54, 0),
('personnel',       'Gestion personnel',      'Absences et remplacements du personnel',            'fas fa-user-tie',           'logistique', 1, 55, 0);

-- Modules système
INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('archivage',       'Archivage',              'Archivage annuel des données',                      'fas fa-archive',            'systeme', 1, 60, 0),
('rgpd',            'RGPD & Audit',           'Conformité RGPD et journal d''audit',               'fas fa-shield-alt',         'systeme', 1, 61, 0),
('support',         'Aide & Support',         'FAQ et tickets de support',                         'fas fa-question-circle',    'systeme', 1, 62, 0);

CREATE TABLE `pdf_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'bulletin, convocation, convention, attestation, diplome, generic',
  `description` text DEFAULT NULL,
  `header_html` text DEFAULT NULL,
  `footer_html` text DEFAULT NULL,
  `body_css` text DEFAULT NULL,
  `page_format` varchar(10) NOT NULL DEFAULT 'A4',
  `orientation` enum('portrait','landscape') NOT NULL DEFAULT 'portrait',
  `margins_json` varchar(200) NOT NULL DEFAULT '{"top":15,"right":10,"bottom":15,"left":10}',
  `show_logo` tinyint(1) NOT NULL DEFAULT 1,
  `show_etablissement` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates PDF par défaut
INSERT INTO `pdf_templates` (`name`, `type`, `description`, `is_default`, `header_html`, `footer_html`, `body_css`) VALUES
('Bulletin standard', 'bulletin', 'Template par défaut pour les bulletins scolaires', 1,
 '<div style="text-align:center;border-bottom:2px solid #333;padding-bottom:10px;margin-bottom:15px"><h2 style="margin:0">{{etablissement_nom}}</h2><p style="margin:2px 0;font-size:11px">{{etablissement_adresse}} {{etablissement_cp}} {{etablissement_ville}}</p><p style="margin:2px 0;font-size:11px">Tél: {{etablissement_tel}} — {{etablissement_email}}</p></div>',
 '<div style="text-align:center;border-top:1px solid #ccc;padding-top:8px;font-size:9px;color:#666">{{etablissement_nom}} — Bulletin généré le {{date}} — Page {{page}}/{{pages}}</div>',
 'body{font-family:Arial,sans-serif;font-size:12px;color:#333} table{width:100%;border-collapse:collapse;margin:10px 0} th,td{border:1px solid #ddd;padding:6px 8px;text-align:left} th{background:#f5f5f5;font-weight:bold} .moyenne{font-weight:bold;color:#2563eb}'),
('Convocation standard', 'convocation', 'Template pour les convocations aux réunions et examens', 1,
 '<div style="text-align:right;margin-bottom:20px"><strong>{{etablissement_nom}}</strong><br>{{etablissement_adresse}}<br>{{etablissement_cp}} {{etablissement_ville}}</div>',
 '<div style="text-align:center;font-size:9px;color:#666;border-top:1px solid #ccc;padding-top:8px">Document officiel — {{etablissement_nom}} — {{date}}</div>',
 'body{font-family:Arial,sans-serif;font-size:12px;color:#333;line-height:1.6}'),
('Attestation standard', 'attestation', 'Template pour les attestations et certificats', 1,
 '<div style="text-align:center;margin-bottom:30px"><h1 style="margin:0;color:#1a365d">{{etablissement_nom}}</h1><p style="color:#666">Académie de {{etablissement_academie}}</p></div>',
 '<div style="text-align:center;font-size:9px;color:#666;margin-top:30px">{{etablissement_nom}} — {{etablissement_adresse}} {{etablissement_cp}} {{etablissement_ville}}</div>',
 'body{font-family:Georgia,serif;font-size:13px;color:#333;line-height:1.8}'),
('Export générique', 'generic', 'Template générique pour les exports de données (listes, tableaux)', 1,
 '<div style="border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:15px"><strong>{{etablissement_nom}}</strong> — {{title}}</div>',
 '<div style="text-align:right;font-size:9px;color:#999;border-top:1px solid #eee;padding-top:5px">Généré le {{date}} — Page {{page}}/{{pages}}</div>',
 'body{font-family:Arial,sans-serif;font-size:11px;color:#333} table{width:100%;border-collapse:collapse} th,td{border:1px solid #ddd;padding:4px 6px} th{background:#f0f0f0;font-size:10px}');

-- ============================================================
-- DONNÉES PAR DÉFAUT
-- ============================================================

-- Créneaux horaires type secondaire

-- Compétences socle commun simplifié

-- FAQ par défaut

-- ============================================================
-- MODULE CANTINE (M18) — Restauration scolaire dédiée
-- ============================================================

-- ============================================================
-- MODULE INTERNAT (M19)
-- ============================================================

-- ============================================================
-- MODULE GARDERIE (M20)
-- ============================================================

-- ============================================================
-- MODULE PROJETS PÉDAGOGIQUES (M41)
-- ============================================================

-- ============================================================
-- MODULE PARCOURS ÉDUCATIFS (M42)
-- ============================================================

-- ============================================================
-- MODULE VIE ASSOCIATIVE / MDL (M43)
-- ============================================================

-- ============================================================
-- AGENDA : champ récurrence (Priorité 3)
-- ============================================================


-- Single-occurrence exceptions for recurring events
-- ============================================================
-- 6 nouveaux modules dans modules_config
-- ============================================================

INSERT INTO `modules_config` (`module_key`, `label`, `description`, `icon`, `category`, `enabled`, `sort_order`, `is_core`) VALUES
('cantine',              'Cantine',               'Restauration scolaire : menus, réservations, pointage',     'fas fa-utensils',           'logistique', 1, 56, 0),
('internat',             'Internat',              'Gestion de l''internat : chambres, affectations, vie',      'fas fa-bed',                'logistique', 1, 57, 0),
('garderie',             'Garderie',              'Accueil périscolaire : matin, soir, mercredi',              'fas fa-child',              'logistique', 1, 58, 0),
('projets_pedagogiques', 'Projets pédagogiques',  'EPI, projets de classe, sorties et voyages',                'fas fa-project-diagram',    'scolaire',   1, 18, 0),
('parcours_educatifs',   'Parcours éducatifs',    'Parcours Avenir, Santé, Citoyen, PEAC',                     'fas fa-route',              'scolaire',   1, 19, 0),
('vie_associative',      'Vie associative',       'MDL, FSE, associations et trésorerie',                      'fas fa-hands-helping',      'etablissement', 1, 48, 0);

-- ============================================================
-- VUE UNIFIÉE DES UTILISATEURS
-- ============================================================

CREATE OR REPLACE VIEW `v_users` AS
  SELECT id, prenom, nom, CONCAT(prenom, ' ', nom) AS nom_complet, 'eleve' AS user_type FROM eleves
  UNION ALL
  SELECT id, prenom, nom, CONCAT(prenom, ' ', nom), 'parent' FROM parents
  UNION ALL
  SELECT id, prenom, nom, CONCAT(prenom, ' ', nom), 'professeur' FROM professeurs
  UNION ALL
  SELECT id, prenom, nom, CONCAT(prenom, ' ', nom), 'vie_scolaire' FROM vie_scolaire
  UNION ALL
  SELECT id, prenom, nom, CONCAT(prenom, ' ', nom), 'administrateur' FROM administrateurs;

-- ============================================================
-- M99 : RBAC Permissions dynamiques
-- ============================================================
CREATE TABLE `rbac_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role` VARCHAR(50) NOT NULL,
  `permission` VARCHAR(100) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_role_permission` (`role`, `permission`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RBAC : catalogue des rôles (source de vérité = RoleCatalog en code,
-- synchronisé ici par RoleSync). Voir API/Security/Authorization.php.
-- ============================================================
CREATE TABLE `rbac_roles` (
  `role_key`    VARCHAR(50)  NOT NULL,
  `label`       VARCHAR(120) NOT NULL,
  `tier`        VARCHAR(40)  NOT NULL DEFAULT 'autre',
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sensitive`   TINYINT(1)   NOT NULL DEFAULT 0,
  `description` TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_key`),
  KEY `idx_tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RBAC : attribution multi-rôles à un utilisateur, scopée (établissement / classes /
-- élèves assignés via scope_json) et éventuellement temporaire (valid_from/until).
-- Le "type de compte" reste le rôle de base ; cette table ajoute des rôles.
-- ============================================================
CREATE TABLE `user_roles` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `user_type`        VARCHAR(30)  NOT NULL,            -- table de compte : eleve/parent/professeur/vie_scolaire/administrateur/super_admin/technicien
  `user_id`          INT          NOT NULL,
  `role_key`         VARCHAR(50)  NOT NULL,
  `etablissement_id` INT          DEFAULT NULL,        -- NULL = tous (réservé super_admin / multi-établissement)
  `scope_type`       VARCHAR(30)  NOT NULL DEFAULT 'establishment', -- global|establishment|establishments|self|children|assigned|own_classes
  `scope_json`       JSON         DEFAULT NULL,        -- ex: {"class_ids":[..]}, {"student_ids":[..]}, {"etablissement_ids":[..]}
  `valid_from`       DATETIME     DEFAULT NULL,
  `valid_until`      DATETIME     DEFAULT NULL,        -- NULL = permanent (accès temporaires : technicien, invité, vacataire…)
  `granted_by_type`  VARCHAR(30)  DEFAULT NULL,
  `granted_by_id`    INT          DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_role_etab` (`user_type`, `user_id`, `role_key`, `etablissement_id`),
  KEY `idx_user` (`user_type`, `user_id`),
  KEY `idx_role_key` (`role_key`),
  KEY `idx_validity` (`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RBAC : valeurs de périmètre normalisées (complète user_roles.scope_json).
-- Une ligne par (attribution de rôle, type de périmètre, ressource ciblée).
-- Ex. un prof sur 3 classes = 3 lignes scope_type='class'. Requêtable en SQL
-- (contrairement à scope_json), tout en gardant scope_json comme repli.
-- ============================================================
CREATE TABLE `user_role_scope_values` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `user_role_id`  INT          NOT NULL,
  `scope_type`    VARCHAR(40)  NOT NULL,  -- class|classes|subject|subjects|student|students|establishment|group
  `scope_id`      INT          NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_urs` (`user_role_id`, `scope_type`, `scope_id`),
  KEY `idx_urs_role` (`user_role_id`),
  KEY `idx_urs_target` (`scope_type`, `scope_id`),
  CONSTRAINT `fk_urs_role` FOREIGN KEY (`user_role_id`) REFERENCES `user_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Relations entre comptes (modèle cible : un lien métier sujet → ressource).
-- Unifie parent_eleve / professeur_classes / suivis AESH-médico-psycho-sociaux.
-- Identité = (type de compte, id) pour rester compatible avec le modèle legacy
-- AVANT la table accounts unifiée ; transposable plus tard sur accounts.id.
-- ============================================================
CREATE TABLE `account_relationships` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `source_type`       VARCHAR(30)  NOT NULL,  -- compte sujet : parent/professeur/vie_scolaire/...
  `source_id`         INT          NOT NULL,
  `target_type`       VARCHAR(30)  NOT NULL,  -- ressource/compte cible : eleve/classe/...
  `target_id`         INT          NOT NULL,
  `relationship_type` VARCHAR(40)  NOT NULL,  -- parent_of|legal_guardian_of|financial_responsible_of|aesh_of|teacher_of|main_teacher_of|tutor_of|company_tutor_of|medical_follow_of|psychological_follow_of|social_follow_of
  `etablissement_id`  INT          DEFAULT NULL,
  `starts_at`         DATETIME     DEFAULT NULL,
  `expires_at`        DATETIME     DEFAULT NULL,  -- NULL = permanent
  `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by_type`   VARCHAR(30)  DEFAULT NULL,
  `created_by_id`     INT          DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_rel` (`source_type`, `source_id`, `target_type`, `target_id`, `relationship_type`),
  KEY `idx_rel_source` (`source_type`, `source_id`),
  KEY `idx_rel_target` (`target_type`, `target_id`),
  KEY `idx_rel_type` (`relationship_type`),
  KEY `idx_rel_active` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Journal d'audit dédié aux rôles/relations/accès sensibles (cf. §5.9).
-- Distinct d'audit_log (changements de données) : trace QUI a donné/retiré QUOI
-- à QUI, et les accès sensibles, avec ancienne/nouvelle valeur.
-- ============================================================
CREATE TABLE `user_role_audit_logs` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `actor_type`   VARCHAR(30)  DEFAULT NULL,
  `actor_id`     INT          DEFAULT NULL,
  `target_type`  VARCHAR(30)  NOT NULL,
  `target_id`    INT          NOT NULL,
  `action`       VARCHAR(40)  NOT NULL,  -- role_assigned|role_updated|role_revoked|scope_changed|relationship_added|relationship_removed|sensitive_access|account_*
  `role_key`     VARCHAR(50)  DEFAULT NULL,
  `old_value`    JSON         DEFAULT NULL,
  `new_value`    JSON         DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `user_agent`   TEXT         DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ural_target` (`target_type`, `target_id`),
  KEY `idx_ural_action` (`action`),
  KEY `idx_ural_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- COMPTES UNIFIÉS (préparation — derrière le flag FEATURE_ACCOUNTS).
-- Identité de connexion unique cible (cahier des charges §5.1/§5.2). NON branchée
-- sur l'authentification : les 5 tables héritées restent la source de login tant
-- que FEATURE_ACCOUNTS=false. legacy_type/legacy_id relient chaque compte à sa
-- ligne d'origine pour permettre un basculement progressif (voir AccountService
-- et scripts/backfill_accounts.php). Tables créées mais inertes par défaut.
-- ============================================================
CREATE TABLE `accounts` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `account_type`          ENUM('platform','personnel','student','family','external','system','temporary') NOT NULL,
  `username`              VARCHAR(100) NOT NULL,
  `email`                 VARCHAR(180) DEFAULT NULL,
  `password_hash`         VARCHAR(255) DEFAULT NULL,
  `first_name`            VARCHAR(100) DEFAULT NULL,
  `last_name`             VARCHAR(100) DEFAULT NULL,
  `display_name`          VARCHAR(180) DEFAULT NULL,
  `phone`                 VARCHAR(30)  DEFAULT NULL,
  `status`                ENUM('active','inactive','pending','locked','archived','deleted') NOT NULL DEFAULT 'pending',
  `etablissement_id`      INT          DEFAULT NULL,
  `legacy_type`           VARCHAR(30)  DEFAULT NULL,   -- table de compte d'origine (eleves/parents/…)
  `legacy_id`             INT          DEFAULT NULL,
  `must_change_password`  TINYINT(1)   NOT NULL DEFAULT 1,
  `two_factor_enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
  `last_login_at`         DATETIME     DEFAULT NULL,
  `locked_until`          DATETIME     DEFAULT NULL,
  `failed_login_attempts` INT          NOT NULL DEFAULT 0,
  `created_by`            INT          DEFAULT NULL,
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`            DATETIME     DEFAULT NULL,
  UNIQUE KEY `uk_acc_username_etab` (`username`, `etablissement_id`),
  UNIQUE KEY `uk_acc_legacy` (`legacy_type`, `legacy_id`),
  KEY `idx_acc_email` (`email`),
  KEY `idx_acc_type` (`account_type`),
  KEY `idx_acc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_profiles` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `account_id`       INT          NOT NULL,
  `profile_type`     ENUM('personnel','student','family','external','system') NOT NULL,
  `etablissement_id` INT          DEFAULT NULL,
  `date_of_birth`    DATE         DEFAULT NULL,
  `address`          TEXT         DEFAULT NULL,
  `job_title`        VARCHAR(150) DEFAULT NULL,
  `employee_number`  VARCHAR(100) DEFAULT NULL,
  `student_number`   VARCHAR(100) DEFAULT NULL,
  `class_id`         INT          DEFAULT NULL,
  `company_id`       INT          DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_ap_account` (`account_id`),
  CONSTRAINT `fk_ap_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ============================================================================
-- REFONTE 3-MONDES (cahier des charges « Plateforme / Établissements / Support »)
-- Monde 1 = PLATEFORME (équipe interne Fronote) : tables platform_*.
-- Séparées physiquement des comptes établissement (tenant_*) : aucune FK ne relie
-- un compte plateforme à un rôle établissement, ni l'inverse.
-- ============================================================================
-- ============================================================================

-- ── PLATEFORME : comptes internes Fronote (équipe) ──
CREATE TABLE `platform_accounts` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `email`              VARCHAR(180) NOT NULL,
  `username`           VARCHAR(100) NOT NULL,
  `password_hash`      VARCHAR(255) NOT NULL,
  `first_name`         VARCHAR(100) NOT NULL,
  `last_name`          VARCHAR(100) NOT NULL,
  `status`             ENUM('active','inactive','locked','archived') NOT NULL DEFAULT 'active',
  `two_factor_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
  `two_factor_secret`  VARCHAR(128) NULL,
  `last_login_at`      DATETIME     NULL,
  `locked_until`       DATETIME     NULL,
  `failed_login_attempts` INT       NOT NULL DEFAULT 0,
  `legacy_super_admin_id` INT       NULL,   -- traçabilité de migration (super_admins.id)
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_platform_email` (`email`),
  UNIQUE KEY `uk_platform_username` (`username`),
  KEY `idx_platform_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PLATEFORME : catalogue des rôles internes (jamais de rôle établissement ici) ──
CREATE TABLE `platform_roles` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `role_key`     VARCHAR(100) NOT NULL,  -- super_admin, platform_admin, platform_support, platform_security, platform_dpo, platform_maintenance, platform_auditor
  `label`        VARCHAR(180) NOT NULL,
  `description`  TEXT         NULL,
  `is_sensitive` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_system`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_platform_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PLATEFORME : attribution de rôles internes ──
CREATE TABLE `platform_account_roles` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `platform_account_id` INT NOT NULL,
  `platform_role_id`    INT NOT NULL,
  `scope_type`          ENUM('global','support_only','security_only') NOT NULL DEFAULT 'global',
  `assigned_by_platform_account_id` INT NULL,
  `assigned_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_by_platform_account_id`  INT NULL,
  `revoked_at`          DATETIME NULL,
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_platform_account_role` (`platform_account_id`, `platform_role_id`),
  KEY `idx_par_account` (`platform_account_id`),
  KEY `idx_par_role` (`platform_role_id`),
  CONSTRAINT `fk_par_account` FOREIGN KEY (`platform_account_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_par_role`    FOREIGN KEY (`platform_role_id`)    REFERENCES `platform_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PLATEFORME : invitations Directeur (le Directeur devient un tenant_account) ──
CREATE TABLE `director_invitations` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `email`        VARCHAR(180) NOT NULL,
  `first_name`   VARCHAR(100) NULL,
  `last_name`    VARCHAR(100) NULL,
  `invitation_type` ENUM('create_establishment','join_establishment','manage_multiple_establishments') NOT NULL,
  `token_hash`   VARCHAR(255) NOT NULL,
  `allowed_establishment_ids` JSON NULL,
  `default_tenant_role` VARCHAR(100) NOT NULL DEFAULT 'directeur',
  `created_by_platform_account_id` INT NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  `accepted_at`  DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  `status`       ENUM('pending','accepted','expired','revoked') NOT NULL DEFAULT 'pending',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_dirinvite_token` (`token_hash`),
  KEY `idx_dirinvite_email` (`email`),
  KEY `idx_dirinvite_status` (`status`),
  CONSTRAINT `fk_dirinvite_creator` FOREIGN KEY (`created_by_platform_account_id`) REFERENCES `platform_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PLATEFORME : audit global (actions internes : invitations, gestion du parc,
-- sessions support, sécurité, sauvegardes…). ──
CREATE TABLE `platform_audit_logs` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `platform_account_id` INT NULL,
  `establishment_id`    INT NULL,
  `action`              VARCHAR(150) NOT NULL,
  `target_type`         VARCHAR(100) NULL,
  `target_id`           INT NULL,
  `description`         TEXT NULL,
  `old_value`           JSON NULL,
  `new_value`           JSON NULL,
  `ip_address`          VARCHAR(45) NULL,
  `user_agent`          TEXT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pal_account` (`platform_account_id`),
  KEY `idx_pal_action` (`action`),
  KEY `idx_pal_created` (`created_at`),
  CONSTRAINT `fk_pal_account` FOREIGN KEY (`platform_account_id`) REFERENCES `platform_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Monde 2 = ÉTABLISSEMENT (locataire SaaS) : tables tenant_*.
-- L'appartenance (tenant_memberships) fournit le périmètre établissement ; les
-- rôles sont attribués AU NIVEAU DE L'APPARTENANCE (multi-établissement possible).
-- Aucune FK vers les tables platform_*. (etablissements sert de table establishments.)
-- ============================================================================

CREATE TABLE `tenant_accounts` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `email`             VARCHAR(180) NULL,
  `username`          VARCHAR(100) NOT NULL,
  `password_hash`     VARCHAR(255) NULL,
  `first_name`        VARCHAR(100) NOT NULL,
  `last_name`         VARCHAR(100) NOT NULL,
  `display_name`      VARCHAR(180) NULL,
  `account_type`      ENUM('director','staff','teacher','student','family','external','temporary','system') NOT NULL,
  `status`            ENUM('pending','active','inactive','locked','archived','deleted') NOT NULL DEFAULT 'pending',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
  `password_changed_at`  DATETIME  NULL,
  `two_factor_enabled`   TINYINT(1) NOT NULL DEFAULT 0,
  `two_factor_secret`    VARCHAR(128) NULL,
  `last_login_at`     DATETIME NULL,
  `locked_until`      DATETIME NULL,
  `failed_login_attempts` INT NOT NULL DEFAULT 0,
  `legacy_type`       VARCHAR(30) NULL,   -- table d'origine (eleves/parents/…) pour la migration
  `legacy_id`         INT NULL,
  `created_by`        INT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME NULL,
  UNIQUE KEY `uk_tenant_username` (`username`),
  UNIQUE KEY `uk_tenant_legacy` (`legacy_type`, `legacy_id`),
  KEY `idx_tenant_email` (`email`),
  KEY `idx_tenant_type` (`account_type`),
  KEY `idx_tenant_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_account_profiles` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_account_id` INT NOT NULL,
  `profile_type`     ENUM('staff','student','family','external','system') NOT NULL,
  `establishment_id` INT NULL,
  `date_of_birth`    DATE NULL,
  `place_of_birth`   VARCHAR(150) NULL,
  `address`          TEXT NULL,
  `job_title`        VARCHAR(150) NULL,
  `employee_number`  VARCHAR(100) NULL,
  `student_number`   VARCHAR(100) NULL,
  `class_id`         INT NULL,
  `company_id`       INT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  KEY `idx_tap_account` (`tenant_account_id`),
  CONSTRAINT `fk_tap_account` FOREIGN KEY (`tenant_account_id`) REFERENCES `tenant_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tap_etab`    FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_memberships` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `establishment_id`  INT NOT NULL,
  `tenant_account_id` INT NOT NULL,
  `status`            ENUM('pending','active','inactive','revoked') NOT NULL DEFAULT 'active',
  `joined_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`        DATETIME NULL,
  UNIQUE KEY `uk_membership` (`establishment_id`, `tenant_account_id`),
  KEY `idx_membership_account` (`tenant_account_id`),
  CONSTRAINT `fk_mbr_etab`    FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`),
  CONSTRAINT `fk_mbr_account` FOREIGN KEY (`tenant_account_id`) REFERENCES `tenant_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_roles` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `role_key`      VARCHAR(100) NOT NULL,
  `label`         VARCHAR(180) NOT NULL,
  `description`   TEXT NULL,
  `category`      ENUM('direction','administratif','vie_scolaire','pedagogique','sante_social','famille','eleve','externe','systeme') NOT NULL,
  `default_scope_type` VARCHAR(80) NOT NULL,
  `is_sensitive`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_system`     TINYINT(1) NOT NULL DEFAULT 1,
  `is_assignable` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_tenant_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_permissions` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `permission_key` VARCHAR(150) NOT NULL,
  `label`          VARCHAR(180) NOT NULL,
  `description`    TEXT NULL,
  `domain`         VARCHAR(100) NOT NULL,
  `action`         VARCHAR(80) NOT NULL,
  `is_sensitive`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tenant_permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_role_permissions` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_role_id`      INT NOT NULL,
  `tenant_permission_id` INT NOT NULL,
  `granted`             TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_trp` (`tenant_role_id`, `tenant_permission_id`),
  CONSTRAINT `fk_trp_role` FOREIGN KEY (`tenant_role_id`) REFERENCES `tenant_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trp_perm` FOREIGN KEY (`tenant_permission_id`) REFERENCES `tenant_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_membership_roles` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `membership_id` INT NOT NULL,
  `tenant_role_id` INT NOT NULL,
  `scope_type`    ENUM('global','establishment','establishments','class','classes','group','groups','subject','subjects','student','students','children','own_classes','assigned','self','company','temporary') NOT NULL,
  `scope_id`      INT NULL,
  `starts_at`     DATETIME NULL,
  `expires_at`    DATETIME NULL,
  `reason`        VARCHAR(255) NULL,
  `assigned_by_membership_id` INT NULL,
  `assigned_by_platform_account_id` INT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`    DATETIME NULL,
  UNIQUE KEY `uk_membership_role` (`membership_id`, `tenant_role_id`),
  KEY `idx_tmr_membership` (`membership_id`),
  KEY `idx_tmr_role` (`tenant_role_id`),
  KEY `idx_tmr_validity` (`expires_at`),
  CONSTRAINT `fk_tmr_membership` FOREIGN KEY (`membership_id`) REFERENCES `tenant_memberships` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tmr_role`       FOREIGN KEY (`tenant_role_id`) REFERENCES `tenant_roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_membership_role_scope_values` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `membership_role_id` INT NOT NULL,
  `scope_type`        VARCHAR(80) NOT NULL,
  `scope_id`          INT NOT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tmrsv` (`membership_role_id`, `scope_type`, `scope_id`),
  KEY `idx_tmrsv_role` (`membership_role_id`),
  KEY `idx_tmrsv_target` (`scope_type`, `scope_id`),
  CONSTRAINT `fk_tmrsv_role` FOREIGN KEY (`membership_role_id`) REFERENCES `tenant_membership_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_audit_logs` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `establishment_id` INT NOT NULL,
  `actor_account_id` INT NULL,
  `target_account_id` INT NULL,
  `action`           VARCHAR(60) NOT NULL,  -- role_assigned|account_created|sensitive_access|import|export|settings_changed|…
  `target_type`      VARCHAR(100) NULL,
  `target_id`        INT NULL,
  `old_value`        JSON NULL,
  `new_value`        JSON NULL,
  `ip_address`       VARCHAR(45) NULL,
  `user_agent`       TEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tal_etab` (`establishment_id`),
  KEY `idx_tal_action` (`action`),
  CONSTRAINT `fk_tal_etab` FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Monde 3 = SUPPORT (pont contrôlé) : tables support_*.
-- Le Support n'est JAMAIS un rôle établissement : c'est un platform_account qui
-- obtient une SESSION temporaire, bornée, auditée, révocable, sur un ticket précis,
-- validée par la Direction. ticket → demande d'accès → validation → session.
-- ============================================================================

CREATE TABLE `support_tickets` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `establishment_id` INT NOT NULL,
  `created_by_tenant_account_id` INT NULL,
  `created_by_membership_id`     INT NULL,
  `assigned_platform_account_id` INT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `category`         ENUM('account','permissions','configuration','module','data','bug','security','performance','billing','other') NOT NULL DEFAULT 'other',
  `priority`         ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `description`      TEXT NOT NULL,
  `status`           ENUM('draft','submitted','triaged','waiting_support_access','access_requested','access_approved','access_refused','in_progress','waiting_establishment','resolved','closed','cancelled') NOT NULL DEFAULT 'submitted',
  `sensitive_flag`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  `resolved_at`      DATETIME NULL,
  `closed_at`        DATETIME NULL,
  KEY `idx_st_etab` (`establishment_id`),
  KEY `idx_st_status` (`status`),
  CONSTRAINT `fk_st_etab` FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NB : nommé support_bridge_messages (et NON support_ticket_messages) pour ne pas
-- entrer en collision avec la table du module historique modules/support, qui possède
-- déjà un support_ticket_messages (colonnes auteur_id/contenu NOT NULL).
CREATE TABLE `support_bridge_messages` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `author_type` ENUM('tenant','platform','system') NOT NULL,
  `tenant_account_id`   INT NULL,
  `platform_account_id` INT NULL,
  `message`   TEXT NOT NULL,
  `is_internal_note`  TINYINT(1) NOT NULL DEFAULT 0,
  `visible_to_tenant` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sbm_ticket` (`ticket_id`),
  CONSTRAINT `fk_sbm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `support_access_requests` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id`        INT NOT NULL,
  `establishment_id` INT NOT NULL,
  `requested_by_platform_account_id` INT NOT NULL,
  `reviewed_by_membership_id`        INT NULL,
  `access_level`     ENUM('read_only','diagnostic','configuration','account_assistance','data_assistance','impersonation','sensitive_data_access') NOT NULL,
  `requested_scope_type`    VARCHAR(100) NOT NULL,
  `requested_scope_payload` JSON NULL,
  `reason`           VARCHAR(255) NOT NULL,
  `detailed_reason`  TEXT NULL,
  `planned_actions`  TEXT NULL,
  `requested_duration_minutes` INT NOT NULL,
  `approved_duration_minutes`  INT NULL,
  `requested_expires_at` DATETIME NOT NULL,
  `approved_expires_at`  DATETIME NULL,
  `status`           ENUM('draft','sent_to_direction','waiting_direction','approved','approved_with_changes','refused','more_info_requested','expired','revoked','cancelled') NOT NULL DEFAULT 'draft',
  `refusal_reason`   TEXT NULL,
  `direction_comment` TEXT NULL,
  `requires_sensitive_validation` TINYINT(1) NOT NULL DEFAULT 0,
  `sensitive_validation_status` ENUM('not_required','pending','approved','refused') NOT NULL DEFAULT 'not_required',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`    DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `revoked_at`  DATETIME NULL,
  KEY `idx_sar_ticket` (`ticket_id`),
  KEY `idx_sar_etab` (`establishment_id`),
  KEY `idx_sar_status` (`status`),
  CONSTRAINT `fk_sar_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`),
  CONSTRAINT `fk_sar_etab`   FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`),
  CONSTRAINT `fk_sar_requester` FOREIGN KEY (`requested_by_platform_account_id`) REFERENCES `platform_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `support_access_request_approvals` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `access_request_id` INT NOT NULL,
  `approver_membership_id` INT NOT NULL,
  `decision`          ENUM('approved','refused','more_info_requested') NOT NULL,
  `approved_access_level`   VARCHAR(100) NULL,
  `approved_scope_payload`  JSON NULL,
  `approved_duration_minutes` INT NULL,
  `comment`           TEXT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sara_request` (`access_request_id`),
  CONSTRAINT `fk_sara_request` FOREIGN KEY (`access_request_id`) REFERENCES `support_access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `support_sessions` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `access_request_id` INT NOT NULL,
  `ticket_id`         INT NOT NULL,
  `establishment_id`  INT NOT NULL,
  `platform_account_id` INT NOT NULL,
  `access_level`      ENUM('read_only','diagnostic','configuration','account_assistance','data_assistance','impersonation','sensitive_data_access') NOT NULL,
  `active_scope_payload` JSON NULL,
  `status`            ENUM('pending_start','active','paused','expired','ended','revoked','security_stopped') NOT NULL DEFAULT 'pending_start',
  `started_at`        DATETIME NULL,
  `expires_at`        DATETIME NOT NULL,
  `ended_at`          DATETIME NULL,
  `ended_by_type`     ENUM('platform','tenant','system') NULL,
  `ended_by_platform_account_id` INT NULL,
  `ended_by_membership_id`       INT NULL,
  `end_reason`        VARCHAR(255) NULL,
  `ip_address`        VARCHAR(45) NULL,
  `user_agent`        TEXT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ss_etab` (`establishment_id`),
  KEY `idx_ss_account` (`platform_account_id`),
  KEY `idx_ss_status` (`status`),
  CONSTRAINT `fk_ss_request` FOREIGN KEY (`access_request_id`) REFERENCES `support_access_requests` (`id`),
  CONSTRAINT `fk_ss_ticket`  FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`),
  CONSTRAINT `fk_ss_etab`    FOREIGN KEY (`establishment_id`) REFERENCES `etablissements` (`id`),
  CONSTRAINT `fk_ss_account` FOREIGN KEY (`platform_account_id`) REFERENCES `platform_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'intervention APPEND-ONLY (aucune mise à jour ni suppression autorisée).
CREATE TABLE `support_session_audit_logs` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `support_session_id` INT NOT NULL,
  `ticket_id`        INT NOT NULL,
  `establishment_id` INT NOT NULL,
  `platform_account_id` INT NOT NULL,
  `action`           VARCHAR(150) NOT NULL,
  `target_type`      VARCHAR(100) NULL,
  `target_id`        INT NULL,
  `permission_used`  VARCHAR(150) NULL,
  `access_level`     VARCHAR(100) NULL,
  `description`      TEXT NULL,
  `old_value`        JSON NULL,
  `new_value`        JSON NULL,
  `sensitive`        TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address`       VARCHAR(45) NULL,
  `user_agent`       TEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ssal_session` (`support_session_id`),
  CONSTRAINT `fk_ssal_session` FOREIGN KEY (`support_session_id`) REFERENCES `support_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `support_session_restrictions` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `support_session_id` INT NOT NULL,
  `restriction_key`   VARCHAR(150) NOT NULL,  -- hide_medical_data, disable_destructive_actions, allowed_student_ids, …
  `restriction_value` JSON NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ssr_session` (`support_session_id`),
  CONSTRAINT `fk_ssr_session` FOREIGN KEY (`support_session_id`) REFERENCES `support_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- M100 : Permissions CRUD par module (admin)
-- ============================================================
CREATE TABLE `module_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `module_key` VARCHAR(50) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `can_view` TINYINT(1) NOT NULL DEFAULT 1,
  `can_create` TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  `can_export` TINYINT(1) NOT NULL DEFAULT 0,
  `can_import` TINYINT(1) NOT NULL DEFAULT 0,
  `custom_permissions` JSON DEFAULT NULL COMMENT 'Permissions spécifiques au module, ex: {"can_send":true,"can_moderate":false}',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_module_role` (`module_key`, `role`),
  KEY `idx_role` (`role`),
  KEY `idx_module` (`module_key`),
  CONSTRAINT `fk_modperm_module` FOREIGN KEY (`module_key`) REFERENCES `modules_config` (`module_key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions par défaut messagerie (désactivée par défaut sauf admin)
INSERT INTO `module_permissions` (`module_key`, `role`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`, `custom_permissions`) VALUES
('messagerie', 'administrateur', 1, 1, 1, 1, 1, '{"can_send":true,"can_moderate":true,"can_broadcast":true}'),
('messagerie', 'professeur',     0, 0, 0, 0, 0, '{"can_send":false,"can_moderate":false}'),
('messagerie', 'vie_scolaire',   0, 0, 0, 0, 0, '{"can_send":false,"can_moderate":false}'),
('messagerie', 'eleve',          0, 0, 0, 0, 0, '{"can_send":false}'),
('messagerie', 'parent',         0, 0, 0, 0, 0, '{"can_send":false}');

-- Permissions par défaut notes
INSERT INTO `module_permissions` (`module_key`, `role`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`) VALUES
('notes', 'administrateur', 1, 1, 1, 1, 1),
('notes', 'professeur',     1, 1, 1, 0, 1),
('notes', 'vie_scolaire',   1, 0, 0, 0, 1),
('notes', 'eleve',          1, 0, 0, 0, 0),
('notes', 'parent',         1, 0, 0, 0, 0);

-- Permissions par défaut absences
INSERT INTO `module_permissions` (`module_key`, `role`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`) VALUES
('absences', 'administrateur', 1, 1, 1, 1, 1),
('absences', 'professeur',     1, 1, 1, 0, 0),
('absences', 'vie_scolaire',   1, 1, 1, 1, 1),
('absences', 'eleve',          1, 0, 0, 0, 0),
('absences', 'parent',         1, 0, 0, 0, 0);

-- ============================================================
-- M101 : Accès technicien temporaire
-- ============================================================
CREATE TABLE `technicien_access` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nom` VARCHAR(100) NOT NULL,
  `prenom` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `identifiant` VARCHAR(50) NOT NULL,
  `mot_de_passe` VARCHAR(255) NOT NULL,
  `motif` TEXT NOT NULL COMMENT 'Raison de l''accès temporaire',
  `permissions` JSON NOT NULL DEFAULT ('["admin.access","admin.systeme"]') COMMENT 'Liste des permissions accordées',
  `modules_autorises` JSON DEFAULT NULL COMMENT 'null = tous les modules, sinon liste de module_key',
  `ip_whitelist` JSON DEFAULT NULL COMMENT 'IPs autorisées, null = toutes',
  `created_by` INT NOT NULL COMMENT 'ID admin qui a créé l''accès',
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `date_debut` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_expiration` DATETIME NOT NULL COMMENT 'Expiration automatique',
  `last_login` DATETIME DEFAULT NULL,
  `login_count` INT NOT NULL DEFAULT 0,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoked_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_identifiant` (`identifiant`),
  KEY `idx_actif_expiration` (`actif`, `date_expiration`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log des actions technicien (audit renforcé)
CREATE TABLE `technicien_audit_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `technicien_id` INT NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_technicien` (`technicien_id`),
  KEY `idx_action` (`action`),
  KEY `idx_date` (`created_at`),
  CONSTRAINT `fk_techaudit_tech` FOREIGN KEY (`technicien_id`) REFERENCES `technicien_access` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- M102 : Profil utilisateur étendu (citation, réseaux sociaux, photo)
-- ============================================================
CREATE TABLE `user_profiles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `citation` VARCHAR(500) DEFAULT NULL COMMENT 'Citation ou phrase de présentation',
  `site_web` VARCHAR(255) DEFAULT NULL,
  `lien_linkedin` VARCHAR(255) DEFAULT NULL,
  `lien_twitter` VARCHAR(255) DEFAULT NULL,
  `lien_github` VARCHAR(255) DEFAULT NULL,
  `lien_instagram` VARCHAR(255) DEFAULT NULL,
  `lien_autre` VARCHAR(255) DEFAULT NULL,
  `competences_tags` JSON DEFAULT NULL COMMENT 'Tags de compétences/intérêts',
  `disponibilites` VARCHAR(255) DEFAULT NULL COMMENT 'Horaires de disponibilité (texte libre)',
  `bureau` VARCHAR(100) DEFAULT NULL COMMENT 'Numéro de bureau (professeur/admin)',
  `telephone_pro` VARCHAR(20) DEFAULT NULL,
  `date_naissance_visible` TINYINT(1) NOT NULL DEFAULT 0,
  `email_visible` TINYINT(1) NOT NULL DEFAULT 0,
  `profil_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Visible dans le trombinoscope',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_profile` (`user_id`, `user_type`),
  KEY `idx_profil_public` (`profil_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- M103 : Configuration import/export
-- ============================================================
CREATE TABLE `import_export_logs` (
  `id` INT AUTO_INCREMENT,
  `type` ENUM('import','export') NOT NULL,
  `cible` VARCHAR(50) NOT NULL COMMENT 'users, config, notes, absences, etc.',
  `format` VARCHAR(20) NOT NULL DEFAULT 'csv' COMMENT 'csv, json, xlsx',
  `fichier_nom` VARCHAR(255) DEFAULT NULL,
  `fichier_chemin` VARCHAR(500) DEFAULT NULL,
  `nb_lignes_total` INT DEFAULT 0,
  `nb_lignes_traitees` INT DEFAULT 0,
  `nb_erreurs` INT DEFAULT 0,
  `erreurs_detail` JSON DEFAULT NULL,
  `options` JSON DEFAULT NULL COMMENT 'Options utilisées (mapping colonnes, etc.)',
  `statut` ENUM('en_cours','termine','erreur','annule') NOT NULL DEFAULT 'en_cours',
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_cible` (`type`, `cible`),
  KEY `idx_statut` (`statut`),
  KEY `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- M104 : Widgets personnalisables accueil
-- ============================================================
CREATE TABLE `dashboard_widgets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `widget_key` VARCHAR(50) NOT NULL COMMENT 'identifiant unique du widget',
  `label` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT 'fas fa-puzzle-piece',
  `type` ENUM('stats','list','chart','calendar','shortcut','custom') NOT NULL DEFAULT 'stats',
  `module_key` VARCHAR(50) DEFAULT NULL COMMENT 'Module lié (null = global)',
  `roles_autorises` JSON DEFAULT NULL COMMENT 'null = tous les rôles',
  `default_config` JSON DEFAULT NULL COMMENT 'Config par défaut du widget',
  `min_width` INT NOT NULL DEFAULT 1 COMMENT 'Largeur min en colonnes (1-4)',
  `max_width` INT NOT NULL DEFAULT 4,
  `default_width` INT NOT NULL DEFAULT 2,
  `default_height` INT NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Affiché par défaut pour les nouveaux utilisateurs',
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 100,
  UNIQUE KEY `uk_widget_key` (`widget_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_dashboard_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `widget_key` VARCHAR(50) NOT NULL,
  `position_x` INT NOT NULL DEFAULT 0 COMMENT 'Colonne (0-based)',
  `position_y` INT NOT NULL DEFAULT 0 COMMENT 'Ligne (0-based)',
  `width` INT NOT NULL DEFAULT 2,
  `height` INT NOT NULL DEFAULT 1,
  `config` JSON DEFAULT NULL COMMENT 'Config spécifique utilisateur',
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_widget` (`user_id`, `user_type`, `widget_key`),
  KEY `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named dashboard layouts per user
CREATE TABLE `dashboard_layouts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL COMMENT 'Layout name (e.g. "Compact", "Full")',
  `columns` INT NOT NULL DEFAULT 4 COMMENT 'Grid column count (2, 3, or 4)',
  `widgets_config` JSON NOT NULL COMMENT 'Array of {widget_key, position_x, position_y, width, height, visible, config}',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Currently active layout',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_layout_user` (`user_id`, `user_type`),
  UNIQUE KEY `uk_layout_name` (`user_id`, `user_type`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Widgets par défaut
INSERT INTO `dashboard_widgets` (`widget_key`, `label`, `description`, `icon`, `type`, `module_key`, `roles_autorises`, `default_width`, `is_default`, `sort_order`) VALUES
('prochains_evenements', 'Prochains événements', 'Événements à venir cette semaine', 'fas fa-calendar', 'list', 'agenda', NULL, 2, 1, 10),
('devoirs_a_faire',      'Devoirs à rendre',     'Devoirs en attente de rendu',      'fas fa-tasks', 'list', 'devoirs', '["eleve","parent"]', 2, 1, 20),
('dernieres_notes',      'Dernières notes',       'Notes les plus récentes',          'fas fa-chart-bar', 'list', 'notes', '["eleve","parent","professeur"]', 2, 1, 30),
('messages_non_lus',     'Messages non lus',      'Messages en attente de lecture',   'fas fa-envelope', 'stats', 'messagerie', NULL, 1, 1, 40),
('absences_du_jour',     'Absences du jour',      'Absences signalées aujourd''hui',  'fas fa-calendar-times', 'stats', 'absences', '["administrateur","vie_scolaire","professeur"]', 1, 1, 50),
('stats_rapides',        'Statistiques rapides',   'Vue d''ensemble chiffrée',         'fas fa-tachometer-alt', 'stats', NULL, '["administrateur","vie_scolaire"]', 4, 1, 5),
('emploi_du_temps_jour', 'Emploi du temps',        'Cours du jour',                    'fas fa-table', 'calendar', 'emploi_du_temps', '["eleve","professeur"]', 2, 1, 15),
('raccourcis',           'Accès rapides',          'Raccourcis vers vos modules favoris', 'fas fa-star', 'shortcut', NULL, NULL, 2, 1, 60),
('annonces_recentes',    'Annonces récentes',     'Dernières annonces et sondages',    'fas fa-bullhorn', 'list', 'annonces', NULL, 2, 1, 25),
('reunions_a_venir',     'Réunions à venir',      'Prochaines réunions planifiées',    'fas fa-handshake', 'list', 'reunions', '["professeur","parent","administrateur"]', 2, 0, 70),
('bulletins_recents',    'Bulletins récents',     'Derniers bulletins disponibles',    'fas fa-file-alt', 'list', 'bulletins', '["eleve","parent"]', 2, 0, 35),
('discipline_recente',   'Discipline',            'Derniers incidents et suivi',       'fas fa-gavel', 'list', 'discipline', '["administrateur","vie_scolaire","professeur"]', 2, 0, 55),
('vie_scolaire_stats',   'Vie scolaire',          'Absences, retards, incidents du jour', 'fas fa-user-graduate', 'stats', 'vie_scolaire', '["administrateur","vie_scolaire"]', 4, 1, 5),
('cantine_menu_jour',    'Menu du jour',           'Menu de la cantine',                'fas fa-utensils', 'list', 'cantine', NULL, 2, 0, 60),
('competences_recentes', 'Compétences récentes',  'Dernières évaluations',             'fas fa-award', 'list', 'competences', '["eleve","parent","professeur"]', 2, 0, 40),
('support_tickets',      'Tickets ouverts',        'Tickets de support en cours',       'fas fa-life-ring', 'list', 'support', NULL, 2, 0, 75);

-- ============================================================
-- INTERNATIONALISATION (i18n)
-- ============================================================

-- Table de traductions pour le contenu dynamique en base
CREATE TABLE `translations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `translatable_type` varchar(50) NOT NULL COMMENT 'Type d''entité (module, widget, announcement)',
  `translatable_id` int(11) NOT NULL COMMENT 'ID de l''entité traduite',
  `locale` varchar(10) NOT NULL COMMENT 'Code locale (fr, en, etc.)',
  `field` varchar(50) NOT NULL COMMENT 'Champ traduit (label, description, etc.)',
  `value` text NOT NULL COMMENT 'Valeur traduite',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trans` (`translatable_type`, `translatable_id`, `locale`, `field`),
  KEY `idx_trans_lookup` (`translatable_type`, `translatable_id`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FEATURE FLAGS (multi-établissement)
-- ============================================================

CREATE TABLE `feature_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `flag_key` varchar(100) NOT NULL,
  `label` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `establishment_types` JSON DEFAULT NULL COMMENT 'null = tous types, ["college","lycee"] = spécifique',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config` JSON DEFAULT NULL COMMENT 'Configuration additionnelle du flag',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flag` (`flag_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feature flags par défaut
INSERT INTO `feature_flags` (`flag_key`, `label`, `establishment_types`, `enabled`) VALUES
('stages.enabled',            'Stages',                      '["lycee","superieur"]',       1),
('alternance.enabled',        'Alternance',                  '["superieur"]',               1),
('orientation.parcoursup',    'Parcoursup',                  '["lycee"]',                   1),
('orientation.ects',          'Crédits ECTS',                '["superieur"]',               1),
('bulletins.brevet',          'Bulletins Brevet',            '["college"]',                 1),
('bulletins.bac',             'Bulletins Bac',               '["lycee"]',                   1),
('examens.brevet',            'Examens Brevet',              '["college"]',                 1),
('examens.bac',               'Examens Bac',                 '["lycee"]',                   1),
('internat.enabled',          'Internat',                    '["college","lycee"]',         1),
('garderie.enabled',          'Garderie',                    '["college"]',                 1),
('cantine.enabled',           'Cantine',                     NULL,                          1),
('periscolaire.enabled',      'Périscolaire',                '["college"]',                 1),
('competences.socle',         'Socle commun de compétences', '["college"]',                 1),
('competences.referentiel',   'Référentiel de compétences',  '["lycee","superieur"]',       1),
('absences.notify_parents',   'Notification email parents pour les absences', NULL,          1),
('annonces.sondages',         'Sondages dans les annonces',  NULL,                          1),
('agenda.recurrence',         'Événements récurrents',       NULL,                          1),
('annonces.attachments',      'Pièces jointes dans les annonces', NULL,                     1);

-- Feature flags granulaires par module
INSERT INTO `feature_flags` (`flag_key`, `label`, `description`, `establishment_types`, `enabled`, `config`) VALUES
('notes.export_pdf',           'Export PDF des notes',              'Permet l\'export des notes en PDF',                    NULL, 1, NULL),
('notes.lock_after_deadline',  'Verrouillage apres deadline',      'Verrouille les notes apres la date limite de saisie',  NULL, 0, NULL),
('notes.parent_notifications', 'Notif. parents pour notes',        'Envoie un email aux parents quand une note est saisie', NULL, 1, NULL),
('notes.class_statistics',     'Statistiques par classe',          'Affiche les stats de classe',                          NULL, 1, NULL),
('notes.coefficient_display',  'Affichage des coefficients',       'Affiche les coefficients des evaluations',             NULL, 1, NULL),
('absences.sms_alerts',        'Alertes SMS absences',             'Envoie des SMS aux parents en cas d\'absence',         NULL, 0, '{"provider": "ovh"}'),
('absences.qr_presence',       'Presence par QR code',             'Permet la saisie de presence par scan QR',             NULL, 0, NULL),
('absences.auto_justify',      'Justification automatique',        'Justifie automatiquement certaines absences',          NULL, 0, NULL),
('absences.export_pdf',        'Export PDF absences',              'Permet l\'export des absences en PDF',                 NULL, 1, NULL),
('absences.parental_justify',  'Justification par parents',        'Permet aux parents de justifier en ligne',             NULL, 1, NULL),
('messagerie.file_attachments','Pieces jointes messagerie',        'Permet d\'envoyer des fichiers en piece jointe',       NULL, 1, '{"max_size_mb": 10}'),
('messagerie.typing_indicators','Indicateur de saisie',            'Affiche quand l\'interlocuteur ecrit',                 NULL, 0, NULL),
('messagerie.read_receipts',   'Accuses de lecture',               'Affiche quand un message a ete lu',                    NULL, 1, NULL),
('messagerie.broadcast',       'Diffusion massive',                'Permet l\'envoi de messages a des groupes entiers',    NULL, 1, NULL),
('messagerie.reactions',       'Reactions emoji',                   'Permet de reagir aux messages avec des emojis',        NULL, 0, NULL),
('agenda.ical_export',         'Export iCal',                      'Permet l\'export du calendrier au format iCal',        NULL, 1, NULL),
('agenda.conflict_detection',  'Detection de conflits',            'Detecte les conflits d\'horaires dans l\'agenda',      NULL, 1, NULL),
('agenda.reminders',           'Rappels automatiques',             'Envoie des rappels avant les evenements',              NULL, 1, '{"minutes_before": 30}'),
('cahierdetextes.rich_editor', 'Editeur riche',                    'Active l\'editeur de texte enrichi',                   NULL, 1, NULL),
('cahierdetextes.attachments', 'Pieces jointes',                   'Permet d\'ajouter des fichiers au cahier de textes',   NULL, 1, NULL),
('cahierdetextes.drag_reorder','Reordonnement drag-drop',          'Permet de reordonner les entrees par glisser-deposer', NULL, 0, NULL),
('devoirs.online_submission',  'Rendu en ligne',                   'Permet aux eleves de soumettre leurs devoirs en ligne', NULL, 1, NULL),
('devoirs.auto_reminders',     'Rappels automatiques',             'Envoie des rappels avant la date limite',              NULL, 1, NULL),
('devoirs.annotation',         'Annotation des copies',            'Permet au professeur d\'annoter les copies rendues',   NULL, 0, NULL),
('devoirs.plagiarism_check',   'Verification de plagiat',          'Active la verification de plagiat sur les rendus',     NULL, 0, NULL),
('competences.radar_chart',    'Graphe radar',                     'Affiche un graphe radar des competences par eleve',    NULL, 1, NULL),
('competences.lsu_export',     'Export LSU',                       'Permet l\'export des competences au format LSU',       '["college"]', 1, NULL),
('competences.auto_link',      'Liaison notes-competences',        'Lie automatiquement les notes aux competences',        NULL, 0, NULL),
('bulletins.batch_generation', 'Generation par lot',               'Permet de generer tous les bulletins d\'une classe',   NULL, 1, NULL),
('bulletins.live_preview',     'Apercu en direct',                 'Affiche un apercu du bulletin pendant la saisie',      NULL, 1, NULL),
('bulletins.auto_suggestion',  'Appreciation auto-suggeree',       'Suggere des appreciations basees sur les resultats',   NULL, 0, NULL),
('emploi_du_temps.drag_edit',  'Edition drag-drop',                'Permet de modifier l\'emploi du temps par drag-drop',  NULL, 0, NULL),
('emploi_du_temps.conflict',   'Detection conflits',               'Detecte les conflits de salles et enseignants',        NULL, 1, NULL),
('emploi_du_temps.substitution','Gestion remplacements',           'Active le module de remplacement d\'enseignants',      NULL, 1, NULL),
('emploi_du_temps.ical_sync',  'Synchronisation iCal',             'Synchronise l\'emploi du temps avec les agendas',      NULL, 1, NULL),
('appel.realtime',             'Appel temps reel',                 'Transmet l\'appel en temps reel via WebSocket',        NULL, 1, NULL),
('appel.qr_scan',              'Scan QR appel',                    'Permet l\'appel par scan de QR code eleve',            NULL, 0, NULL),
('appel.batch_entry',          'Saisie groupee',                   'Permet de saisir l\'appel pour plusieurs cours',       NULL, 0, NULL),
('discipline.points_system',   'Systeme de points',                'Active le systeme de points de comportement',          NULL, 0, NULL),
('discipline.graduated_sanctions','Sanctions graduees',             'Propose des sanctions proportionnelles aux incidents', NULL, 0, NULL),
('discipline.statistics',      'Statistiques discipline',          'Affiche les statistiques d\'incidents',                NULL, 1, NULL),
('vie_scolaire.dropout_alerts','Alertes decrochage',               'Detecte les eleves a risque de decrochage',            NULL, 1, NULL),
('vie_scolaire.consolidated',  'Dashboard consolide',              'Consolide absences + discipline dans un tableau de bord', NULL, 1, NULL),
('signalements.anonymous',     'Signalement anonyme',              'Permet les signalements anonymes',                     NULL, 1, NULL),
('signalements.notifications', 'Notifications signalements',       'Notifie les responsables des nouveaux signalements',   NULL, 1, NULL),
('cantine.online_booking',     'Reservation en ligne',             'Permet la reservation des repas en ligne',             NULL, 1, NULL),
('cantine.menu_display',       'Affichage du menu',                'Affiche le menu de la semaine aux utilisateurs',       NULL, 1, NULL),
('cantine.allergen_alerts',    'Alertes allergenes',               'Affiche les alertes allergenes sur les menus',         NULL, 1, NULL),
('bibliotheque.barcode_scan',  'Scan code-barres',                 'Permet le scan de code-barres pour les prets',         NULL, 0, NULL),
('bibliotheque.online_catalog','Catalogue en ligne',               'Rend le catalogue accessible aux eleves/parents',      NULL, 1, NULL),
('bibliotheque.overdue_alerts','Alertes retard prets',             'Envoie des alertes pour les prets en retard',          NULL, 1, NULL),
('reunions.auto_reminders',    'Rappels reunions',                 'Envoie des rappels automatiques avant les reunions',   NULL, 1, NULL),
('reunions.video_conference',  'Visioconference',                  'Integre un lien de visioconference aux reunions',      NULL, 0, NULL),
('reunions.online_booking',    'Reservation en ligne',             'Permet aux parents de reserver un creneau',            NULL, 1, NULL),
('annonces.scheduled_publish', 'Publication programmee',            'Permet de programmer la publication d\'annonces',      NULL, 1, NULL),
('annonces.read_receipts',     'Accuses de lecture annonces',      'Affiche les accuses de lecture des annonces',           NULL, 0, NULL),
('annonces.target_by_role',    'Ciblage par role',                 'Permet de cibler les annonces par role/classe',         NULL, 1, NULL),
('notifications.push',         'Notifications push',               'Active les notifications push navigateur',             NULL, 1, NULL),
('notifications.email_digest', 'Resume email quotidien',           'Envoie un resume quotidien par email',                 NULL, 0, '{"hour": 18}'),
('notifications.per_module',   'Preferences par module',           'Permet de configurer les notifs par module',            NULL, 1, NULL),
('documents.versioning',       'Versionnement documents',          'Active le versionnement des documents uploades',       NULL, 0, NULL),
('documents.sharing',          'Partage de documents',             'Permet le partage de documents entre utilisateurs',    NULL, 1, NULL),
('facturation.online_payment', 'Paiement en ligne',                'Permet le paiement en ligne des factures',             NULL, 0, NULL),
('facturation.auto_reminders', 'Relances automatiques',            'Envoie des relances pour les factures impayees',       NULL, 1, NULL),
('inscriptions.online_form',   'Formulaire en ligne',              'Permet l\'inscription en ligne',                       NULL, 1, NULL),
('inscriptions.document_upload','Upload documents inscription',    'Permet le telechargement de documents pour inscription', NULL, 1, NULL),
('reporting.scheduled_reports','Rapports programmes',              'Permet de programmer la generation de rapports',        NULL, 0, NULL),
('reporting.custom_templates', 'Modeles personnalises',            'Permet de creer des modeles de rapports personnalises', NULL, 0, NULL),
('rgpd.auto_purge',           'Purge automatique',                 'Purge automatiquement les donnees expirees',           NULL, 0, '{"retention_years": 5}'),
('rgpd.consent_tracking',     'Suivi consentements',               'Suit les consentements des utilisateurs',              NULL, 1, NULL),
('rgpd.data_export',          'Export donnees personnelles',       'Permet aux utilisateurs d\'exporter leurs donnees',    NULL, 1, NULL),
('transports.delay_alerts',   'Alertes retard transport',          'Envoie des alertes en cas de retard de bus',           NULL, 0, NULL),
('transports.online_register','Inscription transport en ligne',    'Permet l\'inscription au transport en ligne',          NULL, 1, NULL),
('infirmerie.vaccination_tracking','Suivi vaccinal',               'Active le suivi des vaccinations',                     NULL, 1, NULL),
('infirmerie.emergency_protocols','Protocoles urgence',            'Active les protocoles d\'urgence integres',            NULL, 1, NULL),
('support.sla_tracking',      'Suivi SLA',                         'Suit les delais de resolution des tickets',            NULL, 0, NULL),
('support.knowledge_base',    'Base de connaissances',              'Active la base de connaissances publique',             NULL, 1, NULL),
('profil.avatar_upload',      'Upload avatar',                     'Permet le telechargement d\'une photo de profil',      NULL, 1, NULL),
('profil.social_links',       'Liens reseaux sociaux',             'Permet d\'ajouter des liens vers les reseaux sociaux', NULL, 0, NULL),
('parametres.export_data',    'Export donnees utilisateur',        'Permet a l\'utilisateur d\'exporter ses donnees',      NULL, 1, NULL),
('examens.auto_convocation',  'Convocations automatiques',        'Genere les convocations automatiquement',              NULL, 1, NULL),
('examens.room_allocation',   'Attribution de salles',             'Attribue automatiquement les salles d\'examen',        NULL, 0, NULL),
('stages.online_evaluation',  'Evaluation en ligne',               'Permet l\'evaluation en ligne par le tuteur',          '["lycee","superieur"]', 1, NULL),
('stages.convention_pdf',     'Generation convention PDF',         'Genere les conventions de stage en PDF',               '["lycee","superieur"]', 1, NULL),
('trombinoscope.pdf_export',  'Export PDF trombinoscope',          'Permet l\'export du trombinoscope en PDF',             NULL, 1, NULL),
('archivage.auto_archive',    'Archivage automatique',             'Archive automatiquement en fin d\'annee scolaire',     NULL, 0, NULL),
('parcours.portfolio',        'Portfolio eleve',                    'Active le portfolio numerique de l\'eleve',            NULL, 0, NULL);

-- ============================================================
-- API TOKENS (authentification externe)
-- ============================================================

CREATE TABLE `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `token_hash` varchar(64) NOT NULL COMMENT 'SHA-256 du token',
  `name` varchar(100) NOT NULL COMMENT 'Nom descriptif du token',
  `abilities` JSON DEFAULT NULL COMMENT 'Permissions du token (null = toutes)',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- WEBHOOKS (intégrations externes)
-- ============================================================

CREATE TABLE `webhooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(500) NOT NULL,
  `events` JSON NOT NULL COMMENT 'Événements déclencheurs',
  `secret` varchar(64) NOT NULL COMMENT 'Secret HMAC-SHA256',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `last_triggered_at` datetime DEFAULT NULL,
  `failure_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- OAuth SSO bindings
-- ============================================================
CREATE TABLE `oauth_bindings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `provider` VARCHAR(50) NOT NULL,
  `provider_user_id` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_binding` (`provider`, `provider_user_id`),
  KEY `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Audit log : colonnes supplémentaires pour traçabilité avancée
-- ============================================================
ALTER TABLE `audit_log`
  ADD COLUMN `severity` ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO' AFTER `user_agent`,
  ADD COLUMN `request_method` VARCHAR(10) DEFAULT NULL AFTER `severity`,
  ADD COLUMN `request_uri` VARCHAR(500) DEFAULT NULL AFTER `request_method`;

-- Index composites pour les requêtes fréquentes du dashboard admin
ALTER TABLE `audit_log`
  ADD INDEX `idx_severity_date` (`severity`, `created_at`),
  ADD INDEX `idx_action_date` (`action`, `created_at`);

-- ============================================================
-- Job Queue (G4) — file d'attente asynchrone en base
-- ============================================================
CREATE TABLE `job_queue` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `handler` VARCHAR(255) NOT NULL COMMENT 'Classe ou callable du job',
  `payload` JSON NOT NULL,
  `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `attempts` INT NOT NULL DEFAULT 0,
  `max_attempts` INT NOT NULL DEFAULT 3,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status_available` (`status`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Module Settings Schema (D5) — champs de configuration déclaratifs par module
-- ============================================================
CREATE TABLE `module_settings_schema` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `module_key` VARCHAR(50) NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `field_type` ENUM('text','number','integer','checkbox','boolean','select','textarea','color','json','email','url','date','time','datetime') NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `default_value` TEXT DEFAULT NULL,
  `options` JSON DEFAULT NULL COMMENT 'Options pour les selects',
  `hint` TEXT DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uk_module_field` (`module_key`, `field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- App Metrics (J2) — métriques applicatives
-- ============================================================
CREATE TABLE `app_metrics` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `metric_key` VARCHAR(100) NOT NULL,
  `metric_value` DECIMAL(12,2) NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_key_date` (`metric_key`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seeds : modules_config.route_path — chemins de routage par module
-- ============================================================
INSERT INTO `modules_config` (`module_key`, `label`, `icon`, `category`, `route_path`)
VALUES
  ('accueil','Accueil','fas fa-home','navigation','accueil/accueil.php'),
  ('notes','Notes','fas fa-star','scolaire','modules/notes/notes.php'),
  ('agenda','Agenda','fas fa-calendar','scolaire','modules/agenda/agenda.php'),
  ('cahierdetextes','Cahier de textes','fas fa-book','scolaire','modules/cahierdetextes/cahierdetextes.php'),
  ('messagerie','Messagerie','fas fa-envelope','communication','modules/messagerie/index.php'),
  ('annonces','Annonces','fas fa-bullhorn','communication','modules/annonces/annonces.php'),
  ('emploi_du_temps','Emploi du temps','fas fa-clock','scolaire','modules/emploi_du_temps/emploi_du_temps.php'),
  ('absences','Absences','fas fa-user-times','vie_scolaire','modules/absences/absences.php'),
  ('appel','Appel','fas fa-check-square','vie_scolaire','modules/appel/appel.php'),
  ('discipline','Discipline','fas fa-gavel','vie_scolaire','modules/discipline/incidents.php'),
  ('vie_scolaire','Vie scolaire','fas fa-school','vie_scolaire','modules/vie_scolaire/dashboard.php'),
  ('reporting','Reporting','fas fa-chart-bar','systeme','modules/reporting/reporting.php'),
  ('bulletins','Bulletins','fas fa-file-alt','scolaire','modules/bulletins/bulletins.php'),
  ('competences','Compétences','fas fa-award','scolaire','modules/competences/competences.php'),
  ('trombinoscope','Trombinoscope','fas fa-id-badge','etablissement','modules/trombinoscope/trombinoscope.php'),
  ('documents','Documents','fas fa-folder','etablissement','modules/documents/documents.php'),
  ('notifications','Notifications','fas fa-bell','communication','modules/notifications/notifications.php'),
  ('reunions','Réunions','fas fa-handshake','etablissement','modules/reunions/reunions.php'),
  ('bibliotheque','Bibliothèque','fas fa-book-open','etablissement','modules/bibliotheque/catalogue.php'),
  ('clubs','Clubs','fas fa-users','etablissement','modules/clubs/clubs.php'),
  ('orientation','Orientation','fas fa-compass','scolaire','modules/orientation/orientation.php'),
  ('inscriptions','Inscriptions','fas fa-user-plus','etablissement','modules/inscriptions/inscriptions.php'),
  ('signalements','Signalements','fas fa-exclamation-triangle','vie_scolaire','modules/signalements/signalements.php'),
  ('infirmerie','Infirmerie','fas fa-heartbeat','sante','modules/infirmerie/infirmerie.php'),
  ('examens','Examens','fas fa-pencil-alt','scolaire','modules/examens/examens.php'),
  ('ressources','Ressources','fas fa-archive','etablissement','modules/ressources/ressources.php'),
  ('diplomes','Diplômes','fas fa-certificate','etablissement','modules/diplomes/diplomes.php'),
  ('periscolaire','Périscolaire','fas fa-child','logistique','modules/periscolaire/services.php'),
  ('cantine','Cantine','fas fa-utensils','logistique','modules/cantine/menus.php'),
  ('internat','Internat','fas fa-bed','logistique','modules/internat/chambres.php'),
  ('garderie','Garderie','fas fa-baby','logistique','modules/garderie/creneaux.php'),
  ('stages','Stages','fas fa-briefcase','scolaire','modules/stages/stages.php'),
  ('transports','Transports','fas fa-bus','logistique','modules/transports/lignes.php'),
  ('facturation','Facturation','fas fa-receipt','etablissement','modules/facturation/factures.php'),
  ('salles','Salles','fas fa-door-open','logistique','modules/salles/reservations.php'),
  ('personnel','Personnel','fas fa-id-card','etablissement','modules/personnel/absences.php'),
  ('besoins','Besoins éducatifs','fas fa-hands-helping','vie_scolaire','modules/besoins/besoins.php'),
  ('archivage','Archivage','fas fa-archive','systeme','modules/archivage/archivage.php'),
  ('rgpd','RGPD','fas fa-shield-alt','systeme','rgpd/demandes.php'),
  ('support','Support','fas fa-life-ring','systeme','modules/support/aide.php'),
  ('projets_pedagogiques','Projets pédagogiques','fas fa-project-diagram','scolaire','modules/projets_pedagogiques/projets.php'),
  ('parcours_educatifs','Parcours éducatifs','fas fa-road','scolaire','modules/parcours_educatifs/parcours.php'),
  ('vie_associative','Vie associative','fas fa-heart','etablissement','modules/vie_associative/associations.php'),
  ('parametres','Paramètres','fas fa-cog','systeme','parametres/parametres.php'),
  ('profil','Profil','fas fa-user','systeme','modules/profil/index.php')
ON DUPLICATE KEY UPDATE route_path = VALUES(route_path);

-- ============================================================
-- Seeds : rbac_permissions — matrice RBAC initiale depuis RBAC::PERMISSIONS
-- ============================================================
INSERT IGNORE INTO `rbac_permissions` (`role`, `permission`, `granted`) VALUES
-- admin.* (administrateur only)
('administrateur','admin.access',1),('administrateur','admin.users',1),
('administrateur','admin.users.create',1),('administrateur','admin.users.delete',1),
('administrateur','admin.users.import',1),('administrateur','admin.scolaire',1),
('administrateur','admin.modules',1),('administrateur','admin.systeme',1),
('administrateur','admin.etablissement',1),('administrateur','admin.messagerie',1),
('administrateur','admin.classes',1),
-- notes
('administrateur','notes.view',1),('professeur','notes.view',1),('vie_scolaire','notes.view',1),('eleve','notes.view',1),('parent','notes.view',1),
('administrateur','notes.manage',1),('professeur','notes.manage',1),('vie_scolaire','notes.manage',1),
('administrateur','notes.edit',1),('professeur','notes.edit',1),
('administrateur','notes.delete',1),('administrateur','notes.lock',1),
-- absences
('administrateur','absences.view',1),('professeur','absences.view',1),('vie_scolaire','absences.view',1),('eleve','absences.view',1),('parent','absences.view',1),
('administrateur','absences.manage',1),('professeur','absences.manage',1),('vie_scolaire','absences.manage',1),
('administrateur','absences.validate',1),('vie_scolaire','absences.validate',1),
('eleve','absences.justify',1),('parent','absences.justify',1),
('administrateur','absences.stats',1),('vie_scolaire','absences.stats',1),
('administrateur','absences.export',1),('vie_scolaire','absences.export',1),
-- appel
('administrateur','appel.view',1),('professeur','appel.view',1),('vie_scolaire','appel.view',1),
('administrateur','appel.manage',1),('professeur','appel.manage',1),('vie_scolaire','appel.manage',1),
('administrateur','appel.correction',1),('professeur','appel.correction',1),
-- devoirs
('administrateur','devoirs.view',1),('professeur','devoirs.view',1),('eleve','devoirs.view',1),('parent','devoirs.view',1),
('administrateur','devoirs.manage',1),('professeur','devoirs.manage',1),
('eleve','devoirs.submit',1),
('administrateur','devoirs.correct',1),('professeur','devoirs.correct',1),
-- edt
('administrateur','edt.view',1),('professeur','edt.view',1),('vie_scolaire','edt.view',1),('eleve','edt.view',1),('parent','edt.view',1),
('administrateur','edt.manage',1),('vie_scolaire','edt.manage',1),
-- discipline
('administrateur','discipline.view',1),('vie_scolaire','discipline.view',1),('professeur','discipline.view',1),
('administrateur','discipline.manage',1),('vie_scolaire','discipline.manage',1),
('administrateur','discipline.signal',1),('professeur','discipline.signal',1),('vie_scolaire','discipline.signal',1),
-- bulletins
('administrateur','bulletins.view',1),('professeur','bulletins.view',1),('vie_scolaire','bulletins.view',1),('eleve','bulletins.view',1),('parent','bulletins.view',1),
('administrateur','bulletins.manage',1),('professeur','bulletins.manage',1),('vie_scolaire','bulletins.manage',1),
('administrateur','bulletins.generate',1),('vie_scolaire','bulletins.generate',1),
-- competences
('administrateur','competences.view',1),('professeur','competences.view',1),('vie_scolaire','competences.view',1),('eleve','competences.view',1),('parent','competences.view',1),
('administrateur','competences.manage',1),('professeur','competences.manage',1),
-- annonces
('administrateur','annonces.view',1),('professeur','annonces.view',1),('vie_scolaire','annonces.view',1),('eleve','annonces.view',1),('parent','annonces.view',1),
('administrateur','annonces.manage',1),('professeur','annonces.manage',1),('vie_scolaire','annonces.manage',1),
-- agenda
('administrateur','agenda.view',1),('professeur','agenda.view',1),('vie_scolaire','agenda.view',1),('eleve','agenda.view',1),('parent','agenda.view',1),
('administrateur','agenda.manage',1),('professeur','agenda.manage',1),('vie_scolaire','agenda.manage',1),
-- messagerie
('administrateur','messagerie.view',1),('professeur','messagerie.view',1),('vie_scolaire','messagerie.view',1),('eleve','messagerie.view',1),('parent','messagerie.view',1),
('administrateur','messagerie.send',1),('professeur','messagerie.send',1),('vie_scolaire','messagerie.send',1),('eleve','messagerie.send',1),('parent','messagerie.send',1),
-- documents
('administrateur','documents.view',1),('professeur','documents.view',1),('vie_scolaire','documents.view',1),('eleve','documents.view',1),('parent','documents.view',1),
('administrateur','documents.manage',1),('professeur','documents.manage',1),('vie_scolaire','documents.manage',1),
-- cahierdetextes
('administrateur','cahierdetextes.view',1),('professeur','cahierdetextes.view',1),('vie_scolaire','cahierdetextes.view',1),('eleve','cahierdetextes.view',1),('parent','cahierdetextes.view',1),
('administrateur','cahierdetextes.manage',1),('professeur','cahierdetextes.manage',1),
-- reunions
('administrateur','reunions.view',1),('professeur','reunions.view',1),('vie_scolaire','reunions.view',1),('parent','reunions.view',1),
('administrateur','reunions.manage',1),('vie_scolaire','reunions.manage',1),('professeur','reunions.manage',1),
('parent','reunions.reserve',1),
-- inscriptions
('administrateur','inscriptions.view',1),('vie_scolaire','inscriptions.view',1),
('administrateur','inscriptions.manage',1),('vie_scolaire','inscriptions.manage',1),
-- orientation
('administrateur','orientation.view',1),('professeur','orientation.view',1),('vie_scolaire','orientation.view',1),('eleve','orientation.view',1),('parent','orientation.view',1),
('administrateur','orientation.manage',1),('professeur','orientation.manage',1),('vie_scolaire','orientation.manage',1),
-- signalements
('administrateur','signalements.view',1),('vie_scolaire','signalements.view',1),
('administrateur','signalements.manage',1),('vie_scolaire','signalements.manage',1),
('administrateur','signalements.create',1),('professeur','signalements.create',1),('vie_scolaire','signalements.create',1),('eleve','signalements.create',1),
-- bibliotheque
('administrateur','bibliotheque.view',1),('professeur','bibliotheque.view',1),('vie_scolaire','bibliotheque.view',1),('eleve','bibliotheque.view',1),('parent','bibliotheque.view',1),
('administrateur','bibliotheque.manage',1),('vie_scolaire','bibliotheque.manage',1),
('eleve','bibliotheque.borrow',1),('professeur','bibliotheque.borrow',1),
-- clubs
('administrateur','clubs.view',1),('professeur','clubs.view',1),('vie_scolaire','clubs.view',1),('eleve','clubs.view',1),
('administrateur','clubs.manage',1),('vie_scolaire','clubs.manage',1),('professeur','clubs.manage',1),
('eleve','clubs.join',1),
-- infirmerie
('administrateur','infirmerie.view',1),('vie_scolaire','infirmerie.view',1),
('administrateur','infirmerie.manage',1),('vie_scolaire','infirmerie.manage',1),
-- support
('administrateur','support.view',1),('professeur','support.view',1),('vie_scolaire','support.view',1),('eleve','support.view',1),('parent','support.view',1),
('administrateur','support.manage',1),('vie_scolaire','support.manage',1),
('administrateur','support.create',1),('professeur','support.create',1),('vie_scolaire','support.create',1),('eleve','support.create',1),('parent','support.create',1),
-- examens
('administrateur','examens.view',1),('vie_scolaire','examens.view',1),('professeur','examens.view',1),('eleve','examens.view',1),
('administrateur','examens.manage',1),('vie_scolaire','examens.manage',1),
-- ressources
('administrateur','ressources.view',1),('professeur','ressources.view',1),('vie_scolaire','ressources.view',1),('eleve','ressources.view',1),
('administrateur','ressources.manage',1),('professeur','ressources.manage',1),
-- stages
('administrateur','stages.view',1),('professeur','stages.view',1),('vie_scolaire','stages.view',1),('eleve','stages.view',1),('parent','stages.view',1),
('administrateur','stages.manage',1),('vie_scolaire','stages.manage',1),('professeur','stages.manage',1),
-- facturation
('administrateur','facturation.view',1),('vie_scolaire','facturation.view',1),('parent','facturation.view',1),
('administrateur','facturation.manage',1),('vie_scolaire','facturation.manage',1),
-- cantine
('administrateur','cantine.view',1),('vie_scolaire','cantine.view',1),('eleve','cantine.view',1),('parent','cantine.view',1),
('administrateur','cantine.manage',1),('vie_scolaire','cantine.manage',1),
('parent','cantine.reserve',1),('eleve','cantine.reserve',1),
-- salles
('administrateur','salles.view',1),('vie_scolaire','salles.view',1),('professeur','salles.view',1),
('administrateur','salles.manage',1),('vie_scolaire','salles.manage',1),
('administrateur','salles.reserve',1),('vie_scolaire','salles.reserve',1),('professeur','salles.reserve',1),
-- periscolaire
('administrateur','periscolaire.view',1),('vie_scolaire','periscolaire.view',1),('parent','periscolaire.view',1),
('administrateur','periscolaire.manage',1),('vie_scolaire','periscolaire.manage',1),
-- personnel
('administrateur','personnel.view',1),('vie_scolaire','personnel.view',1),
('administrateur','personnel.manage',1),('vie_scolaire','personnel.manage',1),
-- transports
('administrateur','transports.view',1),('vie_scolaire','transports.view',1),('parent','transports.view',1),
('administrateur','transports.manage',1),('vie_scolaire','transports.manage',1),
-- diplomes
('administrateur','diplomes.view',1),('vie_scolaire','diplomes.view',1),('eleve','diplomes.view',1),('parent','diplomes.view',1),
('administrateur','diplomes.manage',1),('vie_scolaire','diplomes.manage',1),
-- archivage
('administrateur','archivage.view',1),('administrateur','archivage.manage',1),
-- trombinoscope
('administrateur','trombinoscope.view',1),('professeur','trombinoscope.view',1),('vie_scolaire','trombinoscope.view',1),
-- reporting
('administrateur','reporting.view',1),('professeur','reporting.view',1),('vie_scolaire','reporting.view',1),
('administrateur','reporting.export',1),('vie_scolaire','reporting.export',1),
-- rgpd
('administrateur','rgpd.view',1),('administrateur','rgpd.manage',1),
('administrateur','rgpd.my_data',1),('professeur','rgpd.my_data',1),('vie_scolaire','rgpd.my_data',1),('eleve','rgpd.my_data',1),('parent','rgpd.my_data',1),
-- vie_scolaire
('administrateur','vie_scolaire.view',1),('vie_scolaire','vie_scolaire.view',1),
('administrateur','vie_scolaire.manage',1),('vie_scolaire','vie_scolaire.manage',1),
-- notifications
('administrateur','notifications.view',1),('professeur','notifications.view',1),('vie_scolaire','notifications.view',1),('eleve','notifications.view',1),('parent','notifications.view',1),
-- parametres
('administrateur','parametres.view',1),('professeur','parametres.view',1),('vie_scolaire','parametres.view',1),('eleve','parametres.view',1),('parent','parametres.view',1),
-- projets
('administrateur','projets.view',1),('professeur','projets.view',1),('vie_scolaire','projets.view',1),
('administrateur','projets.manage',1),('professeur','projets.manage',1),
-- parcours
('administrateur','parcours.view',1),('professeur','parcours.view',1),('vie_scolaire','parcours.view',1),('eleve','parcours.view',1),('parent','parcours.view',1),
('administrateur','parcours.manage',1),('professeur','parcours.manage',1),
-- besoins
('administrateur','besoins.view',1),('professeur','besoins.view',1),('vie_scolaire','besoins.view',1),('parent','besoins.view',1),
('administrateur','besoins.manage',1),('vie_scolaire','besoins.manage',1),('professeur','besoins.manage',1),
-- internat
('administrateur','internat.view',1),('vie_scolaire','internat.view',1),
('administrateur','internat.manage',1),('vie_scolaire','internat.manage',1),
-- vie_associative
('administrateur','vie_associative.view',1),('vie_scolaire','vie_associative.view',1),('eleve','vie_associative.view',1),
('administrateur','vie_associative.manage',1),('vie_scolaire','vie_associative.manage',1);

-- ============================================================
-- Seeds : module_settings_schema — champs de configuration déclaratifs
-- ============================================================
INSERT IGNORE INTO `module_settings_schema` (`module_key`, `field_key`, `field_type`, `label`, `default_value`, `options`, `hint`, `sort_order`) VALUES
-- notes
('notes','note_max','number','Note maximale par défaut','20','{"min":1,"max":100}',NULL,10),
('notes','show_class_average','checkbox','Moyenne de classe','1','{"label":"Afficher la moyenne de classe"}',NULL,20),
('notes','show_rank','checkbox','Classement','0','{"label":"Afficher le classement"}',NULL,30),
('notes','decimal_places','number','Décimales affichées','2','{"min":0,"max":4}',NULL,40),
-- absences
('absences','auto_notify_parents','checkbox','Notification parents','1','{"label":"Notifier les parents automatiquement par email"}',NULL,10),
('absences','justification_delay_days','number','Délai de justification (jours)','15','{"min":1,"max":90}',NULL,20),
('absences','allowed_file_types','text','Types de fichiers acceptés','pdf,jpg,png',NULL,'Extensions séparées par des virgules',30),
-- bulletins
('bulletins','show_absences','checkbox','Absences sur bulletin','1','{"label":"Afficher le nombre d\'absences"}',NULL,10),
('bulletins','show_retards','checkbox','Retards sur bulletin','1','{"label":"Afficher le nombre de retards"}',NULL,20),
('bulletins','appreciation_max_length','number','Longueur max appréciation','500','{"min":100,"max":2000}',NULL,30),
('bulletins','pdf_template_type','select','Template PDF','standard','{"standard":"Standard","minimal":"Minimaliste","detailed":"Détaillé"}',NULL,40),
-- messagerie
('messagerie','max_message_length','number','Longueur max message','5000','{"min":500,"max":50000}',NULL,10),
('messagerie','allow_attachments','checkbox','Pièces jointes','1','{"label":"Autoriser les pièces jointes"}',NULL,20),
('messagerie','max_attachment_size_mb','number','Taille max pièce jointe (Mo)','5','{"min":1,"max":50}',NULL,30),
('messagerie','email_notification','checkbox','Notification email','0','{"label":"Envoyer un email pour chaque nouveau message"}',NULL,40),
-- emploi_du_temps
('emploi_du_temps','start_hour','text','Heure de début','08:00',NULL,'Format HH:MM',10),
('emploi_du_temps','end_hour','text','Heure de fin','18:00',NULL,'Format HH:MM',20),
('emploi_du_temps','slot_duration_minutes','number','Durée d\'un créneau (min)','60','{"min":15,"max":120}',NULL,30),
('emploi_du_temps','show_weekends','checkbox','Week-ends','0','{"label":"Afficher samedi et dimanche"}',NULL,40),
-- devoirs
('devoirs','max_file_size_mb','number','Taille max rendu (Mo)','10','{"min":1,"max":100}',NULL,10),
('devoirs','allowed_extensions','text','Extensions autorisées','pdf,doc,docx,odt,jpg,png',NULL,'Extensions séparées par des virgules',20),
('devoirs','late_submission','checkbox','Rendus en retard','0','{"label":"Autoriser les rendus après la date limite"}',NULL,30),
-- reunions
('reunions','slot_duration_minutes','number','Durée créneau par défaut (min)','15','{"min":5,"max":60}',NULL,10),
('reunions','max_slots_per_parent','number','Max créneaux par parent','5','{"min":1,"max":20}',NULL,20),
('reunions','send_confirmation_email','checkbox','Email de confirmation','1','{"label":"Envoyer un email de confirmation aux parents"}',NULL,30),
-- discipline
('discipline','auto_notify_parents','checkbox','Notification parents','1','{"label":"Notifier les parents des incidents"}',NULL,10),
('discipline','penalty_levels','textarea','Niveaux de sanction',"Avertissement\nBlâme\nExclusion temporaire\nExclusion définitive",NULL,'Un niveau par ligne',20),
-- inscriptions
('inscriptions','open_period','checkbox','Période d\'inscription ouverte','0','{"label":"Les inscriptions en ligne sont ouvertes"}',NULL,10),
('inscriptions','require_documents','text','Documents obligatoires','Carte identité,Justificatif domicile,Photo',NULL,'Séparés par des virgules',20),
-- periscolaire
('periscolaire','cantine_enabled','checkbox','Cantine','1','{"label":"Activer le module cantine"}',NULL,10),
('periscolaire','garderie_enabled','checkbox','Garderie','1','{"label":"Activer la garderie"}',NULL,20),
('periscolaire','tarif_cantine','number','Tarif cantine par défaut (€)','3','{"min":0,"max":50}',NULL,30),
-- facturation
('facturation','currency','select','Devise','EUR','{"EUR":"Euro (€)","USD":"Dollar ($)","GBP":"Livre (£)","CHF":"Franc suisse (CHF)"}',NULL,10),
('facturation','tva_rate','number','Taux TVA par défaut (%)','0','{"min":0,"max":30}',NULL,20),
('facturation','payment_reminder_days','number','Rappel paiement (jours)','30','{"min":7,"max":90}',NULL,30);

-- ============================================================
-- PHASE 1 : Marketplace & Themes
-- ============================================================

CREATE TABLE `marketplace_installs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_key` VARCHAR(100) NOT NULL,
  `item_type` ENUM('module','theme') NOT NULL DEFAULT 'module',
  `version` VARCHAR(20) NOT NULL DEFAULT '1.0.0',
  `author` VARCHAR(100) DEFAULT NULL,
  `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_marketplace_item` (`item_key`, `item_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `themes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `author` VARCHAR(100) DEFAULT 'Custom',
  `version` VARCHAR(20) DEFAULT '1.0.0',
  `css_file` VARCHAR(255) NOT NULL,
  `preview_image` VARCHAR(255) DEFAULT NULL,
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `theme_token_overrides` (
  `theme_key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `overrides` JSON DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PHASE 2 : Push Notifications
-- ============================================================

CREATE TABLE `push_subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(30) NOT NULL,
  `endpoint` TEXT NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL,
  `auth` VARCHAR(255) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_push_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PHASE 3 : SMS & Email amélioré
-- ============================================================

CREATE TABLE `sms_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'twilio',
  `api_key` VARCHAR(255) DEFAULT NULL,
  `api_secret` VARCHAR(255) DEFAULT NULL,
  `sender_name` VARCHAR(20) DEFAULT 'Fronote',
  `actif` TINYINT(1) NOT NULL DEFAULT 0,
  `monthly_quota` INT DEFAULT 1000,
  `used_this_month` INT DEFAULT 0,
  `quota_reset_at` DATE DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sms_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `recipient` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('pending','sent','delivered','failed') NOT NULL DEFAULT 'pending',
  `provider_id` VARCHAR(100) DEFAULT NULL,
  `error` TEXT DEFAULT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `html_body` TEXT NOT NULL,
  `variables` JSON DEFAULT NULL,
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `email_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `to_address` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `template_key` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT DEFAULT 0,
  `error` TEXT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed email templates
INSERT INTO `email_templates` (`key`, `name`, `subject`, `html_body`, `variables`) VALUES
('welcome', 'Bienvenue', 'Bienvenue sur Fronote', '<h2>Bienvenue {{prenom}} !</h2><p>Votre compte a été créé sur <strong>{{etablissement}}</strong>.</p><p>Identifiant : <code>{{identifiant}}</code></p>', '["prenom","etablissement","identifiant"]'),
('reset_password', 'Réinitialisation', 'Réinitialisation de votre mot de passe', '<h2>Réinitialisation</h2><p>Cliquez sur le lien ci-dessous pour réinitialiser votre mot de passe :</p><p><a href="{{reset_url}}">{{reset_url}}</a></p><p>Ce lien expire dans {{expiry}}.</p>', '["reset_url","expiry"]'),
('absence', 'Absence', 'Absence de {{eleve}}', '<h2>Notification d\'absence</h2><p>{{eleve}} ({{classe}}) a été signalé(e) absent(e) le {{date}} de {{heure_debut}} à {{heure_fin}}.</p><p>Motif : {{motif}}</p>', '["eleve","classe","date","heure_debut","heure_fin","motif"]'),
('bulletin', 'Bulletin disponible', 'Bulletin de {{periode}} disponible', '<h2>Bulletin scolaire</h2><p>Le bulletin de {{eleve}} pour la période <strong>{{periode}}</strong> est désormais disponible.</p><p><a href="{{url}}">Consulter le bulletin</a></p>', '["eleve","periode","url"]'),
('reunion', 'Invitation réunion', 'Réunion parents-professeurs le {{date}}', '<h2>Réunion parents-professeurs</h2><p>Vous êtes invité(e) à la réunion du <strong>{{date}}</strong> à <strong>{{heure}}</strong>.</p><p>Lieu : {{lieu}}</p><p><a href="{{url}}">Réserver un créneau</a></p>', '["date","heure","lieu","url"]'),
('annonce', 'Annonce', '{{titre}}', '<h2>{{titre}}</h2><div>{{contenu}}</div><p>— {{auteur}}</p>', '["titre","contenu","auteur"]');

-- ============================================================
-- PHASE 4 : Sécurité IP Firewall
-- ============================================================

CREATE TABLE `ip_blocklist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `auto_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  UNIQUE KEY `uq_ip` (`ip`),
  INDEX `idx_ip_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PHASE 7 : Paiements & Signatures
-- ============================================================

CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(30) NOT NULL DEFAULT 'parent',
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `description` VARCHAR(255) DEFAULT NULL,
  `provider` VARCHAR(30) NOT NULL DEFAULT 'stripe',
  `provider_reference` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  INDEX `idx_payment_user` (`user_id`, `user_type`),
  INDEX `idx_payment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `signatures` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_type` VARCHAR(50) NOT NULL,
  `document_id` INT NOT NULL,
  `signer_id` INT NOT NULL,
  `signer_type` VARCHAR(30) NOT NULL,
  `signature_hash` VARCHAR(64) NOT NULL,
  `signature_data` MEDIUMTEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sig_document` (`document_type`, `document_id`),
  INDEX `idx_sig_signer` (`signer_id`, `signer_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MULTI-ÉTABLISSEMENT : ajout etablissement_id sur les tables scopées
-- Toutes les tables sont scopées par défaut sur l'établissement 1
-- ============================================================

-- Utilisateurs (5 tables)
ALTER TABLE `administrateurs`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_administrateurs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `eleves`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_eleves_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `professeurs`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_professeurs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `parents`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_parents_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `vie_scolaire`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_vie_scolaire_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

-- Référentiels scolaires (3 tables)
ALTER TABLE `periodes`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_periodes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `matieres`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_matieres_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `classes`
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_classes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

-- Évaluations et notes (4 tables)




-- Absences et vie scolaire (6 tables)







-- Emploi du temps (3 tables)



-- Devoirs (1 table)

-- Communication (7 tables)



ALTER TABLE `notifications_globales`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_notif_globales_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);




-- Réunions et support (4 tables)




-- Inscriptions et orientation (3 tables)



-- Bibliothèque et clubs (4 tables)




-- Santé (2 tables)


-- RGPD (2 tables)
ALTER TABLE `rgpd_consentements`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_rgpd_consent_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `rgpd_demandes`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_rgpd_demandes_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

-- Examens (2 tables)


-- Besoins, personnel, remplacements (3 tables)



-- Salles et matériels (2 tables)


-- Périscolaire (2 tables)






-- Stages et transport (3 tables)



-- Internat (2 tables)


-- Facturation (2 tables)


-- Ressources, diplômes (2 tables)


-- Projets, parcours, associations (3 tables)



-- Archives (1 table)

-- Configuration scopée par établissement (6 tables)
ALTER TABLE `modules_config`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_modules_config_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `feature_flags`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_feature_flags_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `user_settings`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_user_settings_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `user_profiles`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_user_profiles_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `rbac_permissions`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_rbac_perm_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `notification_preferences`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_notif_prefs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

-- Sécurité et audit (2 tables)
ALTER TABLE `audit_log`
  ADD COLUMN `etablissement_id` INT DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_audit_etab` (`etablissement_id`);

ALTER TABLE `api_tokens`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_api_tokens_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `webhooks`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_webhooks_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `pdf_templates`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_pdf_templates_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `smtp_config`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_smtp_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

ALTER TABLE `dashboard_layouts`
  ADD COLUMN `etablissement_id` INT NOT NULL DEFAULT 1 AFTER `id`,
  ADD INDEX `idx_etab` (`etablissement_id`),
  ADD CONSTRAINT `fk_dash_layouts_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`);

-- Rendre les UNIQUE keys compatibles multi-établissement
-- (identifiant unique PAR établissement, pas globalement)
ALTER TABLE `administrateurs` DROP INDEX `identifiant`, ADD UNIQUE KEY `uk_admin_ident_etab` (`identifiant`, `etablissement_id`);
ALTER TABLE `eleves` DROP INDEX `identifiant`, ADD UNIQUE KEY `uk_eleve_ident_etab` (`identifiant`, `etablissement_id`);
ALTER TABLE `professeurs` DROP INDEX `identifiant`, ADD UNIQUE KEY `uk_prof_ident_etab` (`identifiant`, `etablissement_id`);
ALTER TABLE `parents` DROP INDEX `identifiant`, ADD UNIQUE KEY `uk_parent_ident_etab` (`identifiant`, `etablissement_id`);
ALTER TABLE `vie_scolaire` DROP INDEX `identifiant`, ADD UNIQUE KEY `uk_vs_ident_etab` (`identifiant`, `etablissement_id`);
ALTER TABLE `classes` DROP INDEX `nom_annee`, ADD UNIQUE KEY `uk_classe_etab` (`nom`, `annee_scolaire`, `etablissement_id`);
ALTER TABLE `matieres` DROP INDEX `code`, ADD UNIQUE KEY `uk_matiere_etab` (`code`, `etablissement_id`);
ALTER TABLE `modules_config` DROP INDEX `uk_module_key`, ADD UNIQUE KEY `uk_module_etab` (`module_key`, `etablissement_id`);
ALTER TABLE `feature_flags` DROP INDEX `uk_flag`, ADD UNIQUE KEY `uk_flag_etab` (`flag_key`, `etablissement_id`);

-- Rendre les UNIQUE keys `mail` compatibles multi-établissement
-- (mail unique PAR établissement, pas globalement)
ALTER TABLE `administrateurs` DROP INDEX `mail`, ADD UNIQUE KEY `uk_mail_etab` (`mail`, `etablissement_id`);
ALTER TABLE `eleves` DROP INDEX `mail`, ADD UNIQUE KEY `uk_mail_etab` (`mail`, `etablissement_id`);
ALTER TABLE `professeurs` DROP INDEX `mail`, ADD UNIQUE KEY `uk_mail_etab` (`mail`, `etablissement_id`);
ALTER TABLE `parents` DROP INDEX `mail`, ADD UNIQUE KEY `uk_mail_etab` (`mail`, `etablissement_id`);
ALTER TABLE `vie_scolaire` DROP INDEX `mail`, ADD UNIQUE KEY `uk_mail_etab` (`mail`, `etablissement_id`);

-- ============================================================
-- V1.5.0 MODULE IMPROVEMENTS — New tables and columns
-- ============================================================

-- Phase 6: Notes — locked notes, calculation cache

CREATE TABLE IF NOT EXISTS `note_calculations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `classe_id` INT NOT NULL,
  `matiere_id` INT NOT NULL,
  `periode_id` INT NOT NULL,
  `type` ENUM('moyenne','mediane','min','max') NOT NULL,
  `value` DECIMAL(5,2) NOT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_calc` (`classe_id`, `matiere_id`, `periode_id`, `type`, `etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 6: Competences — referentiel
CREATE TABLE IF NOT EXISTS `referentiel_competences` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `domaine` VARCHAR(200) NOT NULL,
  `sous_domaine` VARCHAR(200) DEFAULT NULL,
  `item` VARCHAR(500) NOT NULL,
  `niveau_attendu` TINYINT DEFAULT 3,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_etab` (`etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Phase 6: Bulletins — templates and appreciations
CREATE TABLE IF NOT EXISTS `bulletin_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_key` VARCHAR(100) DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `html_template` TEXT NOT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_template_key_etab` (`template_key`, `etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bulletin_appreciations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bulletin_id` INT NOT NULL,
  `prof_id` INT DEFAULT NULL,
  `matiere_id` INT DEFAULT NULL,
  `texte` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_bulletin` (`bulletin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 6: Devoirs — rendus
CREATE TABLE IF NOT EXISTS `devoir_rendus` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `devoir_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `fichier_path` VARCHAR(500) DEFAULT NULL,
  `rendu_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_late` TINYINT(1) NOT NULL DEFAULT 0,
  `note` DECIMAL(5,2) DEFAULT NULL,
  `commentaire_prof` TEXT DEFAULT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_devoir_eleve` (`devoir_id`, `eleve_id`),
  INDEX `idx_etab` (`etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 6: Cahier de textes — pieces jointes
CREATE TABLE IF NOT EXISTS `cahier_pieces_jointes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `entree_id` INT NOT NULL,
  `fichier_path` VARCHAR(500) NOT NULL,
  `nom_original` VARCHAR(255) NOT NULL,
  `taille` INT DEFAULT 0,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_entree` (`entree_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 6: Emploi du temps — remplacement enhancement

-- Phase 6: Examens — new tables
CREATE TABLE IF NOT EXISTS `examen_salles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `examen_id` INT NOT NULL,
  `salle_id` INT NOT NULL,
  `nb_places` INT NOT NULL DEFAULT 30,
  INDEX `idx_examen` (`examen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examen_surveillants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `examen_id` INT NOT NULL,
  `prof_id` INT NOT NULL,
  `salle_id` INT DEFAULT NULL,
  INDEX `idx_examen` (`examen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `examen_convocations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `examen_id` INT NOT NULL,
  `eleve_id` INT NOT NULL,
  `salle_id` INT DEFAULT NULL,
  `place` VARCHAR(20) DEFAULT NULL,
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  INDEX `idx_examen` (`examen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 6: Agenda — recurrence

-- Phase 7: Absences — pattern detection

CREATE TABLE IF NOT EXISTS `absence_patterns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `pattern_type` VARCHAR(50) NOT NULL,
  `details_json` JSON DEFAULT NULL,
  `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_eleve` (`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Appel — completion tracking

-- Phase 7: Discipline — points system
ALTER TABLE `eleves`
  ADD COLUMN `discipline_points` INT DEFAULT 0;

CREATE TABLE IF NOT EXISTS `discipline_points` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `valeur` INT NOT NULL,
  `motif` TEXT DEFAULT NULL,
  `prof_id` INT DEFAULT NULL,
  `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_eleve` (`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discipline_seuils` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `points_min` INT NOT NULL,
  `sanction_type` VARCHAR(100) NOT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Vie scolaire — suivi decrochage
CREATE TABLE IF NOT EXISTS `suivi_eleves` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `risque_decrochage` DECIMAL(3,2) DEFAULT 0.00,
  `derniere_analyse` DATE DEFAULT NULL,
  `notes_json` JSON DEFAULT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_eleve` (`eleve_id`, `etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Reporting — custom report templates
CREATE TABLE IF NOT EXISTS `report_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nom` VARCHAR(200) NOT NULL,
  `config_json` JSON NOT NULL,
  `schedule_cron` VARCHAR(50) DEFAULT NULL,
  `email_to` VARCHAR(500) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Signalements — tracking

-- Phase 7: Messagerie — threads, reactions, archive

-- Phase 7: Annonces — scheduled publish, read receipts

CREATE TABLE IF NOT EXISTS `annonce_lectures` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `annonce_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(30) NOT NULL,
  `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_lecture` (`annonce_id`, `user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Reunions — creneaux, PV
CREATE TABLE IF NOT EXISTS `reunion_pv` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `reunion_id` INT NOT NULL,
  `creneau_id` INT DEFAULT NULL,
  `contenu` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 7: Documents — versioning
CREATE TABLE IF NOT EXISTS `document_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `fichier_path` VARCHAR(500) NOT NULL,
  `uploaded_by` INT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_doc` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Phase 7: Notifications — preferences
CREATE TABLE IF NOT EXISTS `notification_user_preferences` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `user_type` VARCHAR(30) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'web',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_pref` (`user_id`, `user_type`, `category`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 8: Inscriptions — workflow steps

-- Phase 8: Facturation — relance tracking

-- Phase 8: Stages — journal, evaluations, entreprises
CREATE TABLE IF NOT EXISTS `stage_journal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `stage_id` INT NOT NULL,
  `semaine` INT NOT NULL,
  `contenu` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_stage` (`stage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stage_evaluations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `stage_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `grille_json` JSON DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uk_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entreprises` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nom` VARCHAR(200) NOT NULL,
  `adresse` VARCHAR(500) DEFAULT NULL,
  `contact_nom` VARCHAR(150) DEFAULT NULL,
  `contact_email` VARCHAR(150) DEFAULT NULL,
  `secteur` VARCHAR(100) DEFAULT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_etab` (`etablissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 8: Transports — delay alerts

-- Phase 8: Salles — equipment tracking

-- Phase 8: Cantine — allergen alerts
CREATE TABLE IF NOT EXISTS `eleve_allergies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `eleve_id` INT NOT NULL,
  `allergene` VARCHAR(100) NOT NULL,
  `severity` ENUM('low','medium','high','critical') DEFAULT 'medium',
  `etablissement_id` INT NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_allergie` (`eleve_id`, `allergene`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 8: Garderie — attendance

-- Phase 8: Periscolaire — waitlist

-- Phase 8: Bibliotheque — ISBN, reservations

-- Phase 8: Infirmerie — vaccination, protocols

-- Phase 8: Trombinoscope — photo consent
ALTER TABLE `eleves`
  ADD COLUMN `photo_consent` TINYINT(1) DEFAULT 0;
ALTER TABLE `professeurs`
  ADD COLUMN `photo_consent` TINYINT(1) DEFAULT 0;

-- Phase 8: Diplomes — PDF

-- Phase 8: Internat — attendance tracking

-- Phase 8: Ressources — sharing

-- Phase 9: User settings — privacy
ALTER TABLE `user_settings`
  ADD COLUMN `privacy_level` ENUM('public','private') DEFAULT 'public';

-- Phase 9: Plans accompagnement — type

-- Phase 9: Orientation — avis conseil

-- Phase 9: Parcours educatifs — portfolio

-- Phase 9: Projets pedagogiques — budget, status

-- Phase 9: Vie associative — budget

-- Phase 9: RGPD — purge tracking
ALTER TABLE `rgpd_demandes`
  ADD COLUMN `purge_completed_at` DATETIME DEFAULT NULL;

-- Phase 9: Support — SLA

-- Phase 9: Archivage — archives table
CREATE TABLE IF NOT EXISTS `archives` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `annee_scolaire` VARCHAR(10) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `data_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_annee` (`annee_scolaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 9: Personnel — leave management
CREATE TABLE IF NOT EXISTS `personnel_conges` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `personnel_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `date_debut` DATE NOT NULL,
  `date_fin` DATE NOT NULL,
  `statut` ENUM('demande','valide','refuse') DEFAULT 'demande',
  `justificatif_path` VARCHAR(500) DEFAULT NULL,
  `valide_par` INT DEFAULT NULL,
  `etablissement_id` INT NOT NULL DEFAULT 1,
  INDEX `idx_personnel` (`personnel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V1.5.0 Feature Flags (new granular flags for module improvements)
INSERT IGNORE INTO `feature_flags` (`flag_key`, `label`, `description`, `establishment_types`, `enabled`, `config`) VALUES
-- Notes
('notes.batch_entry',         'Saisie par lot',          'Grille de saisie multi-eleves', NULL, 1, NULL),
('notes.statistics_graphs',   'Graphiques statistiques', 'Histogrammes et courbes de notes', NULL, 1, NULL),
-- Competences
('competences.radar_graph',   'Graphe radar competences',   'Radar chart par eleve',        NULL, 1, NULL),
('competences.link_to_grades','Liaison notes-competences',   'Lier evaluations aux competences', NULL, 0, NULL),
-- Bulletins
('bulletins.custom_templates','Templates personnalises',     'Choix de template PDF',        NULL, 0, NULL),
-- Emploi du temps
('emploi_du_temps.drag_drop_editor','Editeur drag-drop EDT', 'Deplacer creneaux par glisser', NULL, 0, NULL),
('emploi_du_temps.ical_export',     'Export iCal EDT',       'Synchroniser avec calendrier externe', NULL, 1, NULL),
('emploi_du_temps.replacements',    'Gestion remplacements', 'Affecter remplacants', NULL, 1, NULL),
-- Absences
('absences.pattern_detection','Detection de patterns',       'Analyse automatique des tendances', NULL, 0, NULL),
('absences.grouped_entry',   'Saisie groupee absences',     'Cocher plusieurs eleves en lot', NULL, 1, NULL),
-- Discipline
('discipline.auto_sanctions', 'Sanctions automatiques',      'Sanctions basees sur les points', NULL, 0, NULL),
('discipline.pdf_report',    'Rapport PDF discipline',       'Generer un rapport PDF par eleve', NULL, 1, NULL),
-- Vie scolaire
('vie_scolaire.dropout_detection','Detection decrochage',    'Algorithme de detection risque', NULL, 0, NULL),
('vie_scolaire.consolidated_dashboard','Dashboard consolide','Vue unifiee absences+discipline', NULL, 1, NULL),
-- Messagerie
('messagerie.threads',       'Fils de discussion',          'Reponses imbriquees', NULL, 0, NULL),
('messagerie.search',        'Recherche messagerie',        'Recherche full-text', NULL, 1, NULL),
-- Annonces
('annonces.polls',           'Sondages dans annonces',      'Questions integrees', NULL, 1, NULL),
-- Reunions
('reunions.meeting_notes',   'PV de reunion',               'Compte-rendu apres reunion', NULL, 0, NULL),
-- Documents
('documents.versioning',     'Versionnement documents',     'Historique des versions', NULL, 0, NULL),
('documents.search',         'Recherche documents',         'Filtrer par nom/type/date', NULL, 1, NULL),
-- Notifications
('notifications.digest_mode','Resume quotidien notifs',     'Regrouper en email quotidien', NULL, 0, '{"hour": 18}'),
('notifications.preferences_page','Preferences par module','Configurer notifs par module', NULL, 1, NULL),
-- Reporting
('reporting.custom_builder', 'Rapports personnalises',      'Builder de rapports', NULL, 0, NULL),
('reporting.scheduled_reports','Rapports programmes',        'Envoi automatique', NULL, 0, NULL),
('reporting.xlsx_export',    'Export XLSX',                   'Export au format Excel', NULL, 0, NULL),
-- Cantine
('cantine.statistics',       'Statistiques cantine',        'Frequentation et previsions', NULL, 1, NULL),
-- Garderie
('garderie.auto_billing',   'Facturation garderie auto',    'Heures -> facture', NULL, 0, NULL),
-- Stages
('stages.journal',          'Journal de bord stage',        'Entrees hebdomadaires', NULL, 1, NULL),
('stages.external_evaluation','Evaluation tuteur',          'Formulaire en ligne', NULL, 0, NULL),
-- Personnel
('personnel.leave_management','Gestion conges',             'Demande et validation', NULL, 1, NULL),
('personnel.conflict_detection','Detection conflits',       'Alerter absence sans remplacant', NULL, 0, NULL),
('personnel.directory',      'Annuaire personnel',          'Fiches completes', NULL, 1, NULL),
-- Besoins
('besoins.pap',             'Plan PAP',                     'Plan Accompagnement Personnalise', NULL, 1, NULL),
('besoins.ppre',            'Programme PPRE',               'Programme Reussite Educative', NULL, 1, NULL),
('besoins.pps',             'Projet PPS',                   'Projet Personnalise Scolarisation', NULL, 1, NULL),
-- Orientation
('orientation.career_catalog','Catalogue metiers',          'Fiches metiers/formations', NULL, 1, NULL),
('orientation.wishes',       'Voeux orientation',           'Saisie voeux par eleve', NULL, 1, NULL),
('orientation.counselor_booking','RDV conseiller',          'Prise de RDV orientation', NULL, 0, NULL),
-- Parcours
('parcours.validation',     'Validation parcours',          'Prof valide les entries', NULL, 1, NULL),
-- Projets
('projets.budget_tracking', 'Suivi budget projets',         'Budget prevu vs depense', NULL, 0, NULL),
('projets.kanban',          'Kanban projets',               'Vue kanban du cycle de vie', NULL, 0, NULL),
-- Vie associative
('vie_associative.budget',  'Budget associations',          'Recettes/depenses', NULL, 0, NULL),
('vie_associative.events',  'Evenements associatifs',       'Organisation evenements', NULL, 1, NULL),
-- Accueil
('accueil.drag_drop',       'Widgets drag-drop',            'Repositionner les widgets', NULL, 0, NULL),
('accueil.custom_widgets',  'Widgets configurables',        'Options par widget', NULL, 1, NULL),
-- Profil
('profil.activity_timeline','Timeline activite',            'Historique actions recentes', NULL, 0, NULL),
('profil.data_export',      'Export donnees profil',        'Telecharger ses donnees (RGPD)', NULL, 1, NULL),
-- Archivage
('archivage.student_transfer','Transfert eleve',            'Export dossier pour transfert', NULL, 0, NULL),
-- Internat
('internat.room_management','Gestion chambres',             'Plan et capacite', NULL, 1, NULL),
('internat.attendance',     'Pointage internat',            'Presence soir/matin', NULL, 1, NULL)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ============================================================
-- v2.0.0 "Nova" — Global Infrastructure Tables
-- ============================================================

-- Annees scolaires
CREATE TABLE IF NOT EXISTS annees_scolaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    code VARCHAR(10) NOT NULL,
    libelle VARCHAR(50) NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    actif TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_annee_etab (etablissement_id, actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Champs personnalises
CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    field_key VARCHAR(50) NOT NULL,
    field_type ENUM('text','number','date','select','checkbox','textarea') DEFAULT 'text',
    label VARCHAR(100) NOT NULL,
    options JSON,
    required TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cf_entity_key (etablissement_id, entity_type, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS custom_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_id INT NOT NULL,
    entity_id INT NOT NULL,
    value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cfv (field_id, entity_id),
    INDEX idx_cfv_entity (entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Calendrier academique
CREATE TABLE IF NOT EXISTS calendrier_academique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    date DATE NOT NULL,
    type ENUM('cours','vacances','ferie','pont','formation','examen') NOT NULL,
    libelle VARCHAR(100),
    annee_scolaire VARCHAR(10),
    INDEX idx_cal_etab_date (etablissement_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Routage notifications avance
CREATE TABLE IF NOT EXISTS notification_routing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    canal ENUM('email','sms','push','websocket') NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    template_id INT,
    roles_cibles JSON,
    UNIQUE KEY uk_nr_event_canal (event_type, canal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 1 : Evaluations en Ligne
-- ============================================================

CREATE TABLE IF NOT EXISTS evaluation_banques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    matiere_id INT,
    professeur_id INT NOT NULL,
    description TEXT,
    tags JSON,
    statut ENUM('brouillon','publiee','archivee') DEFAULT 'brouillon',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_eb_prof (professeur_id),
    INDEX idx_eb_mat (matiere_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    banque_id INT NOT NULL,
    type_question ENUM('qcm','vrai_faux','trous','association','courte','longue') NOT NULL,
    enonce TEXT NOT NULL,
    reponses_possibles JSON,
    reponse_correcte JSON,
    points DECIMAL(5,2) DEFAULT 1.00,
    difficulte ENUM('facile','moyen','difficile') DEFAULT 'moyen',
    explication TEXT,
    media_path VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_eq_banque (banque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluations_en_ligne (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    professeur_id INT NOT NULL,
    matiere_id INT,
    classe VARCHAR(20),
    questions_config JSON NOT NULL,
    duree_minutes INT DEFAULT 60,
    date_ouverture DATETIME,
    date_fermeture DATETIME,
    mode ENUM('examen','entrainement','devoir') DEFAULT 'examen',
    anti_triche TINYINT(1) DEFAULT 1,
    melanger_questions TINYINT(1) DEFAULT 0,
    melanger_reponses TINYINT(1) DEFAULT 0,
    note_sur DECIMAL(5,2) DEFAULT 20.00,
    statut ENUM('brouillon','ouverte','fermee','archivee') DEFAULT 'brouillon',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_eel_prof (professeur_id),
    INDEX idx_eel_classe (classe),
    INDEX idx_eel_dates (date_ouverture, date_fermeture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    eleve_id INT NOT NULL,
    date_debut DATETIME,
    date_fin DATETIME,
    score DECIMAL(5,2),
    note_sur DECIMAL(5,2),
    statut ENUM('en_cours','soumis','corrige','abandonne') DEFAULT 'en_cours',
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    events_log JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_es_eval_eleve (evaluation_id, eleve_id),
    INDEX idx_es_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_reponses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    reponse_donnee JSON,
    correct TINYINT(1),
    points_obtenus DECIMAL(5,2) DEFAULT 0,
    correction_manuelle TEXT,
    corrige_par INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_er_session_q (session_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_statistiques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_id INT,
    nb_reponses INT DEFAULT 0,
    nb_correct INT DEFAULT 0,
    taux_reussite DECIMAL(5,2) DEFAULT 0,
    indice_discrimination DECIMAL(5,4),
    temps_moyen_secondes INT,
    UNIQUE KEY uk_estat (evaluation_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 2 : Conseil de Classe
-- ============================================================

CREATE TABLE IF NOT EXISTS conseil_classe_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    classe_id VARCHAR(20) NOT NULL,
    periode_id INT,
    annee_scolaire VARCHAR(10),
    date_conseil DATETIME NOT NULL,
    lieu VARCHAR(100),
    president_id INT,
    president_type VARCHAR(30),
    secretaire_id INT,
    secretaire_type VARCHAR(30),
    statut ENUM('planifie','en_cours','termine','archive') DEFAULT 'planifie',
    pv_path VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ccs_classe (classe_id, annee_scolaire)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conseil_classe_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    role ENUM('president','secretaire','professeur','delegue_parent','delegue_eleve','CPE','direction','invite') DEFAULT 'professeur',
    present TINYINT(1) DEFAULT 0,
    heure_arrivee TIME,
    heure_depart TIME,
    UNIQUE KEY uk_ccp (session_id, user_id, user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conseil_classe_eleve_discussions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    eleve_id INT NOT NULL,
    ordre INT DEFAULT 0,
    appreciation TEXT,
    avis_propose ENUM('felicitations','compliments','encouragements','avertissement_travail','avertissement_conduite','aucun') DEFAULT 'aucun',
    avis_vote_pour INT DEFAULT 0,
    avis_vote_contre INT DEFAULT 0,
    avis_vote_abstention INT DEFAULT 0,
    avis_final ENUM('felicitations','compliments','encouragements','avertissement_travail','avertissement_conduite','aucun'),
    commentaire_delegue_parent TEXT,
    commentaire_delegue_eleve TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cced (session_id, eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conseil_classe_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discussion_id INT NOT NULL,
    voter_id INT NOT NULL,
    voter_type VARCHAR(30) NOT NULL,
    vote ENUM('pour','contre','abstention') NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ccv (discussion_id, voter_id, voter_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conseil_classe_synthese (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL UNIQUE,
    synthese_generale TEXT,
    points_positifs TEXT,
    points_amelioration TEXT,
    decisions TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 3 : Portail Parents
-- ============================================================

CREATE TABLE IF NOT EXISTS portail_parents_autorisations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    parent_id INT NOT NULL,
    eleve_id INT NOT NULL,
    type ENUM('sortie_anticipee','sortie_scolaire','droit_image','informatique','medicale','custom') NOT NULL,
    motif TEXT,
    date_debut DATETIME,
    date_fin DATETIME,
    qr_token VARCHAR(64),
    statut ENUM('demandee','approuvee','utilisee','expiree','refusee') DEFAULT 'demandee',
    approuve_par INT,
    approuve_par_type VARCHAR(30),
    signature_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ppa_parent (parent_id),
    INDEX idx_ppa_eleve (eleve_id),
    INDEX idx_ppa_qr (qr_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portail_parents_documents_a_signer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    fichier_path VARCHAR(255),
    cible_classes JSON,
    cible_niveaux JSON,
    date_limite DATE,
    obligatoire TINYINT(1) DEFAULT 0,
    type_document VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portail_parents_signatures_doc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    parent_id INT NOT NULL,
    eleve_id INT NOT NULL,
    signature_id INT,
    signe_le DATETIME,
    UNIQUE KEY uk_ppsd (document_id, parent_id, eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portail_parents_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL UNIQUE,
    notifications_resume_quotidien TINYINT(1) DEFAULT 1,
    notifications_notes TINYINT(1) DEFAULT 1,
    notifications_absences TINYINT(1) DEFAULT 1,
    notifications_discipline TINYINT(1) DEFAULT 1,
    langue VARCHAR(5) DEFAULT 'fr',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 4 : Enquetes & Satisfaction
-- ============================================================

CREATE TABLE IF NOT EXISTS enquetes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    type ENUM('satisfaction','climat_scolaire','evaluation','custom') DEFAULT 'custom',
    cible_roles JSON,
    cible_classes JSON,
    cible_niveaux JSON,
    anonyme TINYINT(1) DEFAULT 0,
    multi_pages TINYINT(1) DEFAULT 0,
    date_ouverture DATETIME,
    date_fermeture DATETIME,
    statut ENUM('brouillon','ouverte','fermee','archivee') DEFAULT 'brouillon',
    creee_par INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enq_statut (statut, date_ouverture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquete_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquete_id INT NOT NULL,
    titre VARCHAR(200),
    description TEXT,
    ordre INT DEFAULT 0,
    INDEX idx_ep_enquete (enquete_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquete_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    type ENUM('likert','nps','choix_unique','choix_multiple','texte','matrice','nombre') NOT NULL,
    enonce TEXT NOT NULL,
    obligatoire TINYINT(1) DEFAULT 0,
    options JSON,
    configuration JSON,
    ordre INT DEFAULT 0,
    INDEX idx_eqq_page (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquete_participations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquete_id INT NOT NULL,
    participant_hash VARCHAR(64),
    user_id INT,
    user_type VARCHAR(30),
    date_soumission DATETIME,
    completed TINYINT(1) DEFAULT 0,
    UNIQUE KEY uk_ep_hash (enquete_id, participant_hash),
    INDEX idx_ep_enquete (enquete_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquete_reponses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participation_id INT NOT NULL,
    question_id INT NOT NULL,
    valeur_texte TEXT,
    valeur_numero DECIMAL(10,2),
    valeur_json JSON,
    INDEX idx_er_part (participation_id),
    INDEX idx_er_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 5 : Tutorat & Entraide
-- ============================================================

CREATE TABLE IF NOT EXISTS tutorat_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    tuteur_eleve_id INT NOT NULL,
    tutore_eleve_id INT NOT NULL,
    matiere_id INT,
    professeur_validateur_id INT,
    date_debut DATE,
    date_fin DATE,
    statut ENUM('propose','actif','termine','annule') DEFAULT 'propose',
    score_amelioration DECIMAL(5,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tp_tuteur (tuteur_eleve_id),
    INDEX idx_tp_tutore (tutore_eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tutorat_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pair_id INT NOT NULL,
    date_session DATE NOT NULL,
    heure_debut TIME,
    heure_fin TIME,
    salle_id INT,
    sujet TEXT,
    compte_rendu TEXT,
    statut ENUM('planifiee','realisee','annulee') DEFAULT 'planifiee',
    note_tuteur INT,
    note_tutore INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ts_pair (pair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tutorat_badges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    description TEXT,
    icone VARCHAR(50),
    condition_json JSON,
    xp_reward INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tutorat_eleve_badges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eleve_id INT NOT NULL,
    badge_id INT NOT NULL,
    date_obtention DATETIME DEFAULT CURRENT_TIMESTAMP,
    annee_scolaire VARCHAR(10),
    UNIQUE KEY uk_teb (eleve_id, badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tutorat_demandes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    matiere_id INT,
    description TEXT,
    urgence ENUM('basse','moyenne','haute') DEFAULT 'moyenne',
    statut ENUM('ouverte','matchee','fermee') DEFAULT 'ouverte',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_td_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 6 : Intelligence / Analyse Predictive
-- ============================================================

CREATE TABLE IF NOT EXISTS intelligence_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    annee_scolaire VARCHAR(10),
    periode_id INT,
    score_risque DECIMAL(5,2) DEFAULT 0,
    score_absences DECIMAL(5,2) DEFAULT 0,
    score_notes DECIMAL(5,2) DEFAULT 0,
    score_discipline DECIMAL(5,2) DEFAULT 0,
    score_engagement DECIMAL(5,2) DEFAULT 0,
    niveau_alerte ENUM('vert','jaune','orange','rouge') DEFAULT 'vert',
    facteurs_json JSON,
    recommandations JSON,
    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_is_eleve_period (eleve_id, annee_scolaire, periode_id),
    INDEX idx_is_alerte (niveau_alerte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intelligence_alertes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    type_alerte VARCHAR(50) NOT NULL,
    message TEXT,
    score_declencheur DECIMAL(5,2),
    destinataire_id INT,
    destinataire_type VARCHAR(30),
    lu TINYINT(1) DEFAULT 0,
    action_prise TEXT,
    date_alerte DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ia_dest (destinataire_id, destinataire_type, lu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intelligence_cohortes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    type ENUM('classe','niveau','etablissement') NOT NULL,
    reference_id INT,
    annee_scolaire VARCHAR(10),
    periode_id INT,
    moyenne_generale DECIMAL(5,2),
    taux_absenteisme DECIMAL(5,2),
    nb_incidents INT DEFAULT 0,
    nb_eleves_risque INT DEFAULT 0,
    date_calcul DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ic_type (type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intelligence_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL UNIQUE,
    poids_absences DECIMAL(3,2) DEFAULT 0.30,
    poids_notes DECIMAL(3,2) DEFAULT 0.35,
    poids_discipline DECIMAL(3,2) DEFAULT 0.20,
    poids_engagement DECIMAL(3,2) DEFAULT 0.15,
    seuil_jaune DECIMAL(5,2) DEFAULT 40,
    seuil_orange DECIMAL(5,2) DEFAULT 60,
    seuil_rouge DECIMAL(5,2) DEFAULT 80,
    calcul_automatique TINYINT(1) DEFAULT 0,
    frequence_calcul ENUM('quotidien','hebdomadaire') DEFAULT 'hebdomadaire'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 7 : Securite & Plans d'Urgence
-- ============================================================

CREATE TABLE IF NOT EXISTS securite_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    type ENUM('ppms_attentat','ppms_risques','evacuation','confinement') NOT NULL,
    titre VARCHAR(200) NOT NULL,
    contenu TEXT,
    version INT DEFAULT 1,
    fichier_path VARCHAR(255),
    valide_par INT,
    date_validation DATE,
    statut ENUM('brouillon','valide','obsolete') DEFAULT 'brouillon',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp_etab (etablissement_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS securite_exercices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    plan_id INT,
    type_exercice VARCHAR(50) NOT NULL,
    date_exercice DATETIME NOT NULL,
    duree_minutes INT,
    nb_participants INT DEFAULT 0,
    nb_manquants INT DEFAULT 0,
    observations TEXT,
    points_amelioration TEXT,
    responsable_id INT,
    statut ENUM('planifie','en_cours','termine') DEFAULT 'planifie',
    temps_evacuation_secondes INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_se_date (date_exercice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS securite_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice_id INT NOT NULL,
    nom_zone VARCHAR(100) NOT NULL,
    responsable_id INT,
    statut ENUM('non_verifie','securise','probleme') DEFAULT 'non_verifie',
    heure_verification TIME,
    commentaire TEXT,
    INDEX idx_sz_exercice (exercice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS securite_incidents_registre (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    date_incident DATETIME NOT NULL,
    type_danger VARCHAR(100),
    localisation VARCHAR(200),
    description TEXT,
    mesures_prises TEXT,
    signale_par_id INT,
    signale_par_type VARCHAR(30),
    statut ENUM('ouvert','traite','clos') DEFAULT 'ouvert',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sir_date (date_incident)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS securite_contacts_urgence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    fonction VARCHAR(100),
    telephone VARCHAR(20),
    email VARCHAR(150),
    ordre_appel INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS securite_vigipirate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    niveau ENUM('vigilance','renforce','urgence_attentat') NOT NULL,
    date_debut DATETIME DEFAULT CURRENT_TIMESTAMP,
    mesures_actives JSON,
    commentaire TEXT,
    defini_par INT,
    INDEX idx_sv_etab (etablissement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 8 : Accessibilite & Inclusion
-- ============================================================

CREATE TABLE IF NOT EXISTS accessibilite_amenagements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    plan_id INT,
    type_amenagement VARCHAR(100) NOT NULL,
    description TEXT,
    matiere_id INT,
    applicable_examens TINYINT(1) DEFAULT 0,
    date_debut DATE,
    date_fin DATE,
    statut ENUM('actif','suspendu','expire') DEFAULT 'actif',
    notifie_professeurs TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aa_eleve (eleve_id),
    INDEX idx_aa_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accessibilite_aesh (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    telephone VARCHAR(20),
    type_contrat ENUM('individuel','mutualise','collectif') DEFAULT 'mutualise',
    heures_hebdo DECIMAL(5,2),
    date_debut_contrat DATE,
    date_fin_contrat DATE,
    statut ENUM('actif','suspendu','termine') DEFAULT 'actif',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accessibilite_aesh_affectations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aesh_id INT NOT NULL,
    eleve_id INT NOT NULL,
    heures_hebdo DECIMAL(5,2),
    jours JSON,
    horaires JSON,
    date_debut DATE,
    date_fin DATE,
    statut ENUM('actif','suspendu','termine') DEFAULT 'actif',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aaa_aesh (aesh_id),
    INDEX idx_aaa_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accessibilite_mdph (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eleve_id INT NOT NULL,
    numero_dossier VARCHAR(50),
    date_notification DATE,
    date_expiration DATE,
    type_decision VARCHAR(100),
    heures_accompagnement DECIMAL(5,2),
    document_path VARCHAR(255),
    statut ENUM('valide','expire','en_renouvellement') DEFAULT 'valide',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_am_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accessibilite_ess (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    date_reunion DATE NOT NULL,
    participants JSON,
    compte_rendu TEXT,
    decisions TEXT,
    prochaine_ess DATE,
    document_path VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ae_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accessibilite_audit_numerique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    module_key VARCHAR(50),
    critere VARCHAR(100),
    conforme TINYINT(1) DEFAULT 0,
    commentaire TEXT,
    date_audit DATE,
    auditeur_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 9 : Formation Continue
-- ============================================================

CREATE TABLE IF NOT EXISTS formations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    categorie VARCHAR(100),
    type ENUM('interne','externe','en_ligne','conference') DEFAULT 'interne',
    date_debut DATETIME,
    date_fin DATETIME,
    duree_heures DECIMAL(5,1),
    lieu VARCHAR(200),
    formateur VARCHAR(200),
    places_max INT,
    cout_unitaire DECIMAL(10,2) DEFAULT 0,
    statut ENUM('planifiee','ouverte','en_cours','terminee','annulee') DEFAULT 'planifiee',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_f_dates (date_debut, date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS formation_inscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    formation_id INT NOT NULL,
    personnel_id INT NOT NULL,
    personnel_type VARCHAR(30) NOT NULL,
    statut ENUM('demandee','validee','refusee','annulee','realisee') DEFAULT 'demandee',
    commentaire TEXT,
    validee_par INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fi (formation_id, personnel_id, personnel_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS formation_certifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    personnel_id INT NOT NULL,
    personnel_type VARCHAR(30) NOT NULL,
    intitule VARCHAR(200) NOT NULL,
    organisme VARCHAR(200),
    date_obtention DATE,
    date_expiration DATE,
    fichier_path VARCHAR(255),
    statut ENUM('valide','expire','en_renouvellement') DEFAULT 'valide',
    INDEX idx_fc_expiration (date_expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS formation_budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    annee_scolaire VARCHAR(10),
    departement VARCHAR(100),
    budget_alloue DECIMAL(10,2) DEFAULT 0,
    budget_consomme DECIMAL(10,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS formation_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inscription_id INT NOT NULL,
    note_globale INT,
    commentaire TEXT,
    utilite ENUM('tres_utile','utile','peu_utile','inutile'),
    recommandation TINYINT(1),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS formation_plan_annuel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    annee_scolaire VARCHAR(10),
    personnel_id INT NOT NULL,
    personnel_type VARCHAR(30) NOT NULL,
    objectifs TEXT,
    formations_prevues JSON,
    statut ENUM('brouillon','valide','en_cours','termine') DEFAULT 'brouillon',
    valide_par INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 10 : Bourses & Aides Financieres
-- ============================================================

CREATE TABLE IF NOT EXISTS bourses_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(200) NOT NULL,
    description TEXT,
    montant_annuel DECIMAL(10,2),
    echelons JSON,
    criteres TEXT,
    annee_scolaire VARCHAR(10),
    actif TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bourses_demandes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    parent_id INT NOT NULL,
    type_bourse_id INT NOT NULL,
    annee_scolaire VARCHAR(10),
    revenu_fiscal DECIMAL(12,2),
    nb_parts DECIMAL(5,2),
    nb_enfants INT,
    echelon_calcule INT,
    montant_calcule DECIMAL(10,2),
    statut ENUM('brouillon','soumise','en_instruction','accordee','refusee','versee') DEFAULT 'brouillon',
    commentaire_admin TEXT,
    documents JSON,
    date_soumission DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bd_eleve (eleve_id),
    INDEX idx_bd_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bourses_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    demande_id INT NOT NULL,
    type_document VARCHAR(50),
    fichier_nom VARCHAR(200),
    fichier_chemin VARCHAR(255),
    valide TINYINT(1),
    commentaire TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bdoc_demande (demande_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bourses_versements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    demande_id INT NOT NULL,
    montant DECIMAL(10,2) NOT NULL,
    date_versement DATE,
    mode_paiement VARCHAR(50),
    reference_comptable VARCHAR(50),
    statut ENUM('planifie','verse','annule') DEFAULT 'planifie',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bv_demande (demande_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fonds_sociaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    eleve_id INT NOT NULL,
    parent_id INT,
    type_aide VARCHAR(100),
    montant_demande DECIMAL(10,2),
    montant_accorde DECIMAL(10,2),
    motif TEXT,
    statut ENUM('demandee','en_commission','accordee','refusee','versee') DEFAULT 'demandee',
    commission_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fs_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 11 : Inventaire & Patrimoine IT
-- ============================================================

CREATE TABLE IF NOT EXISTS inventaire_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(200) NOT NULL,
    categorie ENUM('ordinateur','tablette','videoprojecteur','imprimante','reseau','audiovisuel','mobilier','autre') DEFAULT 'autre',
    marque VARCHAR(100),
    modele VARCHAR(100),
    numero_serie VARCHAR(100),
    code_inventaire VARCHAR(50),
    qr_code_token VARCHAR(64),
    salle_id INT,
    date_acquisition DATE,
    valeur_achat DECIMAL(10,2),
    duree_amortissement INT DEFAULT 5,
    fournisseur VARCHAR(200),
    garantie_fin DATE,
    etat ENUM('neuf','bon','usage','en_panne','reforme','sorti') DEFAULT 'neuf',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ia_code (code_inventaire),
    INDEX idx_ia_categorie (categorie),
    INDEX idx_ia_etat (etat),
    INDEX idx_ia_salle (salle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaire_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    type ENUM('preventive','corrective','mise_a_jour') DEFAULT 'corrective',
    description TEXT,
    date_planifiee DATE,
    date_realisee DATE,
    technicien VARCHAR(200),
    cout DECIMAL(10,2),
    statut ENUM('planifiee','en_cours','realisee','annulee') DEFAULT 'planifiee',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_im_asset (asset_id),
    INDEX idx_im_date (date_planifiee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaire_prets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    emprunteur_id INT NOT NULL,
    emprunteur_type VARCHAR(30) NOT NULL,
    date_pret DATE NOT NULL,
    date_retour_prevue DATE,
    date_retour_effective DATE,
    etat_depart VARCHAR(50),
    etat_retour VARCHAR(50),
    statut ENUM('en_cours','retourne','en_retard','perdu') DEFAULT 'en_cours',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_asset (asset_id),
    INDEX idx_ip_emprunteur (emprunteur_id, emprunteur_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaire_incidents_tech (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    signale_par_id INT,
    signale_par_type VARCHAR(30),
    description TEXT,
    gravite ENUM('basse','moyenne','haute','critique') DEFAULT 'moyenne',
    statut ENUM('ouvert','en_cours','resolu','clos') DEFAULT 'ouvert',
    resolu_par VARCHAR(200),
    date_resolution DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iit_asset (asset_id),
    INDEX idx_iit_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaire_amortissements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    annee INT NOT NULL,
    valeur_debut_annee DECIMAL(10,2),
    dotation DECIMAL(10,2),
    valeur_fin_annee DECIMAL(10,2),
    methode ENUM('lineaire','degressif') DEFAULT 'lineaire',
    UNIQUE KEY uk_iam (asset_id, annee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 12 : Echanges & Mobilite Internationale
-- ============================================================

CREATE TABLE IF NOT EXISTS echanges_programmes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    pays_partenaire VARCHAR(100),
    ecole_partenaire VARCHAR(200),
    type ENUM('echange','sejour_linguistique','erasmus','etwinning','correspondance') DEFAULT 'echange',
    date_debut DATE,
    date_fin DATE,
    places INT,
    budget DECIMAL(10,2),
    statut ENUM('planifie','ouvert','en_cours','termine','annule') DEFAULT 'planifie',
    responsable_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS echanges_candidatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    programme_id INT NOT NULL,
    eleve_id INT NOT NULL,
    lettre_motivation TEXT,
    niveau_langue VARCHAR(10),
    documents JSON,
    statut ENUM('soumise','acceptee','liste_attente','refusee') DEFAULT 'soumise',
    commentaire TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ec (programme_id, eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS echanges_familles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    parent_id INT,
    capacite_accueil INT DEFAULT 1,
    langues_parlees VARCHAR(200),
    animaux TINYINT(1) DEFAULT 0,
    observations TEXT,
    vetee TINYINT(1) DEFAULT 0,
    statut ENUM('disponible','occupee','indisponible') DEFAULT 'disponible'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS echanges_hebergements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidature_id INT NOT NULL,
    famille_id INT,
    date_arrivee DATE,
    date_depart DATE,
    statut ENUM('planifie','en_cours','termine') DEFAULT 'planifie',
    evaluation_note INT,
    evaluation_commentaire TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS echanges_partenariats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    ecole_partenaire VARCHAR(200) NOT NULL,
    pays VARCHAR(100),
    ville VARCHAR(100),
    contact_nom VARCHAR(200),
    contact_email VARCHAR(150),
    type_accord VARCHAR(100),
    date_signature DATE,
    date_expiration DATE,
    document_path VARCHAR(255),
    statut ENUM('actif','expire','en_renouvellement') DEFAULT 'actif',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS echanges_suivi_linguistique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eleve_id INT NOT NULL,
    langue VARCHAR(20) NOT NULL,
    niveau_avant VARCHAR(5),
    niveau_apres VARCHAR(5),
    programme_id INT,
    date_evaluation DATE,
    evaluateur_id INT,
    certificat_path VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_esl_eleve (eleve_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Module 13 : Mediatheque Numerique
-- ============================================================

CREATE TABLE IF NOT EXISTS mediatheque_contenus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    type ENUM('video','audio','document','interactif','lien') NOT NULL,
    fichier_path VARCHAR(255),
    url_externe VARCHAR(500),
    duree_secondes INT,
    taille_octets BIGINT,
    miniature_path VARCHAR(255),
    matiere_id INT,
    niveau VARCHAR(30),
    tags JSON,
    auteur_id INT NOT NULL,
    auteur_type VARCHAR(30) NOT NULL,
    visibilite ENUM('public','classe','prive') DEFAULT 'public',
    statut ENUM('brouillon','publie','archive') DEFAULT 'brouillon',
    nb_vues INT DEFAULT 0,
    note_moyenne DECIMAL(3,1),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mc_matiere (matiere_id),
    INDEX idx_mc_auteur (auteur_id, auteur_type),
    INDEX idx_mc_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mediatheque_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT,
    auteur_id INT NOT NULL,
    auteur_type VARCHAR(30) NOT NULL,
    matiere_id INT,
    niveau VARCHAR(30),
    visibilite ENUM('public','classe','prive') DEFAULT 'public',
    ordre_contenus JSON,
    statut ENUM('brouillon','publiee','archivee') DEFAULT 'brouillon',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mediatheque_vues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenu_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    duree_visionnee INT DEFAULT 0,
    pourcentage_complete DECIMAL(5,2) DEFAULT 0,
    date_visionnage DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_position INT DEFAULT 0,
    INDEX idx_mv_contenu (contenu_id),
    INDEX idx_mv_user (user_id, user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mediatheque_notes_avis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenu_id INT NOT NULL,
    user_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    note INT,
    commentaire TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mna (contenu_id, user_id, user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mediatheque_favoris (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    contenu_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mf (user_id, user_type, contenu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mediatheque_quotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    espace_utilise BIGINT DEFAULT 0,
    espace_max BIGINT DEFAULT 1073741824,
    UNIQUE KEY uk_mq (user_id, user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- v2.0.0 — Performance Indexes
-- ============================================================

-- NB : l'index sur `notes` est créé par le module notes (modules/notes/Database/install.sql,
-- index idx_eleve_matiere_trimestre). La table `notes` n'existe PAS lors de l'import du socle,
-- donc l'index ne doit pas être déclaré ici (provoquait une erreur 1146 « table inexistante »).
CREATE INDEX idx_notif_user_lu_date ON notifications_globales(user_id, user_type, lu, date_creation);

-- ============================================================
-- v2.0.0 — Extensions table etablissements
-- ============================================================

ALTER TABLE etablissements
    ADD COLUMN slogan VARCHAR(255) AFTER nom,
    ADD COLUMN charte_couleurs JSON AFTER slogan,
    ADD COLUMN entete_pdf_html TEXT AFTER charte_couleurs,
    ADD COLUMN pied_page_pdf_html TEXT AFTER entete_pdf_html;

-- ============================================================
-- v2.0.0 — Feature Flags pour les 13 nouveaux modules
-- ============================================================

INSERT INTO feature_flags (flag_key, label, description, establishment_types, enabled, config) VALUES
('evaluations.enabled',         'Evaluations en ligne',       'QCM et evaluations numeriques',        NULL, 1, NULL),
('evaluations.anti_triche',     'Anti-triche evaluations',    'Mode plein ecran et surveillance',     NULL, 1, NULL),
('evaluations.import_gift',     'Import GIFT',                'Import banques au format Moodle GIFT', NULL, 1, NULL),
('conseil_classe.enabled',      'Conseils de classe',         'Conseils de classe numeriques',        NULL, 1, NULL),
('conseil_classe.vote_electronique','Vote electronique',      'Vote des avis en conseil',             NULL, 1, NULL),
('conseil_classe.delegue_acces','Acces delegues',             'Delegues parents en lecture seule',    NULL, 1, NULL),
('portail_parents.enabled',     'Portail Parents',            'Portail parents avance',               NULL, 1, NULL),
('portail_parents.sortie_anticipee','Sortie anticipee',       'Autorisations sortie par QR',          NULL, 1, NULL),
('portail_parents.e_signature', 'E-signature parents',        'Signature electronique documents',     NULL, 1, NULL),
('enquetes.enabled',            'Enquetes & Satisfaction',    'Enquetes et climat scolaire',          NULL, 1, NULL),
('enquetes.climat_scolaire',    'Barometre climat',           'Questionnaire standardise climat',     NULL, 1, NULL),
('enquetes.anonyme',            'Enquetes anonymes',          'Mode anonyme cryptographique',         NULL, 1, NULL),
('tutorat.enabled',             'Tutorat & Entraide',         'Systeme de tutorat entre pairs',       NULL, 1, NULL),
('tutorat.gamification',        'Gamification tutorat',       'Badges, XP et leaderboard',            NULL, 1, NULL),
('tutorat.auto_matching',       'Auto-matching tutorat',      'Algorithme de matching automatique',   NULL, 1, NULL),
('intelligence.enabled',        'Analyse Predictive',         'Detection decrochage scolaire',        NULL, 0, NULL),
('intelligence.alertes_auto',   'Alertes auto IA',            'Notifications automatiques risque',    NULL, 0, NULL),
('intelligence.calcul_quotidien','Calcul quotidien IA',       'Recalcul journalier des scores',       NULL, 0, NULL),
('securite.enabled',            'Securite & Urgence',         'PPMS et plans d urgence',              NULL, 1, NULL),
('securite.vigipirate',         'Niveaux Vigipirate',         'Gestion niveaux Vigipirate',           NULL, 1, NULL),
('securite.push_urgence',       'Push urgence',               'Alertes push en cas d urgence',        NULL, 1, NULL),
('accessibilite.enabled',       'Accessibilite & Inclusion',  'Amenagements et AESH',                 NULL, 1, NULL),
('accessibilite.aesh',          'Gestion AESH',               'Affectations et planning AESH',        NULL, 1, NULL),
('accessibilite.audit_rgaa',    'Audit RGAA',                 'Audit accessibilite numerique',        NULL, 1, NULL),
('formations.enabled',          'Formation Continue',         'Catalogue et suivi formations',        NULL, 1, NULL),
('formations.budget_tracking',  'Budget formations',          'Suivi budgetaire formations',          NULL, 1, NULL),
('bourses.enabled',             'Bourses & Aides',            'Gestion bourses et fonds sociaux', '["college","lycee"]', 1, NULL),
('bourses.simulateur',          'Simulateur bourses',         'Simulateur eligibilite bourses',   '["college","lycee"]', 1, NULL),
('bourses.fonds_sociaux',       'Fonds sociaux',              'Gestion du fonds social',              NULL, 1, NULL),
('inventaire.enabled',          'Inventaire IT',              'Gestion parc informatique',            NULL, 1, NULL),
('inventaire.amortissement',    'Amortissement',              'Calcul amortissement comptable',       NULL, 1, NULL),
('inventaire.qr_code',          'QR Code assets',             'QR codes sur equipements',             NULL, 1, NULL),
('echanges.enabled',            'Echanges internationaux',    'Mobilite et echanges scolaires', '["lycee","superieur"]', 1, NULL),
('echanges.erasmus',            'Erasmus+',                   'Projets Erasmus+ et eTwinning',  '["lycee","superieur"]', 1, NULL),
('mediatheque.enabled',         'Mediatheque Numerique',      'Contenus pedagogiques numeriques',     NULL, 1, NULL),
('mediatheque.video_upload',    'Upload video',               'Telechargement de videos',             NULL, 1, NULL),
('mediatheque.tracking',        'Suivi visionnage',           'Suivi de consultation des contenus',   NULL, 1, NULL)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ============================================================
SET SESSION FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
