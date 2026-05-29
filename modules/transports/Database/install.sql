-- Module transports — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `lignes_transport` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(255) NOT NULL,
  `type` enum('bus','navette','train','autre') NOT NULL DEFAULT 'bus',
  `itineraire` text DEFAULT NULL,
  `horaire_depart` time DEFAULT NULL,
  `horaire_arrivee` time DEFAULT NULL,
  `capacite` int(11) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_lignes_transp_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inscriptions_transport` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `ligne_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `arret` varchar(255) DEFAULT NULL,
  `annee_scolaire` varchar(9) NOT NULL,
  `statut` enum('inscrit','annule') NOT NULL DEFAULT 'inscrit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ligne_eleve` (`ligne_id`, `eleve_id`, `annee_scolaire`),
  CONSTRAINT `fk_inscrtrans_ligne` FOREIGN KEY (`ligne_id`) REFERENCES `lignes_transport` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_insc_transp_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
