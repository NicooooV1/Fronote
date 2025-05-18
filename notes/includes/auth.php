<?php
/**
 * Module d'authentification pour le module Notes
 */

// Utiliser le résolveur d'authentification qui évite les problèmes de redéclaration
$authResolvePath = __DIR__ . '/../../API/auth_resolve.php';
if (file_exists($authResolvePath)) {
    require_once $authResolvePath;
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
         * @return string|null Rôle de l'utilisateur ou null
         */
        function getUserRole() {
            $user = getCurrentUser();
            return $user ? $user['profil'] : null;
        }
    }
}
?>