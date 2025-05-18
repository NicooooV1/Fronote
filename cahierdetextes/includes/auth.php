<?php
/**
 * Module d'authentification pour le module Cahier de Textes
 */

// Inclure le système d'autoloading
$autoloadPath = __DIR__ . '/../../API/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    
    // Initialiser l'application avec le système d'autoloading
    bootstrap();
} else {
    // Fallback si le système d'autoloading n'est pas disponible
    session_start();
    
    // Fonctions d'authentification de base si le système centralisé n'est pas disponible
    if (!function_exists('isLoggedIn')) {
        function isLoggedIn() {
            return isset($_SESSION['user']) && !empty($_SESSION['user']);
        }
    }
    
    if (!function_exists('getCurrentUser')) {
        function getCurrentUser() {
            return $_SESSION['user'] ?? null;
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
    
    if (!function_exists('getUserRole')) {
        function getUserRole() {
            $user = getCurrentUser();
            return $user ? $user['profil'] : null;
        }
    }
    
    if (!function_exists('isAdmin')) {
        function isAdmin() {
            return getUserRole() === 'administrateur';
        }
    }
    
    if (!function_exists('isTeacher')) {
        function isTeacher() {
            return getUserRole() === 'professeur';
        }
    }
    
    if (!function_exists('isVieScolaire')) {
        function isVieScolaire() {
            return getUserRole() === 'vie_scolaire';
        }
    }
    
    // Cette fonction est spécifique au Cahier de Textes et ne doit être définie que si nécessaire
    if (!function_exists('canManageDevoirs')) {
        function canManageDevoirs() {
            $role = getUserRole();
            return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
        }
    }
    
    if (!function_exists('canManageCahierTextes')) {
        function canManageCahierTextes() {
            $role = getUserRole();
            return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
        }
    }
}