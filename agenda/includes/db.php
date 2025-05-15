<?php
/**
 * Connexion à la base de données pour le module agenda
 */

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Paramètres de connexion à la base de données
// Idéalement, ces paramètres devraient être les mêmes que ceux utilisés dans le reste de l'application
// Nous les définissons ici pour rendre le module autonome
$db_host = 'localhost';  // Serveur de base de données
$db_name = 'u22405372';  // Nom de la base de données (généralement le nom d'utilisateur sur un hébergement universitaire)
$db_user = 'u22405372';  // Utilisateur de la base de données
$db_pass = 'u22405372';  // Mot de passe (généralement le même que le nom d'utilisateur sur un hébergement universitaire)

// Essayer de réutiliser les paramètres du fichier de configuration principal
$config_file = __DIR__ . '/../../login/config/database.php';
if (file_exists($config_file)) {
    include_once $config_file;
    // Si les constantes sont définies dans ce fichier, les utiliser
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_pass = DB_PASS;
    }
}

// Options PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Créer la connexion PDO
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        $options
    );
} catch (PDOException $e) {
    // En cas d'erreur, afficher un message et arrêter le script
    die('Erreur de connexion à la base de données: ' . $e->getMessage());
}
?>