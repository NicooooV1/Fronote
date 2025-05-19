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
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
}

/**
 * Récupère l'utilisateur connecté
 * @return array|null Données de l'utilisateur ou null si non connecté
 */
if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        return $_SESSION['user'] ?? null;
    }
}

/**
 * Récupère le rôle de l'utilisateur
 * @return string|null Rôle de l'utilisateur ou null si non connecté
 */
if (!function_exists('getUserRole')) {
    function getUserRole() {
        $user = getCurrentUser();
        return $user['profil'] ?? null;
    }
}

/**
 * Vérifie si l'utilisateur est un administrateur
 * @return bool Vrai si l'utilisateur est un administrateur
 */
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return getUserRole() === USER_TYPE_ADMIN;
    }
}

/**
 * Vérifie si l'utilisateur est un professeur
 * @return bool Vrai si l'utilisateur est un professeur
 */
if (!function_exists('isTeacher')) {
    function isTeacher() {
        return getUserRole() === USER_TYPE_TEACHER;
    }
}

/**
 * Vérifie si l'utilisateur est un élève
 * @return bool Vrai si l'utilisateur est un élève
 */
if (!function_exists('isStudent')) {
    function isStudent() {
        return getUserRole() === USER_TYPE_STUDENT;
    }
}

/**
 * Vérifie si l'utilisateur est un parent
 * @return bool Vrai si l'utilisateur est un parent
 */
if (!function_exists('isParent')) {
    function isParent() {
        return getUserRole() === USER_TYPE_PARENT;
    }
}

/**
 * Vérifie si l'utilisateur est un membre de la vie scolaire
 * @return bool Vrai si l'utilisateur est un membre de la vie scolaire
 */
if (!function_exists('isVieScolaire')) {
    function isVieScolaire() {
        return getUserRole() === USER_TYPE_STAFF;
    }
}

/**
 * Vérifie si l'utilisateur peut gérer les notes
 * @return bool Vrai si l'utilisateur peut gérer les notes
 */
if (!function_exists('canManageNotes')) {
    function canManageNotes() {
        $role = getUserRole();
        return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
    }
}

/**
 * Vérifie si l'utilisateur peut gérer les absences
 * @return bool Vrai si l'utilisateur peut gérer les absences
 */
if (!function_exists('canManageAbsences')) {
    function canManageAbsences() {
        $role = getUserRole();
        return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
    }
}

/**
 * Vérifie si l'utilisateur peut gérer le cahier de textes
 * @return bool Vrai si l'utilisateur peut gérer le cahier de textes
 */
if (!function_exists('canManageCahierTextes')) {
    function canManageCahierTextes() {
        $role = getUserRole();
        return $role === USER_TYPE_TEACHER || $role === USER_TYPE_ADMIN || $role === USER_TYPE_STAFF;
    }
}

/**
 * Récupère le nom complet de l'utilisateur
 * @return string Nom complet de l'utilisateur ou chaîne vide si non connecté
 */
if (!function_exists('getUserFullName')) {
    function getUserFullName() {
        $user = getCurrentUser();
        if ($user) {
            return $user['prenom'] . ' ' . $user['nom'];
        }
        return '';
    }
}

/**
 * Force l'authentification et redirige si non connecté
 * @return array Données de l'utilisateur connecté
 */
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            header("Location: " . LOGIN_URL);
            exit;
        }
        return getCurrentUser();
    }
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
        return in_array($user['profil'] ?? '', ['administrateur', 'vie_scolaire']);
    }
}

/**
 * Pont d'authentification pour les modules Pronote
 * Ce fichier facilite la transition vers le système d'authentification centralisé
 * pour assurer la compatibilité avec les modules existants
 */

// Vérifier si le fichier d'authentification central est déjà inclus
if (defined('AUTH_CENTRAL_INCLUDED')) {
    return;
}

// Inclure le fichier d'authentification central
require_once __DIR__ . '/auth_central.php';

// Fonctions de compatibilité pour les anciens modules
if (!function_exists('checkAuth')) {
    /**
     * Vérifie l'authentification et redirige si nécessaire
     * - Compatible avec les anciens modules
     * 
     * @param string|null $redirectUrl URL de redirection si non authentifié
     * @return array Données de l'utilisateur connecté
     */
    function checkAuth($redirectUrl = null) {
        return requireLogin($redirectUrl);
    }
}

if (!function_exists('isProfesseur')) {
    /**
     * Vérifie si l'utilisateur est un professeur
     * - Compatible avec les anciens modules qui utilisent 'professeur' au lieu de 'teacher'
     * 
     * @return bool True si l'utilisateur est un professeur
     */
    function isProfesseur() {
        return isTeacher();
    }
}

if (!function_exists('isParentEleve')) {
    /**
     * Vérifie si l'utilisateur est un parent d'élève
     * - Compatible avec les anciens modules qui utilisent 'parent_eleve'
     * 
     * @return bool True si l'utilisateur est un parent
     */
    function isParentEleve() {
        return isParent();
    }
}

if (!function_exists('getBaseUrl')) {
    /**
     * Récupère l'URL de base configurée
     * - Compatibilité avec les anciens modules
     * 
     * @return string URL de base
     */
    function getBaseUrl() {
        return defined('BASE_URL') ? BASE_URL : '';
    }
}

if (!function_exists('getUserInitials')) {
    /**
     * Récupère les initiales de l'utilisateur
     * @return string Initiales de l'utilisateur
     */
    function getUserInitials() {
        $user = getCurrentUser();
        if ($user && isset($user['prenom']) && isset($user['nom'])) {
            return strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));
        }
        return '';
    }
}

if (!function_exists('canManageDevoirs') && function_exists('canManagerDevoirs')) {
    /**
     * Fonction correctement nommée pour la gestion des devoirs
     * Évite la confusion avec l'ancienne fonction mal orthographiée
     */
    function canManageDevoirs() {
        return canManagerDevoirs();
    }
} elseif (!function_exists('canManagerDevoirs') && function_exists('canManageDevoirs')) {
    /**
     * Alias pour maintenir la compatibilité avec le code existant
     */
    function canManagerDevoirs() {
        return canManageDevoirs();
    }
}