<?php
/**
 * Fichier de compatibilité pour Pronote
 * Ce fichier assure la transition entre les différentes versions des systèmes d'authentification
 */

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifier si les constantes de rôles sont définies
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// Fonctions de base pour l'authentification en cas d'urgence
function emergency_isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

function emergency_getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

function emergency_getUserRole() {
    $user = emergency_getCurrentUser();
    return $user ? $user['profil'] : null;
}

function emergency_requireLogin() {
    if (!emergency_isLoggedIn()) {
        $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
        header('Location: ' . $loginUrl);
        exit;
    }
    return emergency_getCurrentUser();
}

// Point d'entrée pour l'utilisation d'urgence
if (!function_exists('ensure_auth_functions')) {
    /**
     * Assure que les fonctions d'authentification de base sont disponibles
     */
    function ensure_auth_functions() {
        // Liste des fonctions essentielles qui doivent être disponibles
        $essential_functions = [
            'isLoggedIn', 
            'getCurrentUser', 
            'getUserRole', 
            'requireLogin'
        ];
        
        $missing = false;
        foreach ($essential_functions as $func) {
            if (!function_exists($func)) {
                $missing = true;
                break;
            }
        }
        
        if ($missing) {
            // Définir les fonctions manquantes en utilisant les fonctions d'urgence
            if (!function_exists('isLoggedIn')) {
                function isLoggedIn() { return emergency_isLoggedIn(); }
            }
            
            if (!function_exists('getCurrentUser')) {
                function getCurrentUser() { return emergency_getCurrentUser(); }
            }
            
            if (!function_exists('getUserRole')) {
                function getUserRole() { return emergency_getUserRole(); }
            }
            
            if (!function_exists('requireLogin')) {
                function requireLogin() { return emergency_requireLogin(); }
            }
            
            return false; // Indique que les fonctions manquantes ont été définies
        }
        
        return true; // Tout va bien
    }
}

// Assurer automatiquement que les fonctions essentielles sont disponibles
ensure_auth_functions();
