<?php
// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Standard path
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(dirname(__DIR__)))) . '/API/path_helper.php', // Another possible path
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    // Define ABSPATH for security check in path_helper.php
    if (!defined('ABSPATH')) define('ABSPATH', dirname(dirname(__FILE__)));
    require_once $path_helper;
    require_once API_CORE_PATH;
} else {
    // Fallback to direct inclusion if path_helper.php is not found
    $api_dir = dirname(dirname(dirname(__DIR__))) . '/API';
    if (file_exists($api_dir) && file_exists($api_dir . '/core.php')) {
        require_once $api_dir . '/core.php';
    } else {
        die("Cannot locate the API directory. Please check your installation.");
    }
}

/**
 * Configuration du module messagerie
 */

// Improved session handling with consistent settings
if (session_status() === PHP_SESSION_NONE) {
    // Use a consistent session name
    session_name('pronote_session');
    
    // Enforce secure session parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// Inclure la configuration globale si elle existe
$globalConfigPath = __DIR__ . '/../../API/config/config.php';
if (file_exists($globalConfigPath)) {
    require_once $globalConfigPath;
}

// Vérifier si la connexion à la base de données est déjà établie
if (!isset($pdo)) {
    // Essayer de charger le fichier de connexion principal
    $databasePath = __DIR__ . '/../../API/database.php';
    if (file_exists($databasePath)) {
        require_once $databasePath;
    } else {
        // Configuration de base de données propre à la messagerie
        $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
        $dbName = defined('DB_NAME') ? DB_NAME : 'db_MASSE';
        $dbUser = defined('DB_USER') ? DB_USER : '22405372';
        $dbPass = defined('DB_PASS') ? DB_PASS : '807014';
        
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            
            // Rendre la connexion disponible globalement
            $GLOBALS['pdo'] = $pdo;
        } catch (PDOException $e) {
            // Logger l'erreur mais ne pas l'afficher directement
            error_log("Erreur de connexion à la base de données: " . $e->getMessage());
        }
    }
}

// Define base URL for the application
if (!defined('BASE_URL')) {
    $baseDir = dirname($_SERVER['SCRIPT_NAME']);
    // Make sure baseDir ends with a trailing slash
    if (substr($baseDir, -1) !== '/') {
        $baseDir .= '/';
    }
    define('BASE_URL', $baseDir);
}
$baseUrl = BASE_URL;

// Forcer l'affichage des erreurs uniquement en développement
if (defined('APP_ENV') && APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Définir le chemin des logs si non défini
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', __DIR__ . '/../logs');
}

// Créer le dossier de logs s'il n'existe pas
if (!is_dir(LOGS_PATH)) {
    @mkdir(LOGS_PATH, 0755, true);
}

// Fonction utilitaire pour journaliser les événements
function logMessage($message, $type = 'info') {
    $logFile = LOGS_PATH . '/app_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$type] $message\n";
    @file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

// Fonction pour journaliser les uploads de fichiers
function logUpload($message, $data = null) {
    $logFile = LOGS_PATH . '/uploads_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        $formattedMessage .= " - " . json_encode($data);
    }
    
    @file_put_contents($logFile, $formattedMessage . "\n", FILE_APPEND);
}
?>