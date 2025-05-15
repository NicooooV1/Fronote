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
        $_SERVER['DOCUMENT_ROOT'] . '/SAE/Pronote/API', // Absolute path on server
        $_SERVER['DOCUMENT_ROOT'] . '/~u22405372/SAE/Pronote/API', // User directory on server
    ];

    $api_dir = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path) && file_exists($path . '/core.php')) {
            $api_dir = $path;
            break;
        }
    }
    
    // If we still can't find the API directory, try one more approach
    if (!$api_dir) {
        // Try to determine path based on the script path
        $script_path = dirname($_SERVER['SCRIPT_FILENAME']);
        $pronote_pos = strpos($script_path, 'Pronote');
        
        if ($pronote_pos !== false) {
            $base_dir = substr($script_path, 0, $pronote_pos + strlen('Pronote'));
            $api_candidate = $base_dir . '/API';
            
            if (file_exists($api_candidate) && file_exists($api_candidate . '/core.php')) {
                $api_dir = $api_candidate;
            }
        }
    }
    
    // If we still can't find it, throw an error
    if (!$api_dir) {
        error_log('API directory not found. Please check server configuration.');
        
        // As a last resort, try to dynamically create core.php in a discoverable location
        $emergency_api_dir = dirname($_SERVER['SCRIPT_FILENAME']) . '/API';
        if (!file_exists($emergency_api_dir)) {
            @mkdir($emergency_api_dir, 0755, true);
        }
        
        // Define API directory location
        $api_dir = $emergency_api_dir;
    }
    
    // Define constants
    define('API_DIR', $api_dir);
    define('API_CORE_PATH', API_DIR . '/core.php');
    define('API_AUTH_PATH', API_DIR . '/auth.php');
    define('API_DATA_PATH', API_DIR . '/data.php');
    define('API_PATHS_DEFINED', true);
    
    // Create a function to include API files safely
    if (!function_exists('include_api_file')) {
        function include_api_file($file) {
            $path = API_DIR . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
                return true;
            }
            return false;
        }
    }
}
?>