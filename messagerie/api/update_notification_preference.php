<?php
/**
 * /api/update_notification_preference.php - Mise à jour d'une préférence de notification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/message_functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifier la méthode et les paramètres
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['preference']) || !isset($_POST['value'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

$preference = $_POST['preference'];
$value = $_POST['value'] ? true : false;

// Valider le nom de la préférence
$validPreferences = [
    'email_notifications', 
    'browser_notifications', 
    'notification_sound',
    'mention_notifications', 
    'reply_notifications', 
    'important_notifications',
    'digest_frequency'
];

if (!in_array($preference, $validPreferences)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Préférence non valide']);
    exit;
}

// Cas spécial pour digest_frequency qui n'est pas un booléen
if ($preference === 'digest_frequency') {
    $value = $_POST['value'];
    $validValues = ['never', 'daily', 'weekly'];
    
    if (!in_array($value, $validValues)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Valeur non valide pour digest_frequency']);
        exit;
    }
}

try {
    // Préparation des préférences à mettre à jour
    $preferences = [$preference => $value];
    
    // Mise à jour de la préférence
    $result = updateUserNotificationPreferences($user['id'], $user['type'], $preferences);
    
    header('Content-Type: application/json');
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la mise à jour de la préférence']);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}