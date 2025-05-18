<?php
/**
 * Résolution des problèmes d'authentification pour Pronote
 * Ce fichier permet de centraliser et de résoudre les problèmes de redéclaration de fonctions
 */

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Charger le bridge de compatibilité
$compatibilityPath = __DIR__ . '/compatibility.php';
if (file_exists($compatibilityPath)) {
    require_once $compatibilityPath;
}

// Charger auth_central.php qui contient les fonctions d'authentification principales
$authCentralPath = __DIR__ . '/auth_central.php';
if (file_exists($authCentralPath)) {
    require_once $authCentralPath;
} else {
    // Utiliser les fonctions d'urgence si auth_central.php n'existe pas
    // Cette partie n'est exécutée que si compatibility.php a été inclus avant
    if (function_exists('ensure_auth_functions')) {
        ensure_auth_functions();
    }
}

/**
 * Fonction de maintenance pour afficher le statut des fonctions d'authentification
 * Cette fonction est utile pour le débogage
 */
function showAuthFunctionsStatus() {
    $functions = [
        'isLoggedIn',
        'getCurrentUser',
        'getUserRole',
        'requireLogin',
        'isAdmin',
        'isTeacher',
        'isStudent',
        'isParent',
        'isVieScolaire',
        'getUserFullName'
    ];
    
    $output = "<h3>Statut des fonctions d'authentification</h3>";
    $output .= "<ul>";
    
    foreach ($functions as $func) {
        $exists = function_exists($func);
        $status = $exists ? 'Disponible' : 'Non définie';
        $color = $exists ? 'green' : 'red';
        
        $output .= "<li style='color: {$color};'>{$func}: {$status}</li>";
    }
    
    $output .= "</ul>";
    
    return $output;
}

// Pour utilisation dans les API ou pour la vérification de santé
if (isset($_GET['check']) && $_GET['check'] == 'auth') {
    header('Content-Type: application/json');
    $result = [];
    foreach (['isLoggedIn', 'getCurrentUser', 'requireLogin'] as $func) {
        $result[$func] = function_exists($func);
    }
    echo json_encode($result);
    exit;
}
