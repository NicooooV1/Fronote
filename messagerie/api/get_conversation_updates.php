<?php
/**
 * /api/get_conversation_updates.php - Vérification des mises à jour d'une conversation
 * Optimisé pour l'actualisation en temps réel des messages
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

$convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;
$lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

if (!$convId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'ID de conversation invalide']);
    exit;
}

try {
    // Vérifier que l'utilisateur est participant à la conversation
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $user['id'], $user['type']]);
    if (!$checkParticipant->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    // Vérifier s'il y a de nouveaux messages après le timestamp donné
    $newMessagesStmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM messages
        WHERE conversation_id = ? AND UNIX_TIMESTAMP(created_at) > ?
    ");
    $newMessagesStmt->execute([$convId, $lastTimestamp]);
    $newMessagesCount = $newMessagesStmt->fetchColumn();
    
    // Vérifier si les participants ont changé
    $participantsChangedStmt = $pdo->prepare("
        SELECT MAX(UNIX_TIMESTAMP(updated_at)) as last_update 
        FROM conversation_participants
        WHERE conversation_id = ?
    ");
    $participantsChangedStmt->execute([$convId]);
    $lastParticipantUpdate = $participantsChangedStmt->fetchColumn() ?: 0;
    $participantsChanged = $lastParticipantUpdate > $lastTimestamp;
    
    // Récupérer la liste des expéditeurs des nouveaux messages
    $sendersInfo = [];
    if ($newMessagesCount > 0) {
        $sendersStmt = $pdo->prepare("
            SELECT DISTINCT 
                m.sender_id,
                m.sender_type,
                CASE 
                    WHEN m.sender_type = 'eleve' THEN 
                        (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = m.sender_id)
                    WHEN m.sender_type = 'parent' THEN 
                        (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = m.sender_id)
                    WHEN m.sender_type = 'professeur' THEN 
                        (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = m.sender_id)
                    WHEN m.sender_type = 'vie_scolaire' THEN 
                        (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = m.sender_id)
                    WHEN m.sender_type = 'administrateur' THEN 
                        (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = m.sender_id)
                    ELSE 'Inconnu'
                END as sender_name
            FROM messages m
            WHERE m.conversation_id = ? AND UNIX_TIMESTAMP(m.created_at) > ?
            ORDER BY m.created_at DESC
            LIMIT 3
        ");
        $sendersStmt->execute([$convId, $lastTimestamp]);
        $sendersInfo = $sendersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Réponse
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'hasUpdates' => $newMessagesCount > 0,
        'updateCount' => $newMessagesCount,
        'participantsChanged' => $participantsChanged,
        'senders' => $sendersInfo,
        'timestamp' => time() // Timestamp actuel pour les futures requêtes
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}