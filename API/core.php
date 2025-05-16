<?php
/**
 * Core API file for Pronote system
 * This file provides centralized database connection management and session handling
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration from login system
$db_config_file = __DIR__ . '/../login/config/database.php';

if (file_exists($db_config_file)) {
    require_once $db_config_file;
} else {
    // Fallback database configurations
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'db_MASSE');
    if (!defined('DB_USER')) define('DB_USER', '22405372');
    if (!defined('DB_PASS')) define('DB_PASS', '807014');
    
    error_log("Database config file not found at expected location: $db_config_file");
}

// Create a single PDO connection if it doesn't exist already
if (!isset($GLOBALS['pdo'])) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $GLOBALS['pdo'] = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log the error
        error_log("Database connection error: " . $e->getMessage());
        die("Une erreur s'est produite lors de la connexion à la base de données. Veuillez réessayer plus tard.");
    }
}

// Expose the PDO connection
$pdo = $GLOBALS['pdo'];

// Check for auth.php
$auth_path = __DIR__ . '/auth.php';
if (file_exists($auth_path) && !function_exists('isLoggedIn')) {
    require_once $auth_path;
}
?>
