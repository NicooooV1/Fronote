<?php
// config.php - Configuration générale de l'application
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Définition des chemins absolus
define('ROOT_PATH', dirname(__FILE__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DATA_PATH', ROOT_PATH . '/login/data');

// Connexion à la base de données
try {
    $pdo = new PDO('mysql:host=localhost;dbname=pronote_devoirs;charset=utf8', 'user', 'password', [
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
    $path = DATA_PATH . '/etablissement.json';
    if (!file_exists($path)) {
        die('Erreur: Le fichier etablissement.json est introuvable.');
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

function getUserProfile() {
    return isset($_SESSION['user']) ? $_SESSION['user']['profil'] : null;
}

function isTeacher() {
    $profile = getUserProfile();
    return $profile === 'professeur' || $profile === 'administrateur';
}

function isAdmin() {
    return getUserProfile() === 'administrateur';
}

function getUserId() {
    return isset($_SESSION['user']) ? $_SESSION['user']['id'] : null;
}

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