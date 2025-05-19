<?php
/**
 * Gestion de la connexion à la base de données pour Pronote
 * Ce fichier centralise la connexion à la base de données
 */

// Inclusion de la configuration
require_once __DIR__ . '/config/config.php';

/**
 * Crée une connexion PDO à la base de données
 * @return PDO Instance de connexion PDO
 */
function getDBConnection() {
    static $pdo = null;
    
    // Si la connexion existe déjà, la réutiliser
    if ($pdo !== null) {
        return $pdo;
    }
    
    try {
        // Récupération des constantes de configuration
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $dbname = defined('DB_NAME') ? DB_NAME : '';
        $user = defined('DB_USER') ? DB_USER : '';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        
        // Création du DSN
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        
        // Options de connexion
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset"
        ];
        
        // Création de la connexion PDO
        $pdo = new PDO($dsn, $user, $pass, $options);
        
        return $pdo;
    } catch (PDOException $e) {
        // Journaliser l'erreur
        error_log("Erreur de connexion à la base de données: " . $e->getMessage());
        
        // En développement, afficher l'erreur
        if (defined('APP_ENV') && APP_ENV === 'development') {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        } else {
            // En production, afficher un message générique
            die("Une erreur est survenue lors de la connexion à la base de données.");
        }
    }
}
