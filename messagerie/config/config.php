<?php
// config/database.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'db_MASSE');
define('DB_USER', '22405372');  // À modifier selon votre configuration
define('DB_PASS', '807014');    // À modifier selon votre configuration

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Erreur de connexion à la base : ' . $e->getMessage());
}