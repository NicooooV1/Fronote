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
    // Log l'erreur et affiche un message plus convivial
    error_log('Erreur de connexion à la base de données : ' . $e->getMessage());
    
    // En mode développement, on peut afficher l'erreur
    if (isset($_SERVER['APPLICATION_ENV']) && $_SERVER['APPLICATION_ENV'] === 'development') {
        die('Erreur de connexion à la base de données : ' . $e->getMessage());
    } else {
        die('Erreur de connexion à la base de données. Veuillez contacter l\'administrateur.');
    }
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
        error_log('Erreur: Le fichier etablissement.json est introuvable à l\'emplacement: ' . $path);
        
        // Tenter de chercher dans d'autres emplacements possibles
        $alternatives = [
            __DIR__ . '/login/data/etablissement.json',
            __DIR__ . '/data/etablissement.json'
        ];
        
        foreach ($alternatives as $altPath) {
            if (file_exists($altPath)) {
                return $altPath;
            }
        }
        
        // Si le fichier n'existe nulle part, retourner un tableau vide plutôt que de planter
        return null;
    }
    return $path;
}

// Fonction pour récupérer les données d'établissement
function getEtablissementData() {
    $jsonFile = getEtablissementJsonPath();
    
    // Si le fichier n'existe pas, retourner un tableau par défaut
    if ($jsonFile === null) {
        return [
            'matieres' => [
                ['nom' => 'Français'],
                ['nom' => 'Mathématiques'],
                ['nom' => 'Histoire-Géographie']
            ],
            'classes' => [
                'Collège' => [
                    'Cycle 4' => ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B']
                ],
                'Lycée' => [
                    'Cycle Terminal' => ['2A', '2B', '1A', '1B', 'TA', 'TB']
                ]
            ]
        ];
    }
    
    // Lire le fichier JSON
    $jsonData = file_get_contents($jsonFile);
    if ($jsonData === false) {
        error_log('Impossible de lire le fichier etablissement.json');
        return [
            'matieres' => [],
            'classes' => []
        ];
    }
    
    // Décoder le JSON
    $data = json_decode($jsonData, true);
    if ($data === null) {
        error_log('Erreur de décodage JSON: ' . json_last_error_msg());
        return [
            'matieres' => [],
            'classes' => []
        ];
    }
    
    return $data;
}

// Fonctions utilitaires
function isAuthenticated() {
    return isset($_SESSION['user']);
}

// Fonctions pour le profil utilisateur

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
    
    $nom = isset($_SESSION['user']['nom']) ? $_SESSION['user']['nom'] : '';
    $prenom = isset($_SESSION['user']['prenom']) ? $_SESSION['user']['prenom'] : '';
    
    if (empty($nom) && empty($prenom)) {
        return isset($_SESSION['user']['username']) ? $_SESSION['user']['username'] : 'Utilisateur';
    }
    
    return $prenom . ' ' . $nom;
}

/**
 * Fonction pour obtenir le nombre de devoirs non lus
 * 
 * @return int Le nombre de devoirs non lus
 */
function getUnreadHomeWorksCount() {
    if (!isAuthenticated()) return 0;
    
    global $pdo;
    $userId = getUserId();
    
    // Si l'utilisateur n'est pas un élève, retourner 0
    if (getUserProfile() !== 'eleve') return 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM devoirs d
            LEFT JOIN devoirs_status ds ON d.id = ds.id_devoir AND ds.id_eleve = ?
            WHERE (ds.id IS NULL OR ds.status = 'non_fait')
            AND d.date_remise >= CURDATE()
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Erreur lors du comptage des devoirs: ' . $e->getMessage());
        return 0;
    }
}

// Fonction pour formater les dates en français
function formatDateFr($date, $includeTime = false) {
    if (!$date) return '';
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Date invalide';
    
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