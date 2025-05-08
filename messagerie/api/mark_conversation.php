<?php
/**
 * /api/mark_conversation.php - Marquer une conversation comme lue/non lue
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

$convId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if (!$convId || !in_array($action, ['mark_read', 'mark_unread'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

try {
    // Vérifier que l'utilisateur est participant à la conversation
    $checkStmt = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkStmt->execute([$convId, $user['id'], $user['type']]);
    
    if (!$checkStmt->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    if ($action === 'mark_read') {
        // Récupérer tous les messages non lus de cette conversation
        $messagesStmt = $pdo->prepare("
            SELECT m.id 
            FROM messages m
            LEFT JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
            WHERE m.conversation_id = ? 
            AND cp.user_id = ? AND cp.user_type = ?
            AND (cp.last_read_at IS NULL OR m.created_at > cp.last_read_at)
            AND m.sender_id != ? AND m.sender_type != ?
        ");
        $messagesStmt->execute([$convId, $user['id'], $user['type'], $user['id'], $user['type']]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Marquer chaque message comme lu
        foreach ($messages as $messageId) {
            markMessageAsRead($messageId, $user['id'], $user['type']);
        }
        
        // Mettre à jour la date de dernière lecture
        $updateStmt = $pdo->prepare("
            UPDATE conversation_participants 
            SET last_read_at = NOW() 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateStmt->execute([$convId, $user['id'], $user['type']]);
        
    } else { // mark_unread
        // Réinitialiser la date de dernière lecture
        $updateStmt = $pdo->prepare("
            UPDATE conversation_participants 
            SET last_read_at = NULL
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateStmt->execute([$convId, $user['id'], $user['type']]);
        
        // Récupérer le dernier message de la conversation
        $lastMessageStmt = $pdo->prepare("
            SELECT id FROM messages 
            WHERE conversation_id = ? 
            AND sender_id != ? AND sender_type != ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $lastMessageStmt->execute([$convId, $user['id'], $user['type']]);
        $lastMessageId = $lastMessageStmt->fetchColumn();
        
        if ($lastMessageId) {
            // Marquer comme non lu
            $updNotif = $pdo->prepare("
                UPDATE message_notifications 
                SET is_read = 0 
                WHERE message_id = ? AND user_id = ? AND user_type = ?
            ");
            $updNotif->execute([$lastMessageId, $user['id'], $user['type']]);
            
            // Si notification n'existe pas, la créer
            $checkNotif = $pdo->prepare("
                SELECT id FROM message_notifications 
                WHERE message_id = ? AND user_id = ? AND user_type = ?
            ");
            $checkNotif->execute([$lastMessageId, $user['id'], $user['type']]);
            
            if (!$checkNotif->fetch()) {
                $createNotif = $pdo->prepare("
                    INSERT INTO message_notifications (user_id, user_type, message_id, notification_type, is_read) 
                    VALUES (?, ?, ?, 'unread', 0)
                ");
                $createNotif->execute([$user['id'], $user['type'], $lastMessageId]);
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}