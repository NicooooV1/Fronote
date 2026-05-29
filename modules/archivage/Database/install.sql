-- Module archivage — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `archives_annuelles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `annee_scolaire` varchar(10) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'bulletins, notes, absences, edt, incidents, general',
  `description` text DEFAULT NULL,
  `donnees` longtext DEFAULT NULL COMMENT 'JSON des données archivées',
  `fichier_chemin` varchar(255) DEFAULT NULL,
  `taille` bigint(20) DEFAULT 0,
  `verrouille` tinyint(1) NOT NULL DEFAULT 1,
  `archive_par` int(11) NOT NULL,
  `date_archivage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_archive_annee` (`annee_scolaire`),
  KEY `idx_archive_type` (`type`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_archives_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
