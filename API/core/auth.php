<?php
/**
 * Système d'authentification centralisé
 */
namespace Pronote\Auth;

// Définir les constantes si elles ne sont pas déjà définies
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

/**
 * Vérifier si l'utilisateur est connecté
 * @return bool True si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * Vérifier si la session a expiré
 * @param int $timeout Timeout en secondes
 * @return bool True si la session a expiré
 */
function isSessionExpired($timeout = 7200) {
    if (!isset($_SESSION['auth_time'])) {
        return true;
    }
    
    return time() - $_SESSION['auth_time'] > $timeout;
}

/**
 * Rafraîchir le timestamp d'authentification
 */
function refreshAuthTime() {
    $_SESSION['auth_time'] = time();
}

/**
 * Récupère l'utilisateur connecté
 * @return array|null Données de l'utilisateur ou null
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Récupère le rôle de l'utilisateur
 * @return string|null Rôle de l'utilisateur ou null
 */
function getUserRole() {
    $user = getCurrentUser();
    return $user ? $user['profil'] : null;
}

/**
 * Vérifier si l'utilisateur est administrateur
 * @return bool True si l'utilisateur est administrateur
 */
function isAdmin() {
    return getUserRole() === USER_TYPE_ADMIN;
}

/**
 * Vérifier si l'utilisateur est professeur
 * @return bool True si l'utilisateur est professeur
 */
function isTeacher() {
    return getUserRole() === USER_TYPE_TEACHER;
}

/**
 * Vérifier si l'utilisateur est élève
 * @return bool True si l'utilisateur est élève
 */
function isStudent() {
    return getUserRole() === USER_TYPE_STUDENT;
}

/**
 * Vérifier si l'utilisateur est parent
 * @return bool True si l'utilisateur est parent
 */
function isParent() {
    return getUserRole() === USER_TYPE_PARENT;
}

/**
 * Vérifier si l'utilisateur est membre de la vie scolaire
 * @return bool True si l'utilisateur est membre de la vie scolaire
 */
function isVieScolaire() {
    return getUserRole() === USER_TYPE_STAFF;
}

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

/**
 * Vérifie si l'utilisateur peut gérer les notes
 * @return bool True si l'utilisateur peut gérer les notes
 */
function canManageNotes() {
    $role = getUserRole();
    return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Vérifie si l'utilisateur peut gérer les absences
 * @return bool True si l'utilisateur peut gérer les absences
 */
function canManageAbsences() {
    $role = getUserRole();
    return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Vérifie si l'utilisateur peut gérer le cahier de textes
 * @return bool True si l'utilisateur peut gérer le cahier de textes
 */
function canManageCahierTextes() {
    $role = getUserRole();
    return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Vérifie si l'utilisateur peut gérer les devoirs
 * @return bool True si l'utilisateur peut gérer les devoirs
 */
function canManageDevoirs() {
    $role = getUserRole();
    return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Force l'authentification et redirige si non connecté
 * @return array Données de l'utilisateur connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        // Définir les URLs de redirection si nécessaire
        if (!defined('BASE_URL')) {
            define('BASE_URL', '/~u22405372/SAE/Pronote');
        }
        
        if (!defined('LOGIN_URL')) {
            define('LOGIN_URL', BASE_URL . '/login/public/index.php');
        }
        
        header("Location: " . LOGIN_URL);
        exit;
    }
    
    // Vérifier si la session a expiré
    if (isSessionExpired()) {
        // Stocker l'URL actuelle pour y revenir après la reconnexion
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
        
        // Ajouter un message flash si la fonction existe
        if (function_exists('\\Pronote\\Session\\setFlash')) {
            \Pronote\Session\setFlash('warning', 'Votre session a expiré. Veuillez vous reconnecter.');
        }
        
        // Définir l'URL de logout si nécessaire
        if (!defined('LOGOUT_URL')) {
            define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
        }
        
        header("Location: " . LOGOUT_URL);
        exit;
    }
    
    // Rafraîchir le timestamp d'authentification
    refreshAuthTime();
    
    return getCurrentUser();
}
