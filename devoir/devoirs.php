<?php
// Include the path helper for API access
$path_helper = null;
$possible_paths = [
    dirname(dirname(__DIR__)) . '/API/path_helper.php',
    dirname(__DIR__) . '/API/path_helper.php',
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php',
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__));
    require_once $path_helper;
    require_once API_CORE_PATH;
    require_once API_AUTH_PATH;
} else {
    // Fallback path resolution
    $api_dir = dirname(dirname(__DIR__)) . '/API';
    if (!file_exists($api_dir . '/auth.php')) {
        $api_dir = dirname(__DIR__) . '/API';
    }
    require_once $api_dir . '/auth.php';
}

// Fix for the undefined function - replace isAuthenticated() with isLoggedIn()
// which is the standard function from the API
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Continue with the rest of the file
// Add your module-specific logic here
?>