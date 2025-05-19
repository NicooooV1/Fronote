-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost
-- Généré le : lun. 19 mai 2025 à 17:23
-- Version du serveur : 10.5.27-MariaDB
-- Version de PHP : 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `db_MASSE`
--

-- --------------------------------------------------------

--
-- Structure de la table `absences`
--

CREATE TABLE `absences` (
  `id` int(11) NOT NULL,
  `id_eleve` int(11) NOT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NOT NULL,
  `type_absence` varchar(50) NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `justifie` tinyint(1) DEFAULT 0,
  `commentaire` text DEFAULT NULL,
  `signale_par` varchar(100) NOT NULL,
  `date_signalement` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `absences`
--

INSERT INTO `absences` (`id`, `id_eleve`, `date_debut`, `date_fin`, `type_absence`, `motif`, `justifie`, `commentaire`, `signale_par`, `date_signalement`, `date_modification`) VALUES
(2, 9, '2024-09-18 08:00:00', '2025-05-18 09:00:00', 'journee', 'transport', 0, 'il ment', 'Loic Viande', '2025-05-18 19:44:07', '2025-05-18 19:44:07');

-- --------------------------------------------------------

--
-- Structure de la table `administrateurs`
--

CREATE TABLE `administrateurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `administrateurs`
--

INSERT INTO `administrateurs` (`id`, `nom`, `prenom`, `mail`, `telephone`, `identifiant`, `mot_de_passe`, `date_creation`) VALUES
(11, 'MASSÉ', 'Nicolas', 'masse.nicolas77@gmail.com', '07 82 11 71 82', 'masse.nicolas', '$2y$12$UZvEK2FnNsCntFt.8LmDreb7lzyoJMIad6/CTQI1jjGaOWLBfAd6y', '2025-05-07 13:13:46'),
(13, 'Viande', 'Loic', 'loic2viande@gmail.com', '06 66 66 66 66', 'viande.loic01', '$2y$12$DH/usDgbRqVD8sfYni5JdO.rUG.8itMio6YvXAYjqjaP7b7zJBFiq', '2025-05-14 17:46:17');

-- --------------------------------------------------------

--
-- Structure de la table `cahier_texte`
--

CREATE TABLE `cahier_texte` (
  `id` int(11) NOT NULL,
  `id_professeur` int(11) NOT NULL,
  `matiere` varchar(100) NOT NULL,
  `classe` varchar(50) NOT NULL,
  `date_cours` date NOT NULL,
  `contenu` text NOT NULL,
  `documents` text DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `derniere_modification` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `conversations`
--

CREATE TABLE `conversations` (
  `id` int(11) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `conversations`
--

INSERT INTO `conversations` (`id`, `subject`, `created_at`, `updated_at`) VALUES
(1, 'Salut', '2025-05-13 08:15:22', '2025-05-13 08:15:22'),
(2, 'azegrhtjyuj', '2025-05-13 08:15:34', '2025-05-13 08:15:34'),
(3, 'pàoçikujygtvrfds', '2025-05-13 08:15:43', '2025-05-13 08:44:58');

-- --------------------------------------------------------

--
-- Structure de la table `conversation_participants`
--

CREATE TABLE `conversation_participants` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_read_at` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_moderator` tinyint(1) NOT NULL DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `unread_count` int(11) NOT NULL DEFAULT 0,
  `last_read_message_id` int(11) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `conversation_participants`
--

INSERT INTO `conversation_participants` (`id`, `conversation_id`, `user_id`, `user_type`, `joined_at`, `last_read_at`, `is_deleted`, `is_admin`, `is_moderator`, `is_archived`, `unread_count`, `last_read_message_id`, `version`) VALUES
(1, 1, 8, 'eleve', '2025-05-13 08:15:22', NULL, 0, 1, 0, 0, 0, 1, 2),
(2, 1, 11, 'administrateur', '2025-05-13 08:15:22', NULL, 0, 0, 0, 0, 1, NULL, 1),
(3, 2, 8, 'eleve', '2025-05-13 08:15:34', NULL, 0, 1, 0, 0, 0, 2, 2),
(4, 2, 9, 'eleve', '2025-05-13 08:15:34', NULL, 0, 0, 0, 0, 1, NULL, 1),
(5, 2, 11, 'administrateur', '2025-05-13 08:15:34', '2025-05-19 12:14:50', 0, 0, 0, 0, 0, NULL, 1),
(6, 3, 8, 'eleve', '2025-05-13 08:15:43', '2025-05-13 08:18:32', 0, 1, 0, 0, 0, 5, 4),
(7, 3, 9, 'eleve', '2025-05-13 08:15:43', NULL, 0, 0, 0, 0, 3, NULL, 1),
(8, 3, 11, 'administrateur', '2025-05-13 08:15:43', '2025-05-19 13:28:11', 0, 0, 0, 0, 0, 5, 4);

-- --------------------------------------------------------

--
-- Structure de la table `devoirs`
--

CREATE TABLE `devoirs` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `classe` varchar(50) NOT NULL,
  `nom_matiere` varchar(255) NOT NULL,
  `nom_professeur` varchar(255) NOT NULL,
  `date_ajout` date NOT NULL,
  `date_rendu` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `devoirs`
--

INSERT INTO `devoirs` (`id`, `titre`, `description`, `classe`, `nom_matiere`, `nom_professeur`, `date_ajout`, `date_rendu`) VALUES
(1, 'SAE nul', 'va faire ', '1G1', 'Philosophie', 'Christophe QUITTET', '2017-02-14', '2030-10-14');

-- --------------------------------------------------------

--
-- Structure de la table `devoirs_status`
--

CREATE TABLE `devoirs_status` (
  `id` int(11) NOT NULL,
  `id_devoir` int(11) NOT NULL,
  `id_eleve` int(11) NOT NULL,
  `status` enum('non_fait','en_cours','termine') DEFAULT 'non_fait',
  `date_derniere_modif` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `eleves`
--

CREATE TABLE `eleves` (
  `id` int(11) NOT NULL,
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
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `eleves`
--

INSERT INTO `eleves` (`id`, `nom`, `prenom`, `date_naissance`, `classe`, `lieu_naissance`, `adresse`, `mail`, `telephone`, `identifiant`, `mot_de_passe`, `date_creation`) VALUES
(9, 'Deschanel', 'Thomas', '2006-07-26', '4C', 'Montpellier', '5bis Rue du Cabernet 34500 Béziers', 'thomasdeschanel644@gmail.com', '06 02 46 48 82', 'deschanel.thomas', '$2y$12$4HiV8Pg5sI1raoPQw86CAOnTaATs4LguXI7Bub8D4yaFF0H15/ovG', '2025-05-09 22:22:34'),
(8, 'Letalle', 'Télio', '2006-11-26', 'TG1', 'Lens', '3 Rue des Félibres 34140 Loupian', 'telioletalle@gmail.com', '06 62 37 09 48', 'letalle.telio', '$2y$12$IaTVI55KHmE7v97oLp4fPuLawnki3JSqxkCDwjmQjQixma3GmInxe', '2025-05-07 14:10:56');

-- --------------------------------------------------------

--
-- Structure de la table `evenements`
--

CREATE TABLE `evenements` (
  `id` int(11) NOT NULL,
  `titre` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NOT NULL,
  `type_evenement` varchar(50) NOT NULL,
  `statut` varchar(30) DEFAULT 'actif',
  `createur` varchar(100) NOT NULL,
  `visibilite` varchar(255) NOT NULL,
  `personnes_concernees` text DEFAULT NULL,
  `lieu` varchar(100) DEFAULT NULL,
  `classes` varchar(255) DEFAULT NULL,
  `matieres` varchar(100) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `evenements`
--

INSERT INTO `evenements` (`id`, `titre`, `description`, `date_debut`, `date_fin`, `type_evenement`, `statut`, `createur`, `visibilite`, `personnes_concernees`, `lieu`, `classes`, `matieres`, `date_creation`, `date_modification`) VALUES
(1, 'Réunion ofzeon', 'Réunion pour les essaies', '2025-05-15 10:16:00', '2025-05-15 11:16:00', 'reunion', 'actif', 'Nicolas MASSÉ', 'professeurs', NULL, 'B204', '', '', '2025-05-15 08:17:11', '2025-05-15 08:17:11');

-- --------------------------------------------------------

--
-- Structure de la table `justificatifs`
--

CREATE TABLE `justificatifs` (
  `id` int(11) NOT NULL,
  `id_eleve` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `id_reference` int(11) NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `date_reception` date NOT NULL,
  `date_validite_debut` date NOT NULL,
  `date_validite_fin` date NOT NULL,
  `motif` varchar(255) NOT NULL,
  `valide` tinyint(1) DEFAULT 0,
  `valide_par` varchar(100) DEFAULT NULL,
  `date_validation` timestamp NULL DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Structure de la table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('normal','important','urgent','annonce') NOT NULL DEFAULT 'normal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `messages`
--

INSERT INTO `messages` (`id`, `conversation_id`, `sender_id`, `sender_type`, `body`, `created_at`, `updated_at`, `status`) VALUES
(1, 1, 8, 'eleve', 'qssohuofhez', '2025-05-13 08:15:22', '2025-05-13 08:15:22', 'normal'),
(2, 2, 8, 'eleve', 'qztgethrbgfvcx', '2025-05-13 08:15:34', '2025-05-13 08:15:34', 'normal'),
(3, 3, 8, 'eleve', 'ré\"\'(eètjyhgfdsx', '2025-05-13 08:15:43', '2025-05-13 08:15:43', 'normal'),
(4, 3, 11, 'administrateur', 'test', '2025-05-13 08:18:21', '2025-05-13 08:18:21', 'normal'),
(5, 3, 8, 'eleve', 'o', '2025-05-13 08:44:58', '2025-05-13 08:44:58', 'normal');

-- --------------------------------------------------------

--
-- Structure de la table `message_attachments`
--

CREATE TABLE `message_attachments` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `message_notifications`
--

CREATE TABLE `message_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `message_id` int(11) NOT NULL,
  `notification_type` enum('unread','broadcast','mention','reply','important') NOT NULL DEFAULT 'unread',
  `notified_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `message_notifications`
--

INSERT INTO `message_notifications` (`id`, `user_id`, `user_type`, `message_id`, `notification_type`, `notified_at`, `is_read`, `read_at`) VALUES
(1, 11, 'administrateur', 1, 'unread', '2025-05-13 08:15:22', 0, NULL),
(2, 9, 'eleve', 2, 'unread', '2025-05-13 08:15:34', 0, NULL),
(3, 11, 'administrateur', 2, 'unread', '2025-05-13 08:15:34', 0, NULL),
(4, 9, 'eleve', 3, 'unread', '2025-05-13 08:15:43', 0, NULL),
(5, 11, 'administrateur', 3, 'unread', '2025-05-13 08:15:43', 1, '2025-05-13 08:16:10'),
(6, 8, 'eleve', 4, 'unread', '2025-05-13 08:18:21', 1, '2025-05-13 08:18:32'),
(7, 9, 'eleve', 4, 'unread', '2025-05-13 08:18:21', 0, NULL),
(8, 9, 'eleve', 5, 'unread', '2025-05-13 08:44:58', 0, NULL),
(9, 11, 'administrateur', 5, 'unread', '2025-05-13 08:44:58', 1, '2025-05-15 20:16:47');

-- --------------------------------------------------------

--
-- Structure de la table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `nom_eleve` varchar(255) NOT NULL,
  `nom_matiere` varchar(255) NOT NULL,
  `nom_professeur` varchar(255) NOT NULL,
  `note` float NOT NULL,
  `date_ajout` date NOT NULL,
  `classe` varchar(10) DEFAULT NULL,
  `coefficient` int(11) NOT NULL DEFAULT 1,
  `description` varchar(255) DEFAULT NULL,
  `date_evaluation` date DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `matiere` varchar(100) NOT NULL,
  `trimestre` int(11) DEFAULT 1
) ;

--
-- Déchargement des données de la table `notes`
--

INSERT INTO `notes` (`id`, `nom_eleve`, `nom_matiere`, `nom_professeur`, `note`, `date_ajout`, `classe`, `coefficient`, `description`, `date_evaluation`, `date_creation`, `matiere`, `trimestre`) VALUES
(2, 'Télio', 'Musique', 'M. MASSE', 17, '2025-05-13', 'TG1', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(3, 'Télio', 'Philosophie', 'Christophe QUITTET', 13, '2025-05-13', 'TG1', 1, 'CTRL', NULL, '2025-05-18 19:07:15', '', 1),
(4, 'Télio', 'Musique', 'M. MASSE', 17, '2025-05-13', 'TG1', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(6, 'Télio', 'Numérique et Sciences Informatiques', 'telio', 20, '2025-02-13', '4A', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(10, '18h29 Test1', 'Anglais', 'Loic Viande', 1, '2025-05-14', '4A', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(8, 'Télio', 'Numérique et Sciences Informatiques', 'telio', 12, '2025-03-13', '6B', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(12, 'mickael youn', 'Mathématiques', 'Loic Viande', 17, '2025-05-14', '1G1', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(14, 'Télio', 'Philosophie', 'Christophe QUITTET', 1.3, '2025-05-14', 'TG1', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(15, 'Thomas', 'Philosophie', 'Christophe QUITTET', 12, '2025-05-14', '4C', 1, NULL, NULL, '2025-05-18 19:07:15', '', 1),
(16, 'Thomas', 'Philosophie', 'Christophe QUITTET', 12, '2025-05-19', '4C', 1, 'SDS', '2025-05-19', '2025-05-19 11:37:08', 'Philosophie', 3),
(17, 'Thomas', 'Philosophie', 'Christophe QUITTET', 20, '2025-05-19', '4C', 1, 'DS', '2025-05-19', '2025-05-19 11:48:26', 'Philosophie', 3);

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('creation','rappel','correction') NOT NULL,
  `id_devoir` int(11) NOT NULL,
  `statut` enum('en_attente','envoye','erreur') NOT NULL DEFAULT 'en_attente',
  `date_creation` datetime DEFAULT current_timestamp(),
  `date_envoi` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `parents`
--

CREATE TABLE `parents` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `metier` varchar(100) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `est_parent_eleve` enum('oui','non') NOT NULL DEFAULT 'non',
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `parents`
--

INSERT INTO `parents` (`id`, `nom`, `prenom`, `mail`, `adresse`, `telephone`, `metier`, `identifiant`, `mot_de_passe`, `est_parent_eleve`, `date_creation`) VALUES
(2, 'URTADO', 'Pierre', 'pierre.urtado@outlook.fr', '9ter Rue Ernest Renan 34500 Béziers', '07 81 83 31 34', 'Plombier', 'urtado.pierre', '$2y$12$6g3XBHyT73m9NKisDRd2B.ZZtulQflxusEQmWzUhwKlLcoA.Iaz36', 'non', '2025-05-15 10:34:17');

-- --------------------------------------------------------

--
-- Structure de la table `professeurs`
--

CREATE TABLE `professeurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `professeur_principal` varchar(50) NOT NULL DEFAULT 'non',
  `matiere` varchar(100) NOT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `professeurs`
--

INSERT INTO `professeurs` (`id`, `nom`, `prenom`, `mail`, `adresse`, `telephone`, `identifiant`, `mot_de_passe`, `professeur_principal`, `matiere`, `date_creation`) VALUES
(1, 'QUITTET', 'Christophe', 'quittet.christophe@gmail.com', '27 Avenue Paul Riquet 34110 Frontignan', '06 89 42 15 32', 'quittet.christophe', '$2y$12$Syqc62QAk0eldH2u90xkvuUXiwBKU5jQ0YUiLJq0l9A0gxipcn88u', 'non', 'Philosophie', '2025-05-13 16:42:46');

-- --------------------------------------------------------

--
-- Structure de la table `professeur_classes`
--

CREATE TABLE `professeur_classes` (
  `id` int(11) NOT NULL,
  `id_professeur` int(11) NOT NULL,
  `nom_classe` varchar(50) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `professeur_classes`
--

INSERT INTO `professeur_classes` (`id`, `id_professeur`, `nom_classe`) VALUES
(1, 1, '6A'),
(2, 1, '5B');

-- --------------------------------------------------------

--
-- Structure de la table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `rate_key` varchar(255) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `reset_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Déchargement des données de la table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `rate_key`, `attempts`, `reset_at`, `created_at`) VALUES
(3, 'rate_limit:notification_token:8_eleve', 1, '2025-05-12 19:25:46', '2025-05-12 19:24:46'),
(2, 'rate_limit:api_notifications:8_eleve', 2, '2025-05-12 19:24:46', '2025-05-12 19:24:34');

-- --------------------------------------------------------

--
-- Structure de la table `retards`
--

CREATE TABLE `retards` (
  `id` int(11) NOT NULL,
  `id_eleve` int(11) NOT NULL,
  `date_retard` datetime NOT NULL,
  `duree_minutes` int(11) NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `justifie` tinyint(1) DEFAULT 0,
  `commentaire` text DEFAULT NULL,
  `signale_par` varchar(100) NOT NULL,
  `date_signalement` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Structure de la table `user_notification_preferences`
--

CREATE TABLE `user_notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('eleve','parent','professeur','vie_scolaire','administrateur') NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 0,
  `browser_notifications` tinyint(1) DEFAULT 1,
  `notification_sound` tinyint(1) DEFAULT 1,
  `mention_notifications` tinyint(1) DEFAULT 1,
  `reply_notifications` tinyint(1) DEFAULT 1,
  `important_notifications` tinyint(1) DEFAULT 1,
  `digest_frequency` enum('never','daily','weekly') DEFAULT 'never',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Structure de la table `vie_scolaire`
--

CREATE TABLE `vie_scolaire` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `mail` varchar(150) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `identifiant` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `est_CPE` enum('oui','non') NOT NULL DEFAULT 'non',
  `est_infirmerie` enum('oui','non') NOT NULL DEFAULT 'non',
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `absences`
--
ALTER TABLE `absences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_eleve` (`id_eleve`);

--
-- Index pour la table `administrateurs`
--
ALTER TABLE `administrateurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mail` (`mail`),
  ADD UNIQUE KEY `identifiant` (`identifiant`);

--
-- Index pour la table `cahier_texte`
--
ALTER TABLE `cahier_texte`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cahier_texte_classe_date` (`classe`,`date_cours`);

--
-- Index pour la table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `conversation_participants`
--
ALTER TABLE `conversation_participants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conv_participant` (`conversation_id`,`user_id`,`user_type`),
  ADD KEY `last_read_message_id` (`last_read_message_id`),
  ADD KEY `idx_conv_last_read` (`conversation_id`,`last_read_message_id`);

--
-- Index pour la table `devoirs`
--
ALTER TABLE `devoirs`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `devoirs_status`
--
ALTER TABLE `devoirs_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_devoir_eleve` (`id_devoir`,`id_eleve`);

--
-- Index pour la table `eleves`
--
ALTER TABLE `eleves`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mail` (`mail`),
  ADD UNIQUE KEY `identifiant` (`identifiant`);

--
-- Index pour la table `evenements`
--
ALTER TABLE `evenements`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `justificatifs`
--
ALTER TABLE `justificatifs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_eleve` (`id_eleve`);

--
-- Index pour la table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_messages_conversation` (`conversation_id`,`created_at`);

--
-- Index pour la table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`);

--
-- Index pour la table `message_notifications`
--
ALTER TABLE `message_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `idx_message_notifications_user_read` (`user_id`,`user_type`,`is_read`),
  ADD KEY `idx_message_notif_user_read` (`user_id`,`user_type`,`is_read`);

--
-- Index pour la table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_devoir` (`id_devoir`);

--
-- Index pour la table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mail` (`mail`),
  ADD UNIQUE KEY `identifiant` (`identifiant`);

--
-- Index pour la table `professeurs`
--
ALTER TABLE `professeurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mail` (`mail`),
  ADD UNIQUE KEY `identifiant` (`identifiant`);

--
-- Index pour la table `professeur_classes`
--
ALTER TABLE `professeur_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_prof_class` (`id_professeur`,`nom_classe`);

--
-- Index pour la table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rate_key` (`rate_key`);

--
-- Index pour la table `retards`
--
ALTER TABLE `retards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_eleve` (`id_eleve`);

--
-- Index pour la table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user` (`user_id`,`user_type`);

--
-- Index pour la table `vie_scolaire`
--
ALTER TABLE `vie_scolaire`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mail` (`mail`),
  ADD UNIQUE KEY `identifiant` (`identifiant`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `absences`
--
ALTER TABLE `absences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `administrateurs`
--
ALTER TABLE `administrateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT pour la table `cahier_texte`
--
ALTER TABLE `cahier_texte`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `conversation_participants`
--
ALTER TABLE `conversation_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `devoirs`
--
ALTER TABLE `devoirs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `devoirs_status`
--
ALTER TABLE `devoirs_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `eleves`
--
ALTER TABLE `eleves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `evenements`
--
ALTER TABLE `evenements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `justificatifs`
--
ALTER TABLE `justificatifs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `message_notifications`
--
ALTER TABLE `message_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `parents`
--
ALTER TABLE `parents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `professeurs`
--
ALTER TABLE `professeurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `professeur_classes`
--
ALTER TABLE `professeur_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `retards`
--
ALTER TABLE `retards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `vie_scolaire`
--
ALTER TABLE `vie_scolaire`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `conversation_participants`
--
ALTER TABLE `conversation_participants`
  ADD CONSTRAINT `conversation_participants_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversation_participants_ibfk_2` FOREIGN KEY (`last_read_message_id`) REFERENCES `messages` (`id`);

--
-- Contraintes pour la table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `message_notifications`
--
ALTER TABLE `message_notifications`
  ADD CONSTRAINT `message_notifications_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
