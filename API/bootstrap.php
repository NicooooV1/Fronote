<?php
/**
 * Bootstrap de l'application
 * Ce fichier charge toutes les dépendances et initialise l'application
 */

// Activer la mise en mémoire tampon pour éviter les erreurs "headers already sent"
ob_start();

// Détection automatique du chemin racine de l'application
if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(dirname(__FILE__) . '/../'));
}

// Chemins relatifs à la racine de l'application
define('API_DIR', APP_ROOT . '/API');
define('UPLOADS_PATH', APP_ROOT . '/uploads');
define('LOGS_PATH', API_DIR . '/logs');

// Vérifier si le fichier d'environnement existe
$envFile = __DIR__ . '/config/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
} else {
    // Charger la configuration par défaut
    require_once __DIR__ . '/config/config.php';
}

require_once __DIR__ . '/config/constants.php';

// Créer les répertoires nécessaires s'ils n'existent pas
$directories = [
    LOGS_PATH,
    UPLOADS_PATH
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Charger les classes de base
require_once __DIR__ . '/core/Autoloader.php';
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/Security.php';

// Enregistrer l'autoloader
Autoloader::register();

// Initialiser la session
Session::init();

// Vérifier les extensions requises
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (!empty($missingExtensions)) {
    Logger::error('Extensions PHP requises manquantes : ' . implode(', ', $missingExtensions));
    if (defined('APP_ENV') && APP_ENV === 'development') {
        die('Extensions PHP requises manquantes : ' . implode(', ', $missingExtensions));
    }
}

// Gestionnaires d'erreurs et d'exceptions
function handleFatalErrors() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        Logger::error('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
        
        if (defined('APP_ENV') && APP_ENV === 'development') {
            echo '<div style="background-color:#f8d7da;color:#721c24;padding:10px;margin:10px;border-radius:5px;">';
            echo '<h3>Une erreur fatale est survenue</h3>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
            echo '<p><strong>Fichier:</strong> ' . htmlspecialchars($error['file']) . '</p>';
            echo '<p><strong>Ligne:</strong> ' . $error['line'] . '</p>';
            echo '</div>';
        } else {
            // En production, afficher un message générique
            echo '<div style="text-align:center;padding:50px;">';
            echo '<h2>Une erreur est survenue</h2>';
            echo '<p>Nous sommes désolés pour ce désagrément. Veuillez réessayer plus tard.</p>';
            echo '</div>';
        }
    }
}

register_shutdown_function('handleFatalErrors');

set_exception_handler(function($exception) {
    Logger::error('Uncaught exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ' on line ' . $exception->getLine());
    
    if (defined('APP_ENV') && APP_ENV === 'development') {
        echo '<div style="background-color:#f8d7da;color:#721c24;padding:10px;margin:10px;border-radius:5px;">';
        echo '<h3>Exception non capturée</h3>';
        echo '<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
        echo '<p><strong>Fichier:</strong> ' . htmlspecialchars($exception->getFile()) . '</p>';
        echo '<p><strong>Ligne:</strong> ' . $exception->getLine() . '</p>';
        echo '<h4>Trace:</h4>';
        echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
        echo '</div>';
    } else {
        // Message générique en production
        echo '<div style="text-align:center;padding:50px;">';
        echo '<h2>Une erreur est survenue</h2>';
        echo '<p>Nous sommes désolés pour ce désagrément. Veuillez réessayer plus tard.</p>';
        echo '</div>';
    }
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ce niveau d'erreur est-il inclus dans error_reporting?
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    switch ($errno) {
        case E_ERROR:
        case E_USER_ERROR:
            Logger::error("Erreur PHP [$errno] $errstr dans $errfile à la ligne $errline");
            break;
        case E_WARNING:
        case E_USER_WARNING:
            Logger::warning("Avertissement PHP [$errno] $errstr dans $errfile à la ligne $errline");
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
            Logger::info("Notice PHP [$errno] $errstr dans $errfile à la ligne $errline");
            break;
        default:
            Logger::debug("Erreur inconnue [$errno] $errstr dans $errfile à la ligne $errline");
    }
    
    // Ne pas exécuter le gestionnaire d'erreur interne de PHP
    return true;
});
