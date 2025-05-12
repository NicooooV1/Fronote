<?php
/**
 * Fichier commun pour l'intégration du système de login avec l'application
 * À placer à la racine du projet ou dans un dossier commun
 */

// Définir le chemin racine du projet
define('ROOT_PATH', dirname(__FILE__));

// Charger les constantes communes
require_once ROOT_PATH . '/devoir/includes/constants.php';

// Charger les classes essentielles
require_once ROOT_PATH . '/login/src/auth.php';
require_once ROOT_PATH . '/login/src/user.php'; 
require_once ROOT_PATH . '/devoir/config/database.php';

// Établir la correspondance entre les types d'utilisateurs
$USER_TYPE_MAP = [
    'eleve' => TYPE_ELEVE,
    'parent' => TYPE_PARENT,
    'professeur' => TYPE_PROFESSEUR,
    'administrateur' => TYPE_ADMIN,
    'vie_scolaire' => TYPE_VIE_SCOLAIRE
];

// Initialiser la base de données
$db = Database::getInstance();

// Initialiser l'authentification
$auth = new Auth($db->getPDO());

/**
 * Vérifie si l'utilisateur a un ou plusieurs rôles
 * @param string|array $roles Un rôle ou un tableau de rôles (format interne de l'application)
 * @return bool Vrai si l'utilisateur a au moins un des rôles spécifiés
 */
function checkRole($roles) {
    global $auth, $USER_TYPE_MAP;
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    // Convertir les types internes en types du système de login
    $loginRoles = [];
    foreach ($roles as $role) {
        // Trouver la clé correspondant à la valeur
        $loginRole = array_search($role, $USER_TYPE_MAP);
        if ($loginRole !== false) {
            $loginRoles[] = $loginRole;
        }
    }
    
    return $auth->hasRole($loginRoles);
}

/**
 * Vérifie si l'utilisateur est connecté, redirige vers la page de login sinon
 */
function requireAuthentication() {
    global $auth;
    $auth->requireLogin();
}

/**
 * Synchronise les informations de session entre le système de login et l'application
 * Permet de maintenir la compatibilité avec le code existant
 */
function syncSessionData() {
    if (isset($_SESSION['user'])) {
        $_SESSION['user_id'] = $_SESSION['user']['id'];
        $_SESSION['username'] = $_SESSION['user']['identifiant'] ?? ($_SESSION['user']['nom'] . '.' . $_SESSION['user']['prenom']);
        $_SESSION['user_type'] = array_search($_SESSION['user']['profil'], $GLOBALS['USER_TYPE_MAP']) ?: $_SESSION['user']['profil'];
        $_SESSION['user_fullname'] = $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
        $_SESSION['is_admin'] = ($_SESSION['user']['profil'] === 'administrateur');
    }
}

// Synchroniser les données de session au chargement du fichier
if (isset($_SESSION['user'])) {
    syncSessionData();
}