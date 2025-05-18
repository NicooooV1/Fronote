<?php
/**
 * Point d'entrée central pour l'application
 * Initialise toutes les fonctionnalités essentielles
 */

// Démarrer la session si besoin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si le fichier autoload.php a déjà été inclus
$autoloadIncluded = defined('PRONOTE_AUTOLOAD_INCLUDED');

// Charger les fichiers de configuration
$configFile = __DIR__ . '/config/env.php';

if (!file_exists($configFile)) {
    // Si le fichier de configuration n'existe pas, utiliser des valeurs par défaut
    define('APP_ENV', 'development');
    define('APP_ROOT', realpath(__DIR__ . '/../'));
    define('BASE_URL', '/~u22405372/SAE/Pronote');
} else {
    require_once $configFile;
}

// Configuration de base de PHP
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

// Définir des constantes si elles ne sont pas définies
if (!defined('APP_ENV')) define('APP_ENV', 'production');
if (!defined('APP_ROOT')) define('APP_ROOT', realpath(__DIR__ . '/../'));
if (!defined('BASE_URL')) define('BASE_URL', '/~u22405372/SAE/Pronote');

// Vérifier si la classe Session existe
$sessionFilePath = __DIR__ . '/core/Session.php';
$sessionFallbackPath = __DIR__ . '/core/SessionFallback.php';

if (file_exists($sessionFilePath)) {
    // Si le fichier Session.php existe, l'inclure
    require_once $sessionFilePath;
    
    // Initialiser la session si la classe Session existe
    if (class_exists('Session') && method_exists('Session', 'init')) {
        Session::init();
    }
} elseif (file_exists($sessionFallbackPath)) {
    // Sinon, utiliser la version de secours
    require_once $sessionFallbackPath;
}

// Inclure les fichiers essentiels s'ils existent
$errorsFile = __DIR__ . '/errors.php';
if (file_exists($errorsFile)) {
    require_once $errorsFile;
}

$databaseFile = __DIR__ . '/database.php';
if (file_exists($databaseFile)) {
    require_once $databaseFile;
}

$authCentralFile = __DIR__ . '/auth_central.php';
if (file_exists($authCentralFile)) {
    require_once $authCentralFile;
}

$validatorFile = __DIR__ . '/validator.php';
if (file_exists($validatorFile)) {
    require_once $validatorFile;
}

$cacheFile = __DIR__ . '/cache.php';
if (file_exists($cacheFile)) {
    require_once $cacheFile;
}

// Définir la fonction bootstrap() uniquement si elle n'existe pas déjà et si autoload n'a pas été inclus
if (!function_exists('bootstrap') && !$autoloadIncluded) {
    /**
     * Fonction pour démarrer l'application
     * Configure et initialise tous les composants essentiels
     */
    function bootstrap() {
        try {
            // Initialiser la connexion à la base de données si la fonction existe
            if (function_exists('initDatabase')) {
                initDatabase();
            }
            
            // Configuration des en-têtes de sécurité
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            
            // En production, forcer HTTPS
            if (APP_ENV === 'production' && !isset($_SERVER['HTTPS'])) {
                // Vérifier si nous sommes sur un serveur Web qui prend en charge HTTPS
                if (isset($_SERVER['SERVER_SOFTWARE']) && 
                    (strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false ||
                    strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false ||
                    strpos($_SERVER['SERVER_SOFTWARE'], 'IIS') !== false)) {
                    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                    header('Location: ' . $redirect);
                    exit;
                }
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
}

// Définir la fonction initDatabase() uniquement si elle n'existe pas déjà et si autoload n'a pas été inclus
if (!function_exists('initDatabase') && !$autoloadIncluded) {
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
}

// Marquer ce fichier comme inclus pour éviter les redéclarations de fonctions
if (!defined('PRONOTE_BOOTSTRAP_INCLUDED')) {
    define('PRONOTE_BOOTSTRAP_INCLUDED', true);
}

// Auto-démarrer l'application si ce n'est pas inclus dans un autre fichier
if (!$autoloadIncluded && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'bootstrap.php') {
    bootstrap();
}
