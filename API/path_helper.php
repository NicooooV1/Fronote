<?php
/**
 * Path Helper for Pronote
 * 
 * This file provides consistent path resolution across all modules,
 * ensuring that files can be found whether running locally or on the server.
 */

// Exit if accessed directly
if (!defined('ABSPATH') && basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    exit('Direct access not permitted');
}

// Only define paths once
if (!defined('API_PATHS_DEFINED')) {
    // Try various path combinations to find the API directory
    $possible_paths = [
        __DIR__, // If this file is already in the API directory
        dirname(dirname(__DIR__)) . '/API', // Two levels up + /API
        dirname(__DIR__) . '/API', // One level up + /API
        dirname(dirname(dirname(__DIR__))) . '/API', // Three levels up + /API
    ];

    $api_dir = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_dir($path)) {
            $api_dir = $path;
            break;
        }
    }

    if (!$api_dir) {
        die("Impossible de localiser le répertoire API.");
    }

    // Define constant for the API directory
    define('API_DIR', $api_dir);

    // Define constants for commonly used API files
    define('API_CORE_PATH', API_DIR . '/core.php');
    define('API_AUTH_PATH', API_DIR . '/auth.php');
    define('API_DATA_PATH', API_DIR . '/data.php');
    
    define('API_PATHS_DEFINED', true);
}
?>