<?php
/**
 * Fichier de gestion des fonctions d'authentification
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
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'professeur';
}

/**
 * Vérifie si l'utilisateur actuel est un élève
 * 
 * @return bool
 */
function isStudent() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'eleve';
}

/**
 * Redirige vers la page de connexion si l'utilisateur n'est pas connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login/login.php');
        exit;
    }
}

// Vérifie que l'utilisateur est connecté pour accéder à toutes les pages du système de notes
requireLogin();
?>