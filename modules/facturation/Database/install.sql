-- Module facturation — schema SQL (genere depuis pronote.sql, ordre FK preserve)
-- Idempotent (IF NOT EXISTS). Injecte a l-activation (ModuleSDK::provisionSql).
-- Encore present dans pronote.sql (filet) jusqu-a validation install neuve.

CREATE TABLE IF NOT EXISTS `factures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `numero` varchar(50) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `eleve_id` int(11) DEFAULT NULL,
  `intitule` varchar(255) NOT NULL,
  `montant_ht` decimal(10,2) NOT NULL,
  `tva` decimal(5,2) NOT NULL DEFAULT 0.00,
  `montant_ttc` decimal(10,2) NOT NULL,
  `date_emission` date NOT NULL,
  `date_echeance` date NOT NULL,
  `statut` enum('brouillon','emise','payee','en_retard','annulee','en_attente','partielle') NOT NULL DEFAULT 'brouillon',
  `type` enum('cantine','periscolaire','inscription','autre','scolarite','transport','activite','garderie') NOT NULL DEFAULT 'autre',
  `notes` text DEFAULT NULL,
  `rappel_envoye` tinyint(1) NOT NULL DEFAULT 0,
  `rappel_date` datetime DEFAULT NULL,
  `relance_count` int(11) NOT NULL DEFAULT 0,
  `derniere_relance` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_factures_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facture_lignes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `facture_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantite` int(11) NOT NULL DEFAULT 1,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_factligne_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paiements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `facture_id` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `mode` enum('cheque','virement','especes','cb','prelevement','carte') NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_paiement_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_paiements_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avoirs / notes de crédit (FacturationService::creerAvoir / getAvoirs).
-- etablissement_id : DEFAULT 1 car le service n'insère pas la colonne (cloisonnement via facture_id).
CREATE TABLE IF NOT EXISTS `avoirs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `facture_id` int(11) NOT NULL,
  `numero` varchar(50) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `motif` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `idx_facture` (`facture_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_avoir_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_avoirs_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Échéancier de paiement en plusieurs fois (FacturationService::creerEcheancier / getEcheancier).
-- etablissement_id : DEFAULT 1 car le service n'insère pas la colonne (cloisonnement via facture_id).
CREATE TABLE IF NOT EXISTS `facture_echeancier` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etablissement_id` int(11) NOT NULL DEFAULT 1,
  `facture_id` int(11) NOT NULL,
  `numero_echeance` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `date_echeance` date NOT NULL,
  `statut` enum('en_attente','paye','annule') NOT NULL DEFAULT 'en_attente',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_facture` (`facture_id`),
  KEY `idx_etab` (`etablissement_id`),
  CONSTRAINT `fk_echeancier_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_echeancier_etab` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
