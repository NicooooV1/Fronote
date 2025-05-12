<?php
/**
 * API pour la gestion des statuts de lecture de messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/message.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';

// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Endpoint pour marquer un message comme lu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        
        // Récupérer les données JSON
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['messageId'])) {
            throw new Exception("ID de message manquant");
        }
        
        $messageId = (int)$data['messageId'];
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Vérifier que le message appartient à la conversation
        $checkMessage = $pdo->prepare("
            SELECT id FROM messages 
            WHERE id = ? AND conversation_id = ?
        ");
        $checkMessage->execute([$messageId, $convId]);
        if (!$checkMessage->fetch()) {
            throw new Exception("Message introuvable dans cette conversation");
        }
        
        // Marquer le message comme lu avec le mécanisme de retry
        $result = markMessageAsRead($messageId, $user['id'], $user['type'], 3);
        
        // Récupérer les informations de lecture actualisées
        $readStatus = getMessageReadStatus($messageId);
        
        echo json_encode([
            'success' => $result,
            'messageId' => $messageId,
            'read_status' => $readStatus
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Endpoint optimisé pour les mises à jour de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-polling') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        $lastVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
        
        // Obtenir la somme actuelle des versions
        $versionStmt = $pdo->prepare("
            SELECT SUM(version) as version_sum FROM conversation_participants
            WHERE conversation_id = ? AND is_deleted = 0
        ");
        $versionStmt->execute([$convId]);
        $currentVersionSum = (int)$versionStmt->fetchColumn();
        
        // Si la version n'a pas changé, on renvoie juste le timestamp actuel
        if ($currentVersionSum === $lastVersion) {
            echo json_encode([
                'success' => true,
                'hasUpdates' => false,
                'version' => $currentVersionSum,
                'timestamp' => time()
            ]);
            exit;
        }
        
        // Récupérer tous les messages depuis le dernier connu
        $messagesStmt = $pdo->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ? AND id > ?
            ORDER BY id ASC
        ");
        $messagesStmt->execute([$convId, $since]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $updates = [];
        foreach ($messages as $messageId) {
            $readStatus = getMessageReadStatus($messageId);
            $updates[] = [
                'messageId' => $messageId,
                'read_status' => $readStatus
            ];
        }
        
        // Si c'est la première requête (version=0), renvoyer toutes les données
        if ($lastVersion === 0) {
            $allMessagesStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ?
                ORDER BY id ASC
            ");
            $allMessagesStmt->execute([$convId]);
            $allMessages = $allMessagesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $initialState = [];
            foreach ($allMessages as $messageId) {
                $initialState[$messageId] = getMessageReadStatus($messageId);
            }
            
            echo json_encode([
                'success' => true,
                'hasUpdates' => true,
                'version' => $currentVersionSum,
                'initialState' => $initialState,
                'updates' => $updates,
                'timestamp' => time()
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'hasUpdates' => !empty($updates),
                'version' => $currentVersionSum,
                'updates' => $updates,
                'timestamp' => time()
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Récupération des statuts de lecture pour plusieurs messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-status') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        $messageIds = isset($_GET['messages']) ? array_map('intval', explode(',', $_GET['messages'])) : [];
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Si aucun message n'est spécifié, récupérer tous les messages de la conversation
        if (empty($messageIds)) {
            $messagesStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ?
                ORDER BY id ASC
            ");
            $messagesStmt->execute([$convId]);
            $messageIds = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Vérifier que les messages appartiennent à la conversation
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $checkMessagesStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE id IN ($placeholders) AND conversation_id = ?
            ");
            $params = array_merge($messageIds, [$convId]);
            $checkMessagesStmt->execute($params);
            $validMessageIds = $checkMessagesStmt->fetchAll(PDO::FETCH_COLUMN);
            
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
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Récupérer tous les messages depuis le dernier connu
        $messagesStmt = $pdo->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ? AND id > ?
            ORDER BY id ASC
        ");
        $messagesStmt->execute([$convId, $since]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Récupérer la somme des versions
        $versionStmt = $pdo->prepare("
            SELECT SUM(version) as version_sum FROM conversation_participants
            WHERE conversation_id = ? AND is_deleted = 0
        ");
        $versionStmt->execute([$convId]);
        $versionSum = $versionStmt->fetchColumn();
        
        $updates = [];
        foreach ($messages as $messageId) {
            $readStatus = getMessageReadStatus($messageId);
            $updates[] = [
                'messageId' => $messageId,
                'read_status' => $readStatus
            ];
        }
        
        echo json_encode([
            'success' => true,
            'hasUpdates' => !empty($updates),
            'updates' => $updates,
            'version' => $versionSum,
            'timestamp' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);