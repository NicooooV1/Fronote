<?php
/**
 * Script de déconnexion pour Pronote
 * Déconnecte l'utilisateur et redirige vers la page de connexion
 */

// Inclure le système d'authentification central
require_once __DIR__ . '/../../API/auth_central.php';

// Utiliser la fonction de déconnexion du système d'authentification central
if (function_exists('logout')) {
    logout();
}

// Code qui sera exécuté si la fonction logout() n'existe pas ou ne termine pas l'exécution
// Déconnexion manuelle
session_start();
$_SESSION = [];

// Détruire le cookie de session
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

// Rediriger vers la page de connexion - FIX: removed duplicate 'login' in the path
$loginUrl = '../public/index.php';
if (defined('LOGIN_URL')) {
    $loginUrl = LOGIN_URL;
}

header("Location: $loginUrl");
exit;