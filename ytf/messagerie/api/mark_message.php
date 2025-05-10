<?php
/**
 * /api/mark_message.php - Marquer un message comme lu/non lu
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

$messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if (!$messageId || !in_array($action, ['mark_read', 'mark_unread'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

try {
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        throw new Exception("Message introuvable");
    }
    
    $convId = $result['conversation_id'];
    
    // Vérifier que l'utilisateur est participant à la conversation
    $checkStmt = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkStmt->execute([$convId, $user['id'], $user['type']]);
    
    if (!$checkStmt->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à ce message");
    }
    
    if ($action === 'mark_read') {
        // Marquer comme lu
        markMessageAsRead($messageId, $user['id'], $user['type']);
    } else {
        // Marquer comme non lu
        markMessageAsUnread($messageId, $user['id'], $user['type']);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}