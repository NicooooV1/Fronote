<?php
/**
 * Authentication functions for notes module
 */

// Locate and include the API auth file
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
    require_once API_AUTH_PATH;
} else {
    // Enhanced fallback for direct inclusion if path_helper.php is not found
    $possible_api_paths = [
        dirname(dirname(dirname(__DIR__))) . '/API/auth.php',
        dirname(dirname(__DIR__)) . '/API/auth.php',
        dirname(__DIR__) . '/../API/auth.php'
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
        // Last resort - create minimal authentication functions
        error_log("Cannot locate API auth file. Using minimal auth fallback");
        
        if (!function_exists('isLoggedIn')) {
            function isLoggedIn() {
                return isset($_SESSION['user']);
            }
        }
        
        if (!function_exists('getCurrentUser')) {
            function getCurrentUser() {
                return $_SESSION['user'] ?? null;
            }
        }
        
        if (!function_exists('getUserRole')) {
            function getUserRole() {
                return $_SESSION['user']['profil'] ?? '';
            }
        }
        
        if (!function_exists('canManageNotes')) {
            function canManageNotes() {
                $role = getUserRole();
                return $role === 'professeur' || $role === 'administrateur' || $role === 'vie_scolaire';
            }
        }
    }
}

// Spécifique au module Notes
// Si ces fonctions n'existent pas encore, on les définit
if (!function_exists('isTeacher')) {
    function isTeacher() {
        return getUserRole() === 'professeur';
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin() {
        return getUserRole() === 'administrateur';
    }
}

if (!function_exists('isVieScolaire')) {
    function isVieScolaire() {
        return getUserRole() === 'vie_scolaire';
    }
}

if (!function_exists('isStudent')) {
    function isStudent() {
        return getUserRole() === 'eleve';
    }
}

if (!function_exists('isParent')) {
    function isParent() {
        return getUserRole() === 'parent';
    }
}

if (!function_exists('getUserFullName')) {
    function getUserFullName() {
        $user = getCurrentUser();
        if ($user) {
            return $user['prenom'] . ' ' . $user['nom'];
        }
        return '';
    }
}
?>