<?php
/**
 * Intégration avec le système d'authentification principal
 * Ce fichier adapte la classe Auth du répertoire login pour le système de notes
 */

// Inclure le fichier de configuration de la base de données s'il n'est pas déjà inclus
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../../login/config/database.php';
}

// Inclure la classe Auth
require_once __DIR__ . '/../../login/src/auth.php';

// Initialiser l'objet Auth avec la connexion à la base de données
$auth = new Auth($pdo);

/**
 * Vérifie si l'utilisateur est connecté
 * 
 * @return bool
 */
function isLoggedIn() {
    global $auth;
    return $auth->isLoggedIn();
}

/**
 * Vérifie si l'utilisateur actuel est un professeur
 * 
 * @return bool
 */
function isTeacher() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('professeur');
}

/**
 * Vérifie si l'utilisateur actuel est un élève
 * 
 * @return bool
 */
function isStudent() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('eleve');
}

/**
 * Vérifie si l'utilisateur actuel est un parent
 * 
 * @return bool
 */
function isParent() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('parent');
}

/**
 * Vérifie si l'utilisateur actuel est un administrateur
 * 
 * @return bool
 */
function isAdmin() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('administrateur');
}

/**
 * Vérifie si l'utilisateur actuel est du personnel de vie scolaire
 * 
 * @return bool
 */
function isVieScolaire() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('vie_scolaire');
}

/**
 * Vérifie si l'utilisateur a le droit de modifier les notes
 * 
 * @return bool
 */
function canManageNotes() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

/**
 * Redirige vers la page de connexion si l'utilisateur n'est pas connecté
 */
function requireLogin() {
    global $auth;
    $auth->requireLogin();
}

/**
 * Obtient le nom complet de l'utilisateur actuellement connecté
 * 
 * @return string
 */
function getUserFullName() {
    if (!isset($_SESSION['user'])) {
        return '';
    }
    return $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
}

/**
 * Obtient l'ID de l'utilisateur actuellement connecté
 * 
 * @return int|null
 */
function getUserId() {
    if (!isset($_SESSION['user'])) {
        return null;
    }
    return $_SESSION['user']['id'];
}

/**
 * Obtient le profil de l'utilisateur actuellement connecté
 * 
 * @return string|null
 */
function getUserRole() {
    if (!isset($_SESSION['user'])) {
        return null;
    }
    return $_SESSION['user']['profil'];
}

// Vérifie que l'utilisateur est connecté pour accéder à toutes les pages du système de notes
requireLogin();
?>