<?php
/**
 * Point d'entrée central pour l'application
 * Initialise toutes les fonctionnalités essentielles
 */

// Démarrer la session
session_start();

// Charger les fichiers de configuration
$configFile = __DIR__ . '/config/env.php';

if (!file_exists($configFile)) {
    die('Le fichier de configuration n\'existe pas. Veuillez exécuter l\'installation.');
}

require_once $configFile;

// Configuration de base de PHP
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

// Définir des constantes si elles ne sont pas définies
if (!defined('APP_ENV')) define('APP_ENV', 'production');
if (!defined('APP_ROOT')) define('APP_ROOT', realpath(__DIR__ . '/../'));

// Inclure les fichiers essentiels
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth_central.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/cache.php';

/**
 * Fonction pour démarrer l'application
 * Configure et initialise tous les composants essentiels
 */
function bootstrap() {
    try {
        // Initialiser la connexion à la base de données
        initDatabase();
        
        // Configuration des en-têtes de sécurité
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        
        // En production, forcer HTTPS
        if (APP_ENV === 'production' && !isset($_SERVER['HTTPS'])) {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect);
            exit;
        }
        
        return true;
    } catch (Exception $e) {
        // Journaliser l'erreur
        error_log("Erreur d'initialisation: " . $e->getMessage());
        
        // En développement, afficher l'erreur
        if (APP_ENV === 'development') {
            echo "Erreur d'initialisation: " . $e->getMessage();
        }
        
        return false;
    }
}

/**
 * Initialise la connexion à la base de données et configure PDO
 */
function initDatabase() {
    global $pdo;
    
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new Exception('Configuration de base de données incomplète');
    }
    
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
    
    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        throw new Exception('Erreur de connexion à la base de données: ' . $e->getMessage());
    }
}

// Auto-démarrer l'application si ce n'est pas inclus dans un autre fichier
if (basename($_SERVER['SCRIPT_NAME']) === 'bootstrap.php') {
    bootstrap();
}
