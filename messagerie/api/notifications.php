<?php
/**
 * API de gestion des notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/notification.php';
require_once __DIR__ . '/../core/auth.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Mise à jour des préférences de notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['preferences']) || !is_array($_POST['preferences'])) {
        echo json_encode(['success' => false, 'error' => 'Préférences invalides']);
        exit;
    }

    try {
        // Vérifier que la fonction existe
        if (!function_exists('handleUpdateNotificationPreferences')) {
            require_once __DIR__ . '/../controllers/notification.php';
        }
        
        $result = handleUpdateNotificationPreferences($user['id'], $user['type'], $_POST['preferences']);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Récupérer les notifications non lues
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_unread') {
    header('Content-Type: application/json');
    
    try {
        // Vérifier que la fonction existe
        if (!function_exists('getUserNotifications')) {
            require_once __DIR__ . '/../models/notification.php';
        }
        
        $notifications = getUserNotifications($user['id'], $user['type'], ['unread_only' => true, 'limit' => 10]);
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);