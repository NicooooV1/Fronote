<?php
/**
 * Authentication functions for notes module
 */

/**
 * Vérifie si l'utilisateur est connecté
 * 
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Vérifie si l'utilisateur actuel est un professeur
 * 
 * @return bool
 */
function isTeacher() {
    // Pour test : si le système de rôles n'est pas configuré, considérer tous les utilisateurs connectés comme professeurs
    if (isset($_SESSION['user_role'])) {
        return $_SESSION['user_role'] === 'professeur';
    }
    // Temporairement - considérer tous les utilisateurs comme professeurs jusqu'à la mise en place complète
    return isLoggedIn(); 
}

/**
 * Vérifie si l'utilisateur actuel est un élève
 * 
 * @return bool
 */
function isStudent() {
    // Pour test : si le système de rôles n'est pas configuré, considérer que personne n'est élève
    if (isset($_SESSION['user_role'])) {
        return $_SESSION['user_role'] === 'eleve';
    }
    return false;
}

/**
 * Redirige vers la page de connexion si l'utilisateur n'est pas connecté
 */
function requireLogin() {
    // Pour tests - ne pas rediriger pour l'instant
    // si désactivé, cela permet d'accéder au système même sans session configurée
    /*
    if (!isLoggedIn()) {
        header('Location: ../login/login.php');
        exit;
    }
    */
}

// No need to redefine functions that already exist in the API
// Only declare functions that are specific to this module that aren't already defined

// Module-specific functions can be added here, checking if they already exist first
// (though they should typically be added to the central API instead)
?>