<?php
/**
 * Gestion centralisée de l'authentification
 */

// Inclure le bootstrap
require_once __DIR__ . '/bootstrap.php';

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return Session::has('user') && Session::has('auth_time');
}

// Vérifier si la session a expiré
function isSessionExpired($timeout = 7200) {
    if (!Session::has('auth_time')) {
        return true;
    }
    
    return time() - Session::get('auth_time') > $timeout;
}

// Mettre à jour le timestamp d'authentification
function refreshAuthTime() {
    Session::set('auth_time', time());
}

// Récupérer l'utilisateur connecté
function getCurrentUser() {
    return Session::get('user');
}

// Récupérer le rôle de l'utilisateur
function getUserRole() {
    $user = getCurrentUser();
    return $user ? $user['profil'] : null;
}

// Vérifier si l'utilisateur est administrateur
function isAdmin() {
    return getUserRole() === USER_TYPE_ADMIN;
}

// Vérifier si l'utilisateur est professeur
function isTeacher() {
    return getUserRole() === USER_TYPE_TEACHER;
}

// Vérifier si l'utilisateur est élève
function isStudent() {
    return getUserRole() === USER_TYPE_STUDENT;
}

// Vérifier si l'utilisateur est parent
function isParent() {
    return getUserRole() === USER_TYPE_PARENT;
}

// Vérifier si l'utilisateur est membre de la vie scolaire
function isVieScolaire() {
    return getUserRole() === USER_TYPE_STAFF;
}

// Récupérer le nom complet de l'utilisateur
function getUserFullName() {
    $user = getCurrentUser();
    if ($user) {
        return $user['prenom'] . ' ' . $user['nom'];
    }
    return '';
}

// Vérifier si l'utilisateur peut gérer les notes
function canManageNotes() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

// Déconnecter l'utilisateur
function logout() {
    Session::destroy();
    
    // Utiliser le chemin BASE_URL complet pour la redirection
    $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : BASE_URL . '/login/public/index.php';
    
    // S'assurer que l'URL commence par le bon chemin
    if (strpos($loginUrl, '/~') !== 0 && strpos($loginUrl, 'http') !== 0) {
        $loginUrl = BASE_URL . $loginUrl;
    }
    
    header('Location: ' . $loginUrl);
    exit;
}

// Rediriger si l'utilisateur n'est pas connecté
function requireLogin() {
    if (!isLoggedIn()) {
        // Utiliser le chemin BASE_URL complet pour la redirection
        $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : BASE_URL . '/login/public/index.php';
        
        // S'assurer que l'URL commence par le bon chemin
        if (strpos($loginUrl, '/~') !== 0 && strpos($loginUrl, 'http') !== 0) {
            $loginUrl = BASE_URL . $loginUrl;
        }
        
        header('Location: ' . $loginUrl);
        exit;
    }
    
    if (isSessionExpired()) {
        Session::set('login_redirect', $_SERVER['REQUEST_URI']);
        Session::setFlash('warning', 'Votre session a expiré. Veuillez vous reconnecter.');
        logout();
    }
    
    // Rafraîchir le temps d'authentification
    refreshAuthTime();
    
    return getCurrentUser();
}
?>
