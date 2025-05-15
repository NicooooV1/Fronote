<?php
/**
 * Helper file for resolving paths to API files
 * Include this file at the beginning of any file that needs to access the API
 */

// Don't redefine if already defined
if (!defined('API_DIR')) {
    // Determine the correct path whether we're in local dev or on server
    $base_dir = dirname(dirname(__FILE__));
    $api_dir = $base_dir . '/API';
    
    // Si nous sommes sur le serveur, le chemin pourrait être différent
    if (!file_exists($api_dir)) {
        $api_dir = dirname(__DIR__) . '/API';
    }
    
    // If we still can't find it, try one more level up
    if (!file_exists($api_dir)) {
        $api_dir = dirname(dirname(__DIR__)) . '/API';
    }
    
    // Define the constant for use throughout the application
    define('API_DIR', $api_dir);
    
    // Define paths to commonly used API files
    define('API_CORE_PATH', API_DIR . '/core.php');
    define('API_AUTH_PATH', API_DIR . '/auth.php');
    define('API_DATA_PATH', API_DIR . '/data.php');
}
?>