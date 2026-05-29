-- Module inventaire — schéma SQL (assets, maintenance, prêts, incidents tech, amortissements).
-- Idempotent (IF NOT EXISTS). Injecté à l'activation (ModuleSDK::provisionSql).
-- FK vers core (etablissements, salles). responsable_id/signale_par NON FK (multi-type).

CREATE TABLE IF NOT EXISTS `inventaire_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `nom` varchar(200) NOT NULL,
  `type_asset` varchar(60) DEFAULT NULL,
  `numero_serie` varchar(120) DEFAULT NULL,
  `marque` varchar(120) DEFAULT NULL,
  `modele` varchar(120) DEFAULT NULL,
  `prix_achat` decimal(10,2) NOT NULL DEFAULT 0,
  `date_achat` date DEFAULT NULL,
  `salle_id` int(11) DEFAULT NULL,
  `fournisseur` varchar(200) DEFAULT NULL,
  `qr_token` varchar(60) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'actif',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_etab` (`etablissement_id`),
  KEY `idx_inv_asset_type` (`type_asset`),
  KEY `idx_inv_asset_salle` (`salle_id`),
  CONSTRAINT `fk_inv_asset_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_asset_salle` FOREIGN KEY (`salle_id`) REFERENCES `salles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventaire_maintenance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `type_maintenance` varchar(60) DEFAULT NULL,
  `date_prevue` date DEFAULT NULL,
  `date_realisation` datetime DEFAULT NULL,
  `description` text DEFAULT NULL,
  `rapport` text DEFAULT NULL,
  `cout` decimal(10,2) NOT NULL DEFAULT 0,
  `responsable_id` int(11) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'planifiee',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_maint_asset` (`asset_id`),
  CONSTRAINT `fk_inv_maint_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventaire_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventaire_prets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `emprunteur_id` int(11) NOT NULL,
  `emprunteur_type` varchar(30) DEFAULT NULL,
  `date_pret` datetime DEFAULT NULL,
  `date_retour_prevue` date DEFAULT NULL,
  `date_retour_effectif` datetime DEFAULT NULL,
  `motif` text DEFAULT NULL,
  `etat_retour` varchar(20) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'en_cours',
  PRIMARY KEY (`id`),
  KEY `idx_inv_pret_asset` (`asset_id`),
  CONSTRAINT `fk_inv_pret_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventaire_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventaire_incidents_tech` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `signale_par` int(11) DEFAULT NULL,
  `signale_par_type` varchar(30) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `urgence` varchar(20) NOT NULL DEFAULT 'normal',
  `statut` varchar(20) NOT NULL DEFAULT 'ouvert',
  `resolution` text DEFAULT NULL,
  `cout_reparation` decimal(10,2) NOT NULL DEFAULT 0,
  `date_resolution` datetime DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_inc_asset` (`asset_id`),
  CONSTRAINT `fk_inv_inc_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventaire_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventaire_amortissements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `methode` varchar(20) NOT NULL DEFAULT 'lineaire',
  `duree_annees` int(11) NOT NULL DEFAULT 5,
  `amortissement_cumule` decimal(12,2) NOT NULL DEFAULT 0,
  `valeur_residuelle` decimal(12,2) NOT NULL DEFAULT 0,
  `date_calcul` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inv_amort_asset` (`asset_id`),
  CONSTRAINT `fk_inv_amort_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventaire_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
