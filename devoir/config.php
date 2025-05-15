<?php
// config.php - General application configuration
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define absolute paths
define('ROOT_PATH', dirname(__FILE__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DATA_PATH', ROOT_PATH . '/data');

// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Standard path
    dirname(__DIR__) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Another possible path
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    // Define ABSPATH for security check in path_helper.php
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__));
    require_once $path_helper;
    require_once API_CORE_PATH;
    require_once API_AUTH_PATH;
    require_once API_DATA_PATH;
} else {
    // Fallback for direct inclusion if path_helper is not found
    $api_dir = dirname(dirname(__DIR__)) . '/API';
    if (file_exists($api_dir . '/core.php')) {
        require_once $api_dir . '/core.php';
        if (file_exists($api_dir . '/auth.php')) require_once $api_dir . '/auth.php';
        if (file_exists($api_dir . '/data.php')) require_once $api_dir . '/data.php';
    } else {
        die("Cannot locate the API directory. Please check your installation.");
    }
}
?>