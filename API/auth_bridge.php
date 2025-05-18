<?php
/**
 * Pont d'authentification pour les modules Pronote
 * Ce fichier standardise l'authentification pour tous les modules
 */

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Constantes utilisées pour les redirections
if (!defined('BASE_URL')) {
    define('BASE_URL', '/~u22405372/SAE/Pronote');
}

if (!defined('LOGIN_URL')) {
    define('LOGIN_URL', BASE_URL . '/login/public/index.php'); // Notez que c'est index.php, pas login.php
}

if (!defined('LOGOUT_URL')) {
    define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
}

if (!defined('HOME_URL')) {
    define('HOME_URL', BASE_URL . '/accueil/accueil.php');
}

// Constantes pour les rôles utilisateurs
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool Vrai si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * Récupère l'utilisateur connecté
 * @return array|null Données de l'utilisateur ou null si non connecté
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Récupère le rôle de l'utilisateur
 * @return string|null Rôle de l'utilisateur ou null si non connecté
 */
function getUserRole() {
    $user = getCurrentUser();
    return $user['profil'] ?? null;
}

/**
 * Vérifie si l'utilisateur est un administrateur
 * @return bool Vrai si l'utilisateur est un administrateur
 */
function isAdmin() {
    return getUserRole() === USER_TYPE_ADMIN;
}

/**
 * Vérifie si l'utilisateur est un professeur
 * @return bool Vrai si l'utilisateur est un professeur
 */
function isTeacher() {
    return getUserRole() === USER_TYPE_TEACHER;
}

/**
 * Vérifie si l'utilisateur est un élève
 * @return bool Vrai si l'utilisateur est un élève
 */
function isStudent() {
    return getUserRole() === USER_TYPE_STUDENT;
}

/**
 * Vérifie si l'utilisateur est un parent
 * @return bool Vrai si l'utilisateur est un parent
 */
function isParent() {
    return getUserRole() === USER_TYPE_PARENT;
}

/**
 * Vérifie si l'utilisateur est un membre de la vie scolaire
 * @return bool Vrai si l'utilisateur est un membre de la vie scolaire
 */
function isVieScolaire() {
    return getUserRole() === USER_TYPE_STAFF;
}

/**
 * Vérifie si l'utilisateur peut gérer les notes
 * @return bool Vrai si l'utilisateur peut gérer les notes
 */
function canManageNotes() {
    $role = getUserRole();
    return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
}

/**
 * Vérifie si l'utilisateur peut gérer les absences
 * @return bool Vrai si l'utilisateur peut gérer les absences
 */
function canManageAbsences() {
    $role = getUserRole();
    return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
}

/**
 * Vérifie si l'utilisateur peut gérer le cahier de textes
 * @return bool Vrai si l'utilisateur peut gérer le cahier de textes
 */
function canManageCahierTextes() {
    $role = getUserRole();
    return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
}

/**
 * Récupère le nom complet de l'utilisateur
 * @return string Nom complet de l'utilisateur ou chaîne vide si non connecté
 */
function getUserFullName() {
    $user = getCurrentUser();
    if ($user) {
        return $user['prenom'] . ' ' . $user['nom'];
    }
    return '';
}

/**
 * Force l'authentification et redirige si non connecté
 * @return array Données de l'utilisateur connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . LOGIN_URL);
        exit;
    }
    return getCurrentUser();
}

/**
 * Pont de compatibilité pour l'authentification centralisée
 * Permet aux modules existants de continuer à fonctionner avec le nouveau système d'authentification
 */

// Ce fichier est inclus par auth_central.php
// Ne pas l'inclure directement dans les modules

// Définir des alias de fonctions si elles n'existent pas encore

// Alias pour isProfesseur (ancienne syntaxe)
if (!function_exists('isProfesseur')) {
    function isProfesseur() {
        return isTeacher();
    }
}

// Alias pour l'ancienne fonction requireAuth (utilisée dans certains modules)
if (!function_exists('requireAuth')) {
    function requireAuth() {
        return requireLogin();
    }
}

// Alias pour checkAuth (utilisée dans certains modules)
if (!function_exists('checkAuth')) {
    function checkAuth() {
        return getCurrentUser();
    }
}

// Compatibilité avec différentes signatures de fonctions
if (!function_exists('canSendAnnouncement') && !function_exists('canSendMessage')) {
    function canSendAnnouncement($user = null) {
        if ($user === null) $user = getCurrentUser();
        return in_array($user['profil'], ['administrateur', 'vie_scolaire']);
    }
}
