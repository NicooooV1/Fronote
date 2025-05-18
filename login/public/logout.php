<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/auth.php';

/**
 * Script de déconnexion
 * Ce script détruit la session et redirige vers la page de connexion
 */

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Sauvegarder le message de redirection si nécessaire
$redirect_message = isset($_SESSION['flash']['warning']) ? $_SESSION['flash']['warning'] : [];

// Détruire toutes les données de session
$_SESSION = [];

// Réinitialiser les messages flash si nécessaire
if (!empty($redirect_message)) {
    $_SESSION['flash']['warning'] = $redirect_message;
}

// Détruire le cookie de session si utilisé
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

// Définir les URLs de base
$base_url = '/~u22405372/SAE/Pronote';
$loginUrl = $base_url . '/login/public/index.php';

// Rediriger vers la page de connexion
header("Location: $loginUrl");
exit;