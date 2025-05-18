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

// URL de base de l'application (à ajuster selon votre configuration)
if (!defined('BASE_URL')) define('BASE_URL', '/~u22405372/SAE/Pronote');

// Configuration des sessions
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'pronote_session');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600); // 1 heure
if (!defined('SESSION_PATH')) define('SESSION_PATH', '/');
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', !empty($_SERVER['HTTPS'])); // Activé si HTTPS
if (!defined('SESSION_HTTPONLY')) define('SESSION_HTTPONLY', true);
if (!defined('SESSION_SAME_SITE')) define('SESSION_SAME_SITE', 'Lax');

// Configuration des chemins
if (!defined('API_DIR')) define('API_DIR', APP_ROOT . '/API');
if (!defined('UPLOADS_PATH')) define('UPLOADS_PATH', APP_ROOT . '/uploads');
if (!defined('LOGS_PATH')) define('LOGS_PATH', API_DIR . '/logs');

// Configuration des logs
if (!defined('LOG_ENABLED')) define('LOG_ENABLED', true);
if (!defined('LOG_LEVEL')) define('LOG_LEVEL', APP_ENV === 'development' ? 'debug' : 'error');

// Types d'utilisateurs
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// URLs communes - Utiliser BASE_URL
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Affichage des erreurs selon l'environnement
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Tentative de création des dossiers nécessaires
$directories = [
    LOGS_PATH,
    UPLOADS_PATH
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
