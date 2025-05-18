<?php
/**
 * Configuration globale pour l'application Pronote
 */

// Environnement (production, development, test)
define('APP_ENV', 'development');

// Configuration de base
define('APP_NAME', 'Pronote');
define('APP_VERSION', '1.0.0');
define('APP_ROOT', dirname(__DIR__, 2)); // Remonte de 2 niveaux depuis API/config
define('APP_URL', '/~u22405372/SAE/Pronote'); // Ajuster selon votre configuration

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_MASSE');
define('DB_USER', '22405372');
define('DB_PASS', '807014');
define('DB_CHARSET', 'utf8mb4');

// Configuration des sessions
define('SESSION_NAME', 'pronote_session');
define('SESSION_LIFETIME', 3600); // 1 heure
define('SESSION_PATH', '/');
define('SESSION_SECURE', false); // Mettre à true en production si HTTPS
define('SESSION_HTTPONLY', true);

// Configuration des chemins
define('API_DIR', APP_ROOT . '/API');
define('UPLOADS_PATH', APP_ROOT . '/uploads');
define('LOGS_PATH', API_DIR . '/logs');

// Configuration des logs
define('LOG_ENABLED', true);
define('LOG_LEVEL', 'debug'); // debug, info, warning, error

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

// Créer les dossiers nécessaires s'ils n'existent pas
$directories = [
    LOGS_PATH,
    UPLOADS_PATH
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
