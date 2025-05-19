<?php
/**
 * Configuration globale pour l'application Pronote
 */

// Vérifier si le fichier env.php existe et le charger
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
}

// Environnement (production, development, test)
if (!defined('APP_ENV')) define('APP_ENV', 'development');

// Configuration de base
if (!defined('APP_NAME')) define('APP_NAME', 'Pronote');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__, 2)); // Remonte de 2 niveaux depuis API/config

// URL de base de l'application (plus dynamique pour portabilité)
if (!defined('BASE_URL')) {
    $baseDirectory = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = '';
    
    // Détection automatique du chemin de base
    if (isset($_SERVER['HTTP_HOST'])) {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $baseUrl .= "://" . $_SERVER['HTTP_HOST'];
        
        // Si nous ne sommes pas à la racine du domaine, déterminer le chemin de base
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptPath !== '/' && $scriptPath !== '\\') {
            // Extraire le chemin de base (sans le module spécifique)
            $parts = explode('/', $scriptPath);
            // Supprimer le dernier segment si c'est un module connu
            $modules = ['API', 'notes', 'absences', 'cahierdetextes', 'agenda', 'messagerie', 'login', 'accueil'];
            if (in_array(end($parts), $modules)) {
                array_pop($parts);
            }
            $baseUrl .= implode('/', $parts);
        }
    }
    
    define('BASE_URL', $baseUrl);
}

// Configuration de la base de données par défaut si non définie dans env.php
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'pronote');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Configuration des sessions
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'pronote_session');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600); // 1 heure

// Configuration des chemins
if (!defined('API_DIR')) define('API_DIR', APP_ROOT . '/API');
if (!defined('UPLOADS_PATH')) define('UPLOADS_PATH', APP_ROOT . '/uploads');
if (!defined('LOGS_PATH')) define('LOGS_PATH', API_DIR . '/logs');
if (!defined('TEMP_PATH')) define('TEMP_PATH', sys_get_temp_dir() . '/pronote');

// URLs communes - Utiliser BASE_URL
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Tentative de création des dossiers nécessaires
$directories = [
    LOGS_PATH,
    UPLOADS_PATH,
    TEMP_PATH
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        try {
            @mkdir($dir, 0755, true);
        } catch (Exception $e) {
            // Ignorer les erreurs pour éviter de bloquer l'application
            error_log("Impossible de créer le répertoire: " . $dir . " - " . $e->getMessage());
        }
    }
}
