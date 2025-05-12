<?php
/**
 * Fichier d'initialisation de l'application ENT Scolaire
 * Chargé dans toutes les pages
 */

// Démarrer ou restaurer une session
session_start();

// Définir l'environnement (development, production)
define('ENVIRONMENT', 'development');

// Configuration des erreurs selon l'environnement
if (ENVIRONMENT === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING);
}

// Charger la configuration de base
require_once __DIR__ . '/config.php';

// Définir l'encodage par défaut
mb_internal_encoding('UTF-8');

// Définir le fuseau horaire
date_default_timezone_set('Europe/Paris');

// Fonction d'autoload des classes
spl_autoload_register(function ($class) {
    // Liste des répertoires où chercher les classes
    $directories = [
        ROOT_PATH . '/models/',
        ROOT_PATH . '/controllers/',
        ROOT_PATH . '/utils/'
    ];
    
    // Parcourir les répertoires
    foreach ($directories as $directory) {
        $file = $directory . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Charger les fonctions utilitaires
require_once ROOT_PATH . '/includes/functions.php';

// Connecter à la base de données
require_once ROOT_PATH . '/utils/Database.php';

// Gérer les sessions expirées
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
    // La session a expiré
    session_unset();
    session_destroy();
    
    // Rediriger vers la page de connexion si ce n'est pas déjà le cas
    $currentPage = basename($_SERVER['PHP_SELF']);
    if ($currentPage !== 'login.php') {
        header('Location: ' . BASE_URL . '/login.php?session_expired=1');
        exit;
    }
}

// Mettre à jour le timestamp de dernière activité
$_SESSION['last_activity'] = time();

// Vérifier si l'utilisateur est connecté (sauf pour la page de connexion)
$currentPage = basename($_SERVER['PHP_SELF']);
$publicPages = ['login.php', 'reset-password.php', 'register.php'];

if (!in_array($currentPage, $publicPages)) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

// Gérer les messages flash (conservés pour une seule requête)
if (!isset($_SESSION['flash_messages'])) {
    $_SESSION['flash_messages'] = [];
}

// Fonction pour ajouter un message flash
function addFlashMessage($type, $message) {
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

// Fonction pour récupérer tous les messages flash et les supprimer
function getFlashMessages() {
    $messages = $_SESSION['flash_messages'];
    $_SESSION['flash_messages'] = [];
    return $messages;
}