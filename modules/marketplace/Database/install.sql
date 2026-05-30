-- Module marketplace — schéma local (idempotent).
-- Provisionné par ModuleSDK::provisionSql() à l'installation et à chaque activation.
-- Stocke uniquement la provenance et le cache : aucune donnée nominative.

CREATE TABLE IF NOT EXISTS `marketplace_sources` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `url` VARCHAR(255) NOT NULL COMMENT 'Base URL du registre (vide = sideload local seulement)',
  `root_public_key` VARBINARY(64) NOT NULL COMMENT 'Clé publique Ed25519 racine attendue (32 bytes)',
  `default_channel` ENUM('stable','beta') NOT NULL DEFAULT 'stable',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_installed` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(64) NOT NULL,
  `version` VARCHAR(32) NOT NULL,
  `publisher_id` VARCHAR(64) NOT NULL,
  `source_id` INT(11) DEFAULT NULL,
  `channel` ENUM('stable','beta','sideload') NOT NULL DEFAULT 'sideload',
  `package_sha256` CHAR(64) NOT NULL COMMENT 'SHA-256 du paquet .fmod',
  `manifest_sha256` CHAR(64) NOT NULL COMMENT 'SHA-256 du MANIFEST.sha256 signé',
  `cert_fingerprint` CHAR(64) NOT NULL COMMENT 'SHA-256 du certificat éditeur',
  `signature_verified_at` DATETIME NOT NULL,
  `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_version` (`module_key`, `version`),
  KEY `idx_publisher` (`publisher_id`),
  KEY `idx_source` (`source_id`),
  CONSTRAINT `fk_mpi_source` FOREIGN KEY (`source_id`) REFERENCES `marketplace_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_cache` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `source_id` INT(11) NOT NULL,
  `cache_key` VARCHAR(255) NOT NULL COMMENT 'ex: catalog, module:<key>, crl',
  `payload` LONGTEXT NOT NULL,
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_key` (`source_id`, `cache_key`),
  CONSTRAINT `fk_mpc_source` FOREIGN KEY (`source_id`) REFERENCES `marketplace_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_consents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(64) NOT NULL,
  `version` VARCHAR(32) NOT NULL,
  `permissions_granted` JSON NOT NULL,
  `granted_by` INT(11) DEFAULT NULL COMMENT 'administrateurs.id (NULL si admin supprimé après consentement)',
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_version_consent` (`module_key`, `version`),
  KEY `idx_module` (`module_key`),
  KEY `idx_granted_by` (`granted_by`),
  CONSTRAINT `fk_mpcon_admin` FOREIGN KEY (`granted_by`)
      REFERENCES `administrateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_advisories_seen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(64) NOT NULL,
  `affected_range` VARCHAR(64) NOT NULL,
  `fixed_in` VARCHAR(32) DEFAULT NULL,
  `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `summary` VARCHAR(255) NOT NULL,
  `advisory_url` VARCHAR(255) DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module_severity` (`module_key`, `severity`),
  KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketplace_revocations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `source_id` INT(11) DEFAULT NULL,
  `cert_fingerprint` CHAR(64) NOT NULL COMMENT 'SHA-256 du certificat révoqué',
  `revoked_at` DATETIME NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `crl_signed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_fp` (`source_id`, `cert_fingerprint`),
  CONSTRAINT `fk_mpr_source` FOREIGN KEY (`source_id`) REFERENCES `marketplace_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
