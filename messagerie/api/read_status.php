<?php
/**
 * API de gestion des statuts de lecture des messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';

// Valider l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Endpoint pour récupérer les statuts de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-status') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        $messageIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
        
        // Valider que l'utilisateur est participant à cette conversation
        $stmt = $pdo->prepare("
            SELECT 1 FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $stmt->execute([$convId, $user['id'], $user['type']]);
        
        if (!$stmt->fetchColumn()) {
            throw new Exception("Non autorisé à accéder à cette conversation");
        }
        
        // Si aucun ID spécifique n'est fourni, récupérer tous les messages de la conversation
        if (empty($messageIds)) {
            $stmt = $pdo->prepare("SELECT id FROM messages WHERE conversation_id = ? ORDER BY id");
            $stmt->execute([$convId]);
            $messageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Vérifier que les messageIds appartiennent à la conversation
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id FROM messages 
                WHERE conversation_id = ? AND id IN ($placeholders)
            ");
            $params = array_merge([$convId], $messageIds);
            $stmt->execute($params);
            $validMessageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Ne garder que les IDs valides
            $messageIds = array_intersect($messageIds, $validMessageIds);
        }
        
        // Récupérer les statuts de lecture pour chaque message
        $statuses = [];
        foreach ($messageIds as $messageId) {
            $readStatus = getMessageReadStatus($messageId);
            $statuses[$messageId] = $readStatus;
        }
        
        echo json_encode([
            'success' => true,
            'statuses' => $statuses
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Endpoint efficace pour les mises à jour de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-updates') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        
        // Vérifier que l'utilisateur a accès à cette conversation
        $stmt = $pdo->prepare("
            SELECT 1 FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $stmt->execute([$convId, $user['id'], $user['type']]);
        
        if (!$stmt->fetchColumn()) {
            throw new Exception("Non autorisé à accéder à cette conversation");
        }
        
        // Récupérer les mises à jour depuis un certain timestamp
        $stmt = $pdo->prepare("
            SELECT m.id AS messageId, m.conversation_id,
                  (SELECT COUNT(*) FROM message_read_status 
                   WHERE message_id = m.id) AS read_count
            FROM messages m
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.id
        ");
        $stmt->execute([$convId, $since]);
        $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Pour chaque message, récupérer son statut détaillé
        foreach ($updates as &$update) {
            $update['read_status'] = getMessageReadStatus($update['messageId']);
        }
        
        echo json_encode([
            'success' => true,
            'updates' => $updates,
            'hasUpdates' => !empty($updates)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si aucune action reconnue, renvoyer une erreur
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);