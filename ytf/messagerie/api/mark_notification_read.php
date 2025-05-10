<?php
/**
 * /api/mark_notification_read.php - Marquer une notification comme lue
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

$notificationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$notificationId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'ID de notification invalide']);
    exit;
}

try {
    // Marquer la notification comme lue
    global $pdo;
    
    // Vérifier que la notification appartient à l'utilisateur
    $stmt = $pdo->prepare("
        SELECT n.id, m.id as message_id 
        FROM message_notifications n
        JOIN messages m ON n.message_id = m.id
        WHERE n.id = ? AND n.user_id = ? AND n.user_type = ? AND n.is_read = 0
    ");
    $stmt->execute([$notificationId, $user['id'], $user['type']]);
    $notification = $stmt->fetch();
    
    if (!$notification) {
        throw new Exception("Notification introuvable ou déjà lue");
    }
    
    // Marquer comme lu
    $messageId = $notification['message_id'];
    markMessageAsRead($messageId, $user['id'], $user['type']);
    
    // Réponse
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}