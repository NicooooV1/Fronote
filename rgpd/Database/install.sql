-- =====================================================================
-- Module RGPD — schéma des tables du tableau de bord conformité.
-- Provisionné par ModuleSDK::provisionSql() (convention Database/install.sql).
-- DDL portable MySQL 8 / MariaDB ; idempotent (CREATE TABLE IF NOT EXISTS).
--
-- Ces tables alimentent AuditRgpdService :
--   - rgpd_registre_traitements  (Art. 30 — registre des traitements)
--   - rgpd_analyses_impact       (AIPD / DPIA)
--   - rgpd_violations            (Art. 33/34 — violations de données)
--   - rgpd_retention_policies    (politiques de rétention / purge)
-- (rgpd_consentements et rgpd_demandes sont déjà créées dans le socle.)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `rgpd_registre_traitements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nom_traitement` VARCHAR(255) NOT NULL,
  `finalite` TEXT NOT NULL,
  `base_legale` VARCHAR(100) NOT NULL DEFAULT 'consentement',
  `categories_donnees` TEXT DEFAULT NULL,
  `destinataires` TEXT DEFAULT NULL,
  `duree_conservation` VARCHAR(255) DEFAULT NULL,
  `mesures_securite` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nom_traitement` (`nom_traitement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rgpd_analyses_impact` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `titre` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `cree_par` INT(11) DEFAULT NULL,
  `statut` ENUM('en_cours','validee','archivee') NOT NULL DEFAULT 'en_cours',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rgpd_violations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `titre` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `date_detection` DATE DEFAULT NULL,
  `nature` VARCHAR(255) DEFAULT NULL,
  `donnees_concernees` TEXT DEFAULT NULL,
  `nb_personnes` INT(11) NOT NULL DEFAULT 0,
  `gravite` ENUM('faible','moyenne','elevee','critique') NOT NULL DEFAULT 'moyenne',
  `signale_par` INT(11) DEFAULT NULL,
  `statut` ENUM('detectee','en_cours','resolue') NOT NULL DEFAULT 'detectee',
  `actions_correctives` TEXT DEFAULT NULL,
  `date_resolution` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_date_detection` (`date_detection`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rgpd_retention_policies` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `table_name` VARCHAR(100) NOT NULL,
  `retention_days` INT(11) NOT NULL DEFAULT 365,
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `derniere_purge` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table_name` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Scoping multi-établissement (cloisonnement RGPD).
-- Ajout de la colonne `etablissement_id` aux tables RGPD qui en sont
-- dépourvues, afin de cloisonner audit/journal/registre/violations par
-- établissement (sauf super_admin). Les ALTER sont idempotents : le
-- provisionneur (ModuleSDK::execSchemaSql) ignore l'erreur 1060
-- « duplicate column », l'exécution répétée est donc sans effet.
-- NB : audit_log et rgpd_demandes appartiennent au socle ; la colonne y
-- est ajoutée ici par sécurité si le socle déployé ne l'a pas encore.
-- =====================================================================

-- ADD COLUMN et ADD KEY séparés : un échec 1060 (colonne déjà présente) n'empêche plus la
-- création de l'index, et 1061 (index déjà présent) est désormais ignoré par execSchemaSql.
ALTER TABLE `audit_log` ADD COLUMN `etablissement_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `audit_log` ADD KEY `idx_etablissement` (`etablissement_id`);

ALTER TABLE `rgpd_demandes` ADD COLUMN `etablissement_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `rgpd_demandes` ADD KEY `idx_etablissement` (`etablissement_id`);

ALTER TABLE `rgpd_registre_traitements` ADD COLUMN `etablissement_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `rgpd_registre_traitements` ADD KEY `idx_etablissement` (`etablissement_id`);

ALTER TABLE `rgpd_analyses_impact` ADD COLUMN `etablissement_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `rgpd_analyses_impact` ADD KEY `idx_etablissement` (`etablissement_id`);

ALTER TABLE `rgpd_violations` ADD COLUMN `etablissement_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `rgpd_violations` ADD KEY `idx_etablissement` (`etablissement_id`);
