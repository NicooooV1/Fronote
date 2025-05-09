<?php
/**
 * /api/conditional_notifications.php - Vérification des notifications avec ETag
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

try {
    // Compter les notifications non lues
    $count = countUnreadNotifications($user['id'], $user['type']);
    
    // Générer un ETag basé sur le nombre de notifications
    $etag = '"notifications_' . $user['id'] . '_' . $user['type'] . '_' . $count . '"';
    
    // Vérifier si le client a envoyé un ETag
    $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    
    // Si les ETags correspondent, renvoyer 304 Not Modified
    if ($clientEtag === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    // Obtenir la dernière notification si nécessaire
    $latestNotification = null;
    if ($count > 0) {
        $notifications = getUnreadNotifications($user['id'], $user['type'], 1);
        if (!empty($notifications)) {
            $latestNotification = $notifications[0];
        }
    }
    
    // Définir l'en-tête ETag
    header('ETag: ' . $etag);
    header('Cache-Control: private, must-revalidate');
    
    // Renvoyer les données
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'count' => $count,
        'latest_notification' => $latestNotification
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}