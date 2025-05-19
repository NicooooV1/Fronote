<?php
/**
 * Connexion à la base de données pour le module Notes
 * Ce fichier utilise la connexion centralisée à la base de données
 */

// Vérifier si l'objet PDO global existe déjà
if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    // Essayer d'utiliser la connexion centralisée
    $dbPath = __DIR__ . '/../../API/database.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
        $pdo = getDBConnection();
    } else {
        // Fallback si le fichier centralisé n'est pas disponible
        try {
            // Chemin vers le fichier de configuration
            $configPath = __DIR__ . '/../../API/config/config.php';
            if (file_exists($configPath)) {
                require_once $configPath;
            }
            
            // Récupération des constantes de configuration ou utilisation de valeurs par défaut
            $host = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbname = defined('DB_NAME') ? DB_NAME : 'pronote';
            $user = defined('DB_USER') ? DB_USER : 'root';
            $pass = defined('DB_PASS') ? DB_PASS : '';
            $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
            
            // Création de la connexion PDO
            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("Erreur de connexion à la base de données: " . $e->getMessage());
            die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
        }
    }
    
    // Stocker la connexion dans une variable globale pour la réutiliser
    $GLOBALS['pdo'] = $pdo;
} else {
    // Réutiliser la connexion existante
    $pdo = $GLOBALS['pdo'];
}
?>