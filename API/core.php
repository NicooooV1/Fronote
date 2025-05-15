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
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../login/config/database.php';
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

/**
 * Include the authentication system
 * If the inclusion fails due to auth.php not being loaded yet, load it
 */
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
?>
