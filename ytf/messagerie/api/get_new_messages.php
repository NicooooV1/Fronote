<?php
/**
 * /api/get_new_messages.php - Récupération des nouveaux messages d'une conversation
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
    
    // Récupérer les nouveaux messages
    $stmt = $pdo->prepare("
        SELECT m.*, 
               CASE 
                   WHEN cp.last_read_at IS NULL OR m.created_at > cp.last_read_at THEN 0
                   ELSE 1
               END as est_lu,
               CASE 
                   WHEN m.sender_id = ? AND m.sender_type = ? THEN 1
                   ELSE 0
               END as is_self,
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
               END as expediteur_nom,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        LEFT JOIN conversation_participants cp ON (
            m.conversation_id = cp.conversation_id AND 
            cp.user_id = ? AND 
            cp.user_type = ?
        )
        WHERE m.conversation_id = ? AND UNIX_TIMESTAMP(m.created_at) > ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$user['id'], $user['type'], $user['id'], $user['type'], $convId, $lastTimestamp]);
    $messages = $stmt->fetchAll();
    
    // Récupérer les pièces jointes pour chaque message
    $attachmentStmt = $pdo->prepare("
        SELECT id, message_id, file_name as nom_fichier, file_path as chemin
        FROM message_attachments 
        WHERE message_id = ?
    ");
    
    foreach ($messages as &$message) {
        $attachmentStmt->execute([$message['id']]);
        $message['pieces_jointes'] = $attachmentStmt->fetchAll();
        
        // Marquer comme lu automatiquement
        if (!$message['est_lu'] && !$message['is_self']) {
            markMessageAsRead($message['id'], $user['id'], $user['type']);
        }
    }
    
    // Réponse
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}