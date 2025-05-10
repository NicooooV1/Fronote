<?php
// /includes/auth.php - Fonctions d'authentification

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Vérifie si l'utilisateur est connecté (alias pour compatibilité)
 * @return bool True si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user']);
}

/**
 * Vérifie l'authentification de l'utilisateur
 * @return array|false Informations sur l'utilisateur ou false si non authentifié
 */
function checkAuth() {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    $user = $_SESSION['user'];
    
    // Adaptation: utiliser 'profil' comme 'type' si 'type' n'existe pas
    if (!isset($user['type']) && isset($user['profil'])) {
        $user['type'] = $user['profil'];
        $_SESSION['user']['type'] = $user['profil']; // Mise à jour en session
    }

    // Vérifier que le type est défini
    if (!isset($user['type'])) {
        return false;
    }
    
    return $user;
}

/**
 * Redirige vers la page de connexion si non authentifié
 * @return array Informations sur l'utilisateur
 */
function requireAuth() {
    $user = checkAuth();
    
    if (!$user) {
        header('Location: ' . LOGIN_URL);
        exit;
    }
    
    return $user;
}

/**
 * Vérifie si l'utilisateur a un rôle spécifique
 * @param array $user Informations sur l'utilisateur
 * @param array $roles Rôles autorisés
 * @return bool True si l'utilisateur a un des rôles spécifiés
 */
function hasRole($user, $roles) {
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['type'], $roles);
}

/**
 * Vérifie si l'utilisateur est un administrateur
 * @param array $user Informations sur l'utilisateur
 * @return bool True si l'utilisateur est un administrateur
 */
function isAdmin($user) {
    return $user['type'] === 'administrateur';
}

/**
 * Vérifie si l'utilisateur est un professeur
 * @param array $user Informations sur l'utilisateur
 * @return bool True si l'utilisateur est un professeur
 */
function isProfesseur($user) {
    return $user['type'] === 'professeur';
}

/**
 * Vérifie si l'utilisateur peut envoyer des annonces
 * @param array $user Informations sur l'utilisateur
 * @return bool True si l'utilisateur peut envoyer des annonces
 */
function canSendAnnouncement($user) {
    return in_array($user['type'], ['vie_scolaire', 'administrateur']);
}

/**
 * Redirige si l'utilisateur n'a pas le rôle requis
 * @param array $user Informations sur l'utilisateur
 * @param array $roles Rôles autorisés
 * @param string $redirectUrl URL de redirection si non autorisé
 */
function requireRole($user, $roles, $redirectUrl = 'index.php') {
    if (!hasRole($user, $roles)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}