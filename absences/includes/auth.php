<?php
/**
 * Module d'authentification pour le module Absences
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
    
    // Inclure les fonctions d'authentification de base (version simplifiée)
    // Éviter de redéfinir les fonctions si elles existent déjà
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
    
    if (!function_exists('getUserFullName')) {
        function getUserFullName() {
            $user = getCurrentUser();
            if ($user) {
                return $user['prenom'] . ' ' . $user['nom'];
            }
            return '';
        }
    }
    
    if (!function_exists('canManageAbsences')) {
        function canManageAbsences() {
            $role = getUserRole();
            return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
        }
    }
}
