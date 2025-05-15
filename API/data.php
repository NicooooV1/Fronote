<?php
/**
 * Data access API for Pronote
 * Centralizes common data access functions across all modules
 */

// Include the core API
require_once __DIR__ . '/core.php';

/**
 * Get path to etablissement.json file
 * 
 * @return string|null Path to the file or null if not found
 */
function getEtablissementJsonPath() {
    $path = __DIR__ . '/../login/data/etablissement.json';
    
    if (!file_exists($path)) {
        error_log('Error: etablissement.json file not found at: ' . $path);
        
        // Try alternative locations
        $alternatives = [
            __DIR__ . '/../login/data/etablissement.json',
            __DIR__ . '/../data/etablissement.json'
        ];
        
        foreach ($alternatives as $altPath) {
            if (file_exists($altPath)) {
                return $altPath;
            }
        }
        
        return null;
    }
    return $path;
}

/**
 * Get etablissement data (classes, subjects)
 * 
 * @return array Establishment data or default values if file not found
 */
function getEtablissementData() {
    $jsonFile = getEtablissementJsonPath();
    
    // If file doesn't exist, return default data
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
    
    // Read JSON file
    $jsonData = file_get_contents($jsonFile);
    if ($jsonData === false) {
        error_log('Cannot read etablissement.json file');
        return [
            'matieres' => [],
            'classes' => []
        ];
    }
    
    // Decode JSON
    $data = json_decode($jsonData, true);
    if ($data === null) {
        error_log('JSON decode error: ' . json_last_error_msg());
        return [
            'matieres' => [],
            'classes' => []
        ];
    }
    
    return $data;
}

/**
 * Get available classes
 * 
 * @return array List of available classes
 */
function getAvailableClasses() {
    $etablissementData = getEtablissementData();
    $classes = [];
    
    if (isset($etablissementData['classes'])) {
        foreach ($etablissementData['classes'] as $niveau => $cycles) {
            foreach ($cycles as $cycle => $classesList) {
                foreach ($classesList as $classe) {
                    $classes[] = $classe;
                }
            }
        }
    }
    
    return $classes;
}

/**
 * Get available subjects
 * 
 * @return array List of available subjects
 */
function getAvailableMatieres() {
    $etablissementData = getEtablissementData();
    return isset($etablissementData['matieres']) ? $etablissementData['matieres'] : [];
}

/**
 * Get unread homework count for current user
 * 
 * @return int Number of unread homework assignments
 */
function getUnreadHomeWorksCount() {
    if (!isLoggedIn()) return 0;
    
    global $pdo;
    $userId = getUserId();
    
    // If user is not a student, return 0
    if (getUserRole() !== 'eleve') return 0;
    
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
        error_log('Error counting unread homework: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Format date in French
 * 
 * @param string $date Date to format
 * @param bool $includeTime Whether to include time
 * @return string Formatted date
 */
function formatDateFr($date, $includeTime = false) {
    if (!$date) return '';
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Date invalide';
    
    $format = 'l j F Y';
    if ($includeTime) {
        $format .= ' à H\hi';
    }
    
    // French translation of days and months
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
    
    // Replace English days and months with French equivalents
    foreach ($jours_fr as $en => $fr) {
        $formatted = str_replace($en, $fr, $formatted);
    }
    
    foreach ($mois_fr as $en => $fr) {
        $formatted = str_replace($en, $fr, $formatted);
    }
    
    return $formatted;
}

/**
 * Clean input against XSS
 * 
 * @param string $data Input to clean
 * @return string Cleaned input
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Redirect with a message
 * 
 * @param string $url URL to redirect to
 * @param string $message Message to display
 * @param string $type Message type (success, error, etc.)
 */
function redirect($url, $message = '', $type = 'success') {
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header('Location: ' . $url);
    exit;
}
?>
