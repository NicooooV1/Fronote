<?php
/**
 * Authentication functions for cahier de textes module
 */

// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Standard path
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(dirname(__DIR__)))) . '/API/path_helper.php', // Another possible path
    $_SERVER['DOCUMENT_ROOT'] . '/SAE/Pronote/API/path_helper.php', // Server absolute path
    $_SERVER['DOCUMENT_ROOT'] . '/~u22405372/SAE/Pronote/API/path_helper.php', // User directory path
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
    
    // Include the centralized API auth file
    require_once API_AUTH_PATH;
} else {
    // Enhanced fallback for direct inclusion if path_helper.php is not found
    $possible_api_paths = [
        dirname(dirname(dirname(__DIR__))) . '/API/auth.php',
        dirname(dirname(__DIR__)) . '/API/auth.php',
        dirname(__DIR__) . '/../API/auth.php',
        $_SERVER['DOCUMENT_ROOT'] . '/SAE/Pronote/API/auth.php',
        $_SERVER['DOCUMENT_ROOT'] . '/~u22405372/SAE/Pronote/API/auth.php'
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
        // Last resort - create a minimal authentication service
        error_log("Cannot locate API auth file. Using minimal auth fallback in cahierdetextes/includes/auth.php");
        
        if (!function_exists('isLoggedIn')) {
            function isLoggedIn() {
                return isset($_SESSION['user']);
            }
        }
        
        if (!function_exists('isTeacher')) {
            function isTeacher() {
                return isset($_SESSION['user']) && $_SESSION['user']['profil'] === 'professeur';
            }
        }
        
        if (!function_exists('isAdmin')) {
            function isAdmin() {
                return isset($_SESSION['user']) && $_SESSION['user']['profil'] === 'administrateur';
            }
        }
        
        if (!function_exists('isVieScolaire')) {
            function isVieScolaire() {
                return isset($_SESSION['user']) && $_SESSION['user']['profil'] === 'vie_scolaire';
            }
        }
        
        if (!function_exists('canManageDevoirs')) {
            function canManageDevoirs() {
                return isTeacher() || isAdmin() || isVieScolaire();
            }
        }
        
        if (!function_exists('getCurrentUser')) {
            function getCurrentUser() {
                return isset($_SESSION['user']) ? $_SESSION['user'] : null;
            }
        }
        
        if (!function_exists('getUserFullName')) {
            function getUserFullName() {
                if (isset($_SESSION['user'])) {
                    $user = $_SESSION['user'];
                    return $user['prenom'] . ' ' . $user['nom'];
                }
                return '';
            }
        }
    }
}
?>