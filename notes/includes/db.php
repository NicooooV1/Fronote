<?php
/**
 * Database connection for notes module
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
    // Fallback to direct inclusion if path_helper.php is not found
    $api_dir = dirname(dirname(dirname(__DIR__))) . '/API';
    if (file_exists($api_dir . '/core.php')) {
        require_once $api_dir . '/core.php';
    } else {
        die("Cannot locate the API directory. Please check your installation.");
    }
}
?>