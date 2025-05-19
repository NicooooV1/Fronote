<?php
/**
 * Database connection for notes module
 * This file uses the centralized database connection
 */

// Check if the global PDO object already exists
if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    // Try to use the centralized connection
    $dbPath = __DIR__ . '/../../API/database.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
        $pdo = getDBConnection();
    } else {
        // Fallback if the centralized file is not available
        try {
            // Path to the configuration file
            $configPath = __DIR__ . '/../../API/config/config.php';
            if (file_exists($configPath)) {
                require_once $configPath;
            }
            
            // Retrieve configuration constants or use default values
            $host = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbname = defined('DB_NAME') ? DB_NAME : 'pronote';
            $user = defined('DB_USER') ? DB_USER : 'root';
            $pass = defined('DB_PASS') ? DB_PASS : '';
            $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
            
            // Create the PDO connection
            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    // Store the connection in a global variable for reuse
    $GLOBALS['pdo'] = $pdo;
} else {
    // Reuse the existing connection
    $pdo = $GLOBALS['pdo'];
}
?>