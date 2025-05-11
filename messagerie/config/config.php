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

// Démarrer la session de manière sécurisée avec paramètres améliorés
$sessionParams = [
    'cookie_httponly' => true,     // Empêcher l'accès JS aux cookies
    'cookie_secure' => isset($_SERVER['HTTPS']), // Cookies uniquement sur HTTPS
    'cookie_samesite' => 'Lax',    // Protection contre CSRF
    'use_strict_mode' => true,     // Mode strict pour les sessions
    'gc_maxlifetime' => 7200,      // Durée de vie de la session (2h)
    'sid_length' => 48,            // Longueur plus sécurisée de l'ID de session
    'sid_bits_per_character' => 6, // Plus d'entropie dans l'ID de session
];

session_start($sessionParams);

// Définir des en-têtes de sécurité
if (!headers_sent()) {
    // Protection contre le clickjacking
    header('X-Frame-Options: DENY');
    // Protection XSS
    header('X-XSS-Protection: 1; mode=block');
    // Empêcher le MIME-sniffing
    header('X-Content-Type-Options: nosniff');
    // Référence seulement en interne
    header('Referrer-Policy: same-origin');
    
    // En production uniquement
    if (getenv('ENVIRONMENT') === 'production') {
        // Politique de sécurité du contenu (CSP)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com;");
    }
}

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