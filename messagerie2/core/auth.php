<?php
/**
 * Fonctions d'authentification
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user']);
}

/**
 * Vérifie l'authentification et retourne les infos utilisateur
 * @return array|false
 */
function checkAuth() {
    if (!isset($_SESSION['user'])) {
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
 * @return array
 */
function requireAuth() {
    $user = checkAuth();
    
    if (!$user) {
        redirect(LOGIN_URL);
    }
    
    return $user;
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