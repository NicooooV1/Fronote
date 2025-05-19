<?php
/**
 * Module d'authentification pour le module Notes
 * Ce fichier utilise le système d'authentification centralisé
 */

// Utiliser le résolveur d'authentification central
$authPath = __DIR__ . '/../../API/auth_central.php';
if (file_exists($authPath)) {
    require_once $authPath;
} else {
    // Fallback si le fichier central n'est pas disponible
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Uniquement si les fonctions n'existent pas déjà
    if (!function_exists('isLoggedIn')) {
        /**
         * Vérifie si l'utilisateur est connecté
         * @return bool True si l'utilisateur est connecté
         */
        function isLoggedIn() {
            return isset($_SESSION['user']) && !empty($_SESSION['user']);
        }
    }
    
    if (!function_exists('getCurrentUser')) {
        /**
         * Récupérer l'utilisateur connecté
         * @return array|null Données de l'utilisateur ou null si non connecté
         */
        function getCurrentUser() {
            return $_SESSION['user'] ?? null;
        }
    }
    
    if (!function_exists('getUserRole')) {
        /**
         * Récupère le rôle de l'utilisateur
         * @return string|null Rôle de l'utilisateur
         */
        function getUserRole() {
            $user = getCurrentUser();
            return $user ? ($user['profil'] ?? null) : null;
        }
    }
    
    if (!function_exists('getUserFullName')) {
        /**
         * Récupère le nom complet de l'utilisateur
         * @return string Nom complet de l'utilisateur
         */
        function getUserFullName() {
            $user = getCurrentUser();
            if ($user && isset($user['nom']) && isset($user['prenom'])) {
                return $user['prenom'] . ' ' . $user['nom'];
            }
            return '';
        }
    }
    
    if (!function_exists('canManageNotes')) {
        /**
         * Vérifie si l'utilisateur peut gérer les notes
         * @return bool True si l'utilisateur peut gérer les notes
         */
        function canManageNotes() {
            $role = getUserRole();
            return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
        }
    }
    
    if (!function_exists('isAdmin')) {
        /**
         * Vérifie si l'utilisateur est un administrateur
         * @return bool True si l'utilisateur est un administrateur
         */
        function isAdmin() {
            return getUserRole() === 'administrateur';
        }
    }
    
    if (!function_exists('isTeacher')) {
        /**
         * Vérifie si l'utilisateur est un professeur
         * @return bool True si l'utilisateur est un professeur
         */
        function isTeacher() {
            return getUserRole() === 'professeur';
        }
    }
    
    if (!function_exists('isVieScolaire')) {
        /**
         * Vérifie si l'utilisateur est un membre de la vie scolaire
         * @return bool True si l'utilisateur est un membre de la vie scolaire
         */
        function isVieScolaire() {
            return getUserRole() === 'vie_scolaire';
        }
    }
}
?>