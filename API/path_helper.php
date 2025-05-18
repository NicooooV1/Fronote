<?php
/**
 * Helper pour la gestion des chemins d'accès à l'API
 */

// Vérification de sécurité pour éviter l'accès direct
if (!defined('ABSPATH')) {
    die('Accès direct interdit');
}

// Trouver le chemin racine de l'API
$api_dir = null;
$current_dir = dirname(__FILE__);

// Essayer des chemins relatifs probables
$possible_api_dirs = [
    $current_dir,
    dirname(dirname(__FILE__)) . '/API',
    dirname(dirname(dirname(__FILE__))) . '/API',
];

foreach ($possible_api_dirs as $dir) {
    if (file_exists($dir . '/core.php')) {
        $api_dir = $dir;
        break;
    }
}

if (!defined('API_PATHS_DEFINED')) {
    if (!$api_dir) {
        die("Impossible de localiser le répertoire API.");
    }

    // Define constant for the API directory
    if (!defined('API_DIR')) define('API_DIR', $api_dir);

    // Define constants for commonly used API files
    if (!defined('API_CORE_PATH')) define('API_CORE_PATH', API_DIR . '/core.php');
    if (!defined('API_AUTH_PATH')) define('API_AUTH_PATH', API_DIR . '/auth.php');
    if (!defined('API_DATA_PATH')) define('API_DATA_PATH', API_DIR . '/data.php');
    
    define('API_PATHS_DEFINED', true);
}
?>