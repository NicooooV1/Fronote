<?php
/**
 * Script de déconnexion pour Pronote
 * Déconnecte l'utilisateur et redirige vers la page de connexion
 */

// Inclure le système d'authentification central
require_once __DIR__ . '/../../API/auth_central.php';

// Utiliser la fonction de déconnexion du système d'authentification central
logout(LOGIN_URL);

// Code qui ne sera jamais exécuté à cause du exit dans logout()
// Mais en cas de problème avec le système d'authentification central, voici un fallback
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

// Rediriger vers la page de connexion
$loginUrl = '../login/public/index.php';
if (defined('LOGIN_URL')) {
    $loginUrl = LOGIN_URL;
}
header("Location: " . $loginUrl);
exit;