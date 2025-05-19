<?php
/**
 * Système centralisé d'authentification et d'autorisation pour Pronote
 * Ce fichier est le point d'entrée pour toutes les fonctions d'authentification
 * et d'autorisation utilisées dans l'application.
 */

// Démarrer la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Constantes pour les rôles - vérifier si elles sont déjà définies
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool True si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * Récupère les informations de l'utilisateur connecté
 * @return array|null Tableau des informations utilisateur ou null
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Récupère le rôle de l'utilisateur courant
 * @return string|null Rôle de l'utilisateur ou null
 */
function getUserRole() {
    $user = getCurrentUser();
    return $user ? $user['profil'] : null;
}

/**
 * Récupère le nom complet de l'utilisateur
 * @return string Nom complet de l'utilisateur ou chaîne vide
 */
function getUserFullName() {
    $user = getCurrentUser();
    return $user ? $user['prenom'] . ' ' . $user['nom'] : '';
}

/**
 * Récupère les initiales de l'utilisateur
 * @return string Initiales de l'utilisateur ou chaîne vide
 */
function getUserInitials() {
    $user = getCurrentUser();
    if (!$user) {
        return '';
    }
    return strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));
}

/**
 * Vérifie si l'utilisateur est un administrateur
 * @return bool True si l'utilisateur est un administrateur
 */
function isAdmin() {
    return getUserRole() === USER_TYPE_ADMIN;
}

/**
 * Vérifie si l'utilisateur est un professeur
 * @return bool True si l'utilisateur est un professeur
 */
function isTeacher() {
    return getUserRole() === USER_TYPE_TEACHER;
}

/**
 * Vérifie si l'utilisateur est un élève
 * @return bool True si l'utilisateur est un élève
 */
function isStudent() {
    return getUserRole() === USER_TYPE_STUDENT;
}

/**
 * Vérifie si l'utilisateur est un parent
 * @return bool True si l'utilisateur est un parent
 */
function isParent() {
    return getUserRole() === USER_TYPE_PARENT;
}

/**
 * Vérifie si l'utilisateur fait partie de la vie scolaire
 * @return bool True si l'utilisateur fait partie de la vie scolaire
 */
function isVieScolaire() {
    return getUserRole() === USER_TYPE_STAFF;
}

/**
 * Vérifie si l'utilisateur peut gérer les absences
 * @return bool True si l'utilisateur peut gérer les absences
 */
function canManageAbsences() {
    return in_array(getUserRole(), [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Vérifie si l'utilisateur peut gérer les notes
 * @return bool True si l'utilisateur peut gérer les notes
 */
function canManageNotes() {
    return in_array(getUserRole(), [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Vérifie si l'utilisateur peut gérer les devoirs
 * @return bool True si l'utilisateur peut gérer les devoirs
 */
function canManageDevoirs() {
    return in_array(getUserRole(), [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
}

/**
 * Force l'utilisateur à être connecté, redirige sinon
 * @return array|null Informations de l'utilisateur connecté ou null après redirection
 */
function requireLogin() {
    if (!isLoggedIn()) {
        // Déterminer l'URL de redirection
        $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : '../login/public/index.php';
        
        // Journaliser la tentative d'accès non autorisé
        error_log('Tentative d\'accès non autorisé: redirection vers ' . $loginUrl);
        
        // Rediriger vers la page de connexion
        header('Location: ' . $loginUrl);
        exit;
    }
    return getCurrentUser();
}

/**
 * Vérifie si l'utilisateur a un rôle spécifique, redirige sinon
 * @param string|array $roles Rôle(s) autorisé(s)
 * @return bool True si l'utilisateur a le rôle requis
 */
function requireRole($roles) {
    requireLogin();
    
    $userRole = getUserRole();
    $roles = is_array($roles) ? $roles : [$roles];
    
    if (!in_array($userRole, $roles)) {
        // Journaliser la tentative d'accès non autorisé
        error_log('Accès refusé: rôle ' . $userRole . ' non autorisé');
        
        // Rediriger vers une page d'erreur ou d'accueil
        $homeUrl = defined('HOME_URL') ? HOME_URL : '../accueil/accueil.php';
        header('Location: ' . $homeUrl . '?error=unauthorized');
        exit;
    }
    
    return true;
}

/**
 * Détermine si l'utilisateur est professeur principal d'une classe
 * @param string $classe Classe à vérifier
 * @return bool True si l'utilisateur est professeur principal de la classe
 */
function isProfesseurPrincipal($classe = null) {
    if (!isTeacher()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!isset($user['professeur_principal']) || !$user['professeur_principal']) {
        return false;
    }
    
    // Si aucune classe n'est spécifiée, vérifier si l'utilisateur est professeur principal de n'importe quelle classe
    if ($classe === null) {
        return true;
    }
    
    // Si une classe est spécifiée, vérifier si l'utilisateur est professeur principal de cette classe
    // Cette vérification nécessite une requête à la base de données dans un cas réel
    // Pour l'instant, on suppose que c'est le cas si l'utilisateur est professeur principal
    return true;
}

/**
 * Déconnecte l'utilisateur courant
 */
function logout() {
    // Détruire toutes les variables de session
    $_SESSION = [];
    
    // Si un cookie de session est utilisé, le détruire
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Détruire la session
    session_destroy();
}

/**
 * Vérifie si la création de comptes administrateur est autorisée
 * @return bool True si la création de comptes administrateur est autorisée
 */
function isAdminCreationAllowed() {
    $adminLockFile = __DIR__ . '/../admin.lock';
    return !file_exists($adminLockFile);
}
