<?php
/**
 * Bootstrap pour l'application Pronote
 * Ce fichier charge toutes les dépendances et initialise l'application
 */

// Démarrer une session si ce n'est pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Charger la configuration
if (file_exists(__DIR__ . '/config/config.php')) {
    require_once __DIR__ . '/config/config.php';
}

// Si pdo n'est pas définie, créer une connexion à la base de données
if (!isset($pdo)) {
    // Définir des valeurs par défaut si les constantes ne sont pas définies
    $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
    $dbName = defined('DB_NAME') ? DB_NAME : 'db_MASSE';
    $dbUser = defined('DB_USER') ? DB_USER : '22405372';
    $dbPass = defined('DB_PASS') ? DB_PASS : '807014';
    
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        
        // Rendre la connexion disponible globalement
        $GLOBALS['pdo'] = $pdo;
        
        // Journaliser le succès de la connexion si on est en mode débogage
        if (defined('APP_ENV') && APP_ENV === 'development') {
            error_log("Connexion à la base de données établie avec succès dans bootstrap.php");
        }
    } catch (PDOException $e) {
        // Journaliser l'erreur
        error_log("Erreur de connexion à la base de données dans bootstrap.php: " . $e->getMessage());
    }
}

// Inclure les fonctions utilitaires
if (file_exists(__DIR__ . '/core/utils.php')) {
    require_once __DIR__ . '/core/utils.php';
}

// Charger le système d'authentification central
if (file_exists(__DIR__ . '/auth_central.php')) {
    require_once __DIR__ . '/auth_central.php';
} else {
    // Charger le système d'urgence
    if (file_exists(__DIR__ . '/compatibility.php')) {
        require_once __DIR__ . '/compatibility.php';
        ensure_auth_functions();
    }
}
