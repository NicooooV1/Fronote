<?php
/**
 * Configuration d'environnement
 * Ce fichier devrait être différent pour chaque environnement
 */

// Base URL de l'application
define('BASE_URL', '/~u22405372/SAE/Pronote');

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_MASSE');
define('DB_USER', '22405372');
define('DB_PASS', '807014');

// Environnement (development, production, test)
define('APP_ENV', 'development');

// Chemin racine de l'application
define('APP_ROOT', realpath(dirname(__FILE__) . '/../../'));
