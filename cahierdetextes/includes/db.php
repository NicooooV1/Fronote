<?php
/**
 * Database connection for cahier de textes module
 */

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
    // Enhanced fallback for direct inclusion if path_helper.php is not found
    $possible_api_paths = [
        dirname(dirname(dirname(__DIR__))) . '/API/core.php',
        dirname(dirname(__DIR__)) . '/API/core.php',
        dirname(__DIR__) . '/../API/core.php'
    ];
    
    $api_path = null;
    foreach ($possible_api_paths as $path) {
        if (file_exists($path)) {
            $api_path = $path;
            break;
        }
    }
    
    if ($api_path) {
        require_once $api_path;
    } else {
        // Create emergency database connection as last resort
        try {
            $dsn = "mysql:host=localhost;dbname=db_MASSE;charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, '22405372', '807014', $options);
            error_log("Emergency database connection created in cahierdetextes/includes/db.php");
        } catch (PDOException $e) {
            die("Cannot locate API or establish database connection. Error: " . $e->getMessage());
        }
    }
}
?>