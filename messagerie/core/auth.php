<?php
/**
 * Authentication functions for messagerie module
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once API_AUTH_PATH;

// Local helper functions that don't conflict with central API
if (!function_exists('checkAuth')) {
    /**
     * Check authentication and return user info
     * @return array|false
     */
    function checkAuth() {
        $user = getCurrentUser();
        if (!$user) {
            return false;
        }
        
        // Adapt: use 'profil' as 'type' if 'type' doesn't exist
        if (!isset($user['type']) && isset($user['profil'])) {
            $user['type'] = $user['profil'];
            $_SESSION['user']['type'] = $user['profil'];
        }

        // Check that type is defined
        if (!isset($user['type'])) {
            return false;
        }
        
        return $user;
    }
}

if (!function_exists('requireAuth')) {
    /**
     * Require authentication or redirect to login
     */
    function requireAuth() {
        $user = checkAuth();
        if (!$user) {
            redirect(LOGIN_URL);
        }
        return $user;
    }
}

if (!function_exists('canSendAnnouncement')) {
    /**
     * Vérifie si l'utilisateur peut envoyer des annonces
     * @param array $user
     * @return bool
     */
    function canSendAnnouncement($user) {
        return in_array($user['type'], ['vie_scolaire', 'administrateur']);
    }
}

if (!function_exists('isProfesseur')) {
    /**
     * Vérifie si l'utilisateur est un professeur
     * @param array $user
     * @return bool
     */
    function isProfesseur($user) {
        return $user['type'] === 'professeur';
    }
}

if (!function_exists('hasRole')) {
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
}

if (!function_exists('requireRole')) {
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
}
?>