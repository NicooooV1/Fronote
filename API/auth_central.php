<?php
/**
 * Système d'authentification centralisé pour Pronote
 * Ce fichier fournit toutes les fonctions d'authentification pour les différents modules
 */

// Démarrer automatique de la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Marquer le fichier comme inclus
if (!defined('AUTH_CENTRAL_INCLUDED')) {
    define('AUTH_CENTRAL_INCLUDED', true);
}

// Charger les dépendances nécessaires
$configPath = __DIR__ . '/config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

// Charger le fichier de sécurité
$securityPath = __DIR__ . '/core/Security.php';
if (file_exists($securityPath)) {
    require_once $securityPath;
}

// Constantes de rôles utilisateurs
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// Vérifier si les fonctions existent déjà pour éviter les redéclarations
if (!function_exists('isLoggedIn')) {
    /**
     * Vérifie si l'utilisateur est connecté
     * @return bool True si l'utilisateur est connecté
     */
    function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']) && isset($_SESSION['auth_time']);
    }
}

if (!function_exists('isSessionExpired')) {
    /**
     * Vérifier si la session a expiré
     * @param int $timeout Timeout en secondes (par défaut 7200 = 2 heures)
     * @return bool True si la session a expiré
     */
    function isSessionExpired($timeout = SESSION_LIFETIME ?? 7200) {
        if (!isset($_SESSION['auth_time'])) {
            return true;
        }
        
        return (time() - $_SESSION['auth_time']) > $timeout;
    }
}

if (!function_exists('refreshAuthTime')) {
    /**
     * Rafraîchir le timestamp d'authentification
     * @return void
     */
    function refreshAuthTime() {
        $_SESSION['auth_time'] = time();
    }
}

if (!function_exists('getCurrentUser')) {
    /**
     * Récupérer l'utilisateur connecté
     * @return array|null Données de l'utilisateur ou null si non connecté
     */
    function getCurrentUser() {
        if (!isLoggedIn() || isSessionExpired()) {
            return null;
        }
        
        refreshAuthTime();
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('getUserRole')) {
    /**
     * Récupère le rôle de l'utilisateur actuel
     * @return string|null Rôle de l'utilisateur ou null si non connecté
     */
    function getUserRole() {
        $user = getCurrentUser();
        return $user ? ($user['profil'] ?? null) : null;
    }
}

if (!function_exists('getUserFullName')) {
    /**
     * Récupère le nom complet de l'utilisateur
     * @return string Nom complet de l'utilisateur ou chaîne vide
     */
    function getUserFullName() {
        $user = getCurrentUser();
        if ($user && isset($user['nom']) && isset($user['prenom'])) {
            return $user['prenom'] . ' ' . $user['nom'];
        }
        return '';
    }
}

if (!function_exists('isAdmin')) {
    /**
     * Vérifie si l'utilisateur est un administrateur
     * @return bool True si l'utilisateur est un administrateur
     */
    function isAdmin() {
        return getUserRole() === USER_TYPE_ADMIN;
    }
}

if (!function_exists('isTeacher')) {
    /**
     * Vérifie si l'utilisateur est un professeur
     * @return bool True si l'utilisateur est un professeur
     */
    function isTeacher() {
        return getUserRole() === USER_TYPE_TEACHER;
    }
}

if (!function_exists('isStudent')) {
    /**
     * Vérifie si l'utilisateur est un élève
     * @return bool True si l'utilisateur est un élève
     */
    function isStudent() {
        return getUserRole() === USER_TYPE_STUDENT;
    }
}

if (!function_exists('isParent')) {
    /**
     * Vérifie si l'utilisateur est un parent
     * @return bool True si l'utilisateur est un parent
     */
    function isParent() {
        return getUserRole() === USER_TYPE_PARENT;
    }
}

if (!function_exists('isVieScolaire')) {
    /**
     * Vérifie si l'utilisateur est un membre de la vie scolaire
     * @return bool True si l'utilisateur est un membre de la vie scolaire
     */
    function isVieScolaire() {
        return getUserRole() === USER_TYPE_STAFF;
    }
}

if (!function_exists('canManageNotes')) {
    /**
     * Vérifie si l'utilisateur peut gérer les notes
     * @return bool True si l'utilisateur peut gérer les notes
     */
    function canManageNotes() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canManageAbsences')) {
    /**
     * Vérifie si l'utilisateur peut gérer les absences
     * @return bool True si l'utilisateur peut gérer les absences
     */
    function canManageAbsences() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canManageDevoirs')) {
    /**
     * Vérifie si l'utilisateur peut gérer les devoirs
     * @return bool True si l'utilisateur peut gérer les devoirs
     */
    function canManageDevoirs() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('requireLogin')) {
    /**
     * Force l'authentification et redirige si non connecté
     * @param string|null $redirectUrl URL de redirection en cas de non authentification
     * @return array Données de l'utilisateur connecté
     */
    function requireLogin($redirectUrl = null) {
        if (!isLoggedIn() || isSessionExpired()) {
            $url = $redirectUrl ?? (defined('LOGIN_URL') ? LOGIN_URL : '/login/public/index.php');
            header("Location: $url");
            exit;
        }
        
        refreshAuthTime();
        return $_SESSION['user'];
    }
}

if (!function_exists('logout')) {
    /**
     * Déconnecte l'utilisateur
     * @param string|null $redirectUrl URL de redirection après déconnexion
     * @return void
     */
    function logout($redirectUrl = null) {
        // Détruire toutes les données de session
        $_SESSION = [];
        
        // Détruire le cookie de session si nécessaire
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Détruire la session
        session_destroy();
        
        // Redirection
        if ($redirectUrl) {
            header("Location: $redirectUrl");
        } else if (defined('LOGIN_URL')) {
            header("Location: " . LOGIN_URL);
        } else {
            header("Location: /login/public/index.php");
        }
        exit;
    }
}

// Créer un bridge pour assurer la compatibilité avec le code existant
// Cette ligne doit être après toutes les définitions de fonctions principales
if (file_exists(__DIR__ . '/auth_bridge.php')) {
    include_once __DIR__ . '/auth_bridge.php';
}
