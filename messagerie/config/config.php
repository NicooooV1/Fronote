<?php
// Chargement sécurisé des informations d'identification
$credentials = require_once __DIR__ . '/credentials.php';

// Configuration de la base de données
$host = $credentials['db_host'];
$db   = $credentials['db_name'];
$user = $credentials['db_user'];
$pass = $credentials['db_pass'];
$dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, // Désactiver l'émulation des requêtes préparées
];
$pdo = new PDO($dsn, $user, $pass, $options);

// Démarrer la session de manière sécurisée
session_start([
    'cookie_httponly' => true,     // Empêcher l'accès JS aux cookies
    'cookie_secure' => isset($_SERVER['HTTPS']), // Cookies uniquement sur HTTPS
    'cookie_samesite' => 'Lax',    // Protection contre CSRF
    'use_strict_mode' => true,     // Mode strict pour les sessions
    'gc_maxlifetime' => 3600       // Durée de vie de la session (1h)
]);

// Fonction pour vérifier si la connexion est HTTPS
function isSecureConnection() {
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
}

// Forcer HTTPS en production
if (!isSecureConnection() && getenv('ENVIRONMENT') === 'production') {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirect);
    exit;
}