<?php
// config.php - Configuration générale de l'application
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Définition des chemins absolus
define('ROOT_PATH', dirname(__FILE__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DATA_PATH', ROOT_PATH . '/login/data');

// Inclusion des paramètres de connexion depuis le système de login
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../login/config/database.php';
}

// Connexion à la base de données avec les paramètres du système de login
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}

// Initialisation de la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fonction pour récupérer le chemin du fichier etablissement.json
function getEtablissementJsonPath() {
    // Chemin absolu vers login/data/etablissement.json
    $path = __DIR__ . '/../login/data/etablissement.json';
    
    if (!file_exists($path)) {
        die('Erreur: Le fichier etablissement.json est introuvable à l\'emplacement: ' . $path);
    }
    return $path;
}

// Fonction pour récupérer les données d'établissement
function getEtablissementData() {
    $jsonFile = getEtablissementJsonPath();
    $jsonData = file_get_contents($jsonFile);
    if ($jsonData === false) {
        return null;
    }
    return json_decode($jsonData, true);
}

// Fonctions utilitaires
function isAuthenticated() {
    return isset($_SESSION['user']);
}

// Fonctions pour le profil utilisateur à ajouter à config.php si elles ne sont pas définies

/**
 * Récupère le profil de l'utilisateur connecté
 * 
 * @return string|null Le profil utilisateur ou null si non connecté
 */
function getUserProfile() {
    return isset($_SESSION['user']) ? $_SESSION['user']['profil'] : null;
}

/**
 * Vérifie si l'utilisateur connecté est un enseignant ou un administrateur
 * 
 * @return bool True si l'utilisateur est un enseignant ou administrateur
 */
function isTeacher() {
    $profile = getUserProfile();
    return $profile === 'professeur' || $profile === 'administrateur';
}

/**
 * Vérifie si l'utilisateur connecté est un administrateur
 * 
 * @return bool True si l'utilisateur est un administrateur
 */
function isAdmin() {
    return getUserProfile() === 'administrateur';
}

/**
 * Récupère l'ID de l'utilisateur connecté
 * 
 * @return int|null L'ID de l'utilisateur ou null si non connecté
 */
function getUserId() {
    return isset($_SESSION['user']) ? $_SESSION['user']['id'] : null;
}

/**
 * Récupère le nom complet de l'utilisateur connecté
 * 
 * @return string Le nom complet de l'utilisateur ou chaîne vide si non connecté
 */
function getUserName() {
    if (!isset($_SESSION['user'])) return '';
    return $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
}

// Fonction pour formater les dates en français
function formatDateFr($date, $includeTime = false) {
    if (!$date) return '';
    
    $timestamp = strtotime($date);
    $format = 'l j F Y';
    if ($includeTime) {
        $format .= ' à H\hi';
    }
    
    // Traduction des jours et mois en français
    $jour = strftime('%A', $timestamp);
    $mois = strftime('%B', $timestamp);
    
    $jours_fr = [
        'Monday' => 'Lundi',
        'Tuesday' => 'Mardi',
        'Wednesday' => 'Mercredi',
        'Thursday' => 'Jeudi',
        'Friday' => 'Vendredi',
        'Saturday' => 'Samedi',
        'Sunday' => 'Dimanche'
    ];
    
    $mois_fr = [
        'January' => 'janvier',
        'February' => 'février',
        'March' => 'mars',
        'April' => 'avril',
        'May' => 'mai',
        'June' => 'juin',
        'July' => 'juillet',
        'August' => 'août',
        'September' => 'septembre',
        'October' => 'octobre',
        'November' => 'novembre',
        'December' => 'décembre'
    ];
    
    $formatted = date($format, $timestamp);
    
    // Remplacement des jours et mois en anglais par leur équivalent français
    foreach ($jours_fr as $en => $fr) {
        $formatted = str_replace($en, $fr, $formatted);
    }
    
    foreach ($mois_fr as $en => $fr) {
        $formatted = str_replace($en, $fr, $formatted);
    }
    
    return $formatted;
}

// Protection contre les injections XSS
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Redirection avec un message
function redirect($url, $message = '', $type = 'success') {
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header('Location: ' . $url);
    exit;
}