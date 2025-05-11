<?php
/**
 * Fonctions d'authentification
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

// Constantes de sécurité des sessions
define('SESSION_LIFETIME', 3600); // 1 heure
define('SESSION_REGENERATE_TIME', 300); // 5 minutes

/**
 * Initialise les paramètres de sécurité de session
 */
function initSessionSecurity() {
    // Régénérer périodiquement l'ID de session
    if (!isset($_SESSION['created_at']) || (time() - $_SESSION['created_at'] > SESSION_REGENERATE_TIME)) {
        regenerateSession();
    }
    
    // Vérifier l'expiration de la session
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
        // Session expirée
        session_destroy();
        return false;
    }
    
    // Mettre à jour l'horodatage d'activité
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Régénère la session en toute sécurité
 */
function regenerateSession() {
    // Sauvegarde des données importantes
    $old_user_data = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    
    // Régénérer l'ID de session
    session_regenerate_id(true);
    $_SESSION = array();
    
    // Restaurer les données importantes
    if ($old_user_data) {
        $_SESSION['user'] = $old_user_data;
    }
    
    // Mettre à jour l'horodatage
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    
    // Vérifier l'empreinte du navigateur (simplifiée)
    if (!isset($_SESSION['browser_fingerprint'])) {
        $_SESSION['browser_fingerprint'] = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool
 */
function isLoggedIn() {
    // Initialiser la sécurité des sessions
    if (!initSessionSecurity()) {
        return false;
    }
    
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    // Vérifier l'empreinte du navigateur
    if (isset($_SESSION['browser_fingerprint']) && 
        $_SESSION['browser_fingerprint'] !== md5($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        // Possible détournement de session
        session_destroy();
        return false;
    }
    
    return true;
}

/**
 * Vérifie l'authentification et retourne les infos utilisateur
 * @return array|false
 */
function checkAuth() {
    if (!isLoggedIn()) {
        return false;
    }

    $user = $_SESSION['user'];
    
    // Adaptation: utiliser 'profil' comme 'type' si 'type' n'existe pas
    if (!isset($user['type']) && isset($user['profil'])) {
        $user['type'] = $user['profil'];
        $_SESSION['user']['type'] = $user['profil'];
    }

    // Vérifier que le type est défini
    if (!isset($user['type'])) {
        return false;
    }
    
    return $user;
}

/**
 * Redirige vers la page de connexion si non authentifié
 * @param bool $ajaxCheck Vérifie si c'est une requête AJAX
 * @return array
 */
function requireAuth($ajaxCheck = false) {
    $user = checkAuth();
    
    if (!$user) {
        if ($ajaxCheck && isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Session expirée', 'redirect' => LOGIN_URL]);
            exit;
        }
        redirect(LOGIN_URL);
    }
    
    return $user;
}

/**
 * Vérifie si la requête est une requête AJAX
 * @return bool
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Vérifie si l'utilisateur peut envoyer des annonces
 * @param array $user
 * @return bool
 */
function canSendAnnouncement($user) {
    return in_array($user['type'], ['vie_scolaire', 'administrateur']);
}

/**
 * Vérifie si l'utilisateur est un professeur
 * @param array $user
 * @return bool
 */
function isProfesseur($user) {
    return $user['type'] === 'professeur';
}

/**
 * Vérifie si l'utilisateur a un rôle spécifique
 * @param array $user
 * @param array|string $roles
 * @return bool
 */
function hasRole($user, $roles) {
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['type'], $roles);
}

/**
 * Redirige si l'utilisateur n'a pas le rôle requis
 * @param array $user
 * @param array|string $roles
 * @param string $redirectUrl
 */
function requireRole($user, $roles, $redirectUrl = 'index.php') {
    if (!hasRole($user, $roles)) {
        redirect($redirectUrl);
    }
}