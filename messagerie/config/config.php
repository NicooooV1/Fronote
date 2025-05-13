<?php
// Configuration de la base de données
$host = 'localhost';
$db   = 'db_MASSE';
$user = '22405372';
$pass = '807014';
$dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Enregistrer l'erreur dans un fichier de log au lieu d'afficher
    file_put_contents(__DIR__ . '/../logs/db_error.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    die("Une erreur s'est produite lors de la connexion à la base de données. Veuillez réessayer plus tard.");
}

session_start();