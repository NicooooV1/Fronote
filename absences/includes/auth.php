<?php
/**
 * Module d'authentification pour le module Absences
 * Bridge vers auth_central.php
 */

// Démarrer la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Essayer d'inclure le fichier d'authentification central
$authCentralPath = __DIR__ . '/../../API/auth_central.php';
if (file_exists($authCentralPath)) {
    require_once $authCentralPath;
} else {
    // Fallback si le fichier central n'est pas disponible
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
         * Récupère l'utilisateur connecté
         * @return array|null Données de l'utilisateur ou null
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
    
    if (!function_exists('isAdmin')) {
        /**
         * Vérifie si l'utilisateur est administrateur
         * @return bool True si l'utilisateur est administrateur
         */
        function isAdmin() {
            return getUserRole() === 'administrateur';
        }
    }
    
    if (!function_exists('isTeacher')) {
        /**
         * Vérifie si l'utilisateur est professeur
         * @return bool True si l'utilisateur est professeur
         */
        function isTeacher() {
            return getUserRole() === 'professeur';
        }
    }
    
    if (!function_exists('isStudent')) {
        /**
         * Vérifie si l'utilisateur est élève
         * @return bool True si l'utilisateur est élève
         */
        function isStudent() {
            return getUserRole() === 'eleve';
        }
    }
    
    if (!function_exists('isParent')) {
        /**
         * Vérifie si l'utilisateur est parent
         * @return bool True si l'utilisateur est parent
         */
        function isParent() {
            return getUserRole() === 'parent';
        }
    }
    
    if (!function_exists('isVieScolaire')) {
        /**
         * Vérifie si l'utilisateur est membre de la vie scolaire
         * @return bool True si l'utilisateur est membre de la vie scolaire
         */
        function isVieScolaire() {
            return getUserRole() === 'vie_scolaire';
        }
    }
    
    if (!function_exists('getUserFullName')) {
        /**
         * Récupère le nom complet de l'utilisateur
         * @return string Nom complet de l'utilisateur ou chaîne vide
         */
        function getUserFullName() {
            $user = getCurrentUser();
            if ($user) {
                return $user['prenom'] . ' ' . $user['nom'];
            }
            return '';
        }
    }
    
    if (!function_exists('canManageAbsences')) {
        /**
         * Vérifie si l'utilisateur peut gérer les absences
         * @return bool True si l'utilisateur peut gérer les absences
         */
        function canManageAbsences() {
            $role = getUserRole();
            return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
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
    
    if (!function_exists('requireLogin')) {
        /**
         * Rediriger si l'utilisateur n'est pas connecté
         * @return array|null Données utilisateur ou null
         */
        function requireLogin() {
            if (!isLoggedIn()) {
                header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
                exit;
            }
            return getCurrentUser();
        }
    }
}
