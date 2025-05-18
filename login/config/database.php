<?php
/**
 * Database configuration for login module
 */

// Define database constants if they don't exist yet
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'db_MASSE');
if (!defined('DB_USER')) define('DB_USER', '22405372');
if (!defined('DB_PASS')) define('DB_PASS', '807014');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// First try to use the centralized API if available
$path_helper = null;
$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Standard path
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(dirname(__DIR__)))) . '/API/path_helper.php' // Another possible path
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
    
    // If we found path_helper, use the core PDO connection
    if (defined('API_CORE_PATH') && file_exists(API_CORE_PATH)) {
        require_once API_CORE_PATH;
        // $pdo is now defined from core.php
    }
} 

// If $pdo is not defined, create a local connection
if (!isset($pdo)) {
    try {
        // Use defined constants or fallback to default values
        $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
        $dbName = defined('DB_NAME') ? DB_NAME : 'db_MASSE';
        $dbUser = defined('DB_USER') ? DB_USER : '22405372';
        $dbPass = defined('DB_PASS') ? DB_PASS : '807014';
        $dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        
        // Add to globals so other parts of the API can use it
        $GLOBALS['pdo'] = $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        die("Une erreur s'est produite lors de la connexion à la base de données. Veuillez réessayer plus tard.");
    }
}
?>