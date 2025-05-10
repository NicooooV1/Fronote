<?php
/**
 * /api/send_message.php - API pour envoyer un message sans rechargement
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/message_functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Toujours répondre en JSON
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifier que la requête est de type POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

try {
    $convId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
    $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';
    $importance = isset($_POST['importance']) ? $_POST['importance'] : 'normal';
    $parentMessageId = isset($_POST['parent_message_id']) && !empty($_POST['parent_message_id']) ? 
                      (int)$_POST['parent_message_id'] : null;
    
    if (!$convId) {
        throw new Exception("ID de conversation invalide");
    }
    
    if (empty($contenu)) {
        throw new Exception("Le message ne peut pas être vide");
    }
    
    // Récupérer les informations de la conversation
    $conversation = getConversationInfo($convId);
    if (!$conversation) {
        throw new Exception("Conversation introuvable");
    }
    
    // Vérifier si l'utilisateur peut répondre à une annonce
    if (!canReplyToAnnouncement($user['id'], $user['type'], $convId, $conversation['type'])) {
        throw new Exception("Vous n'êtes pas autorisé à répondre à cette annonce");
    }
    
    // Vérifier si l'utilisateur peut définir l'importance
    if (!canSetMessageImportance($user['type'])) {
        $importance = 'normal';
    }
    
    // Traiter les pièces jointes
    $filesData = isset($_FILES['attachments']) ? $_FILES['attachments'] : [];
    
    // Ajouter le message
    $messageId = addMessage(
        $convId, 
        $user['id'], 
        $user['type'], 
        $contenu, 
        $importance, 
        false, // Est annonce
        false, // Notification obligatoire
        false, // Accusé de réception
        $parentMessageId, 
        'standard', 
        $filesData
    );
    
    if ($messageId) {
        // Récupérer les informations du message créé pour les renvoyer
        $message = getMessageById($messageId);
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } else {
        throw new Exception("Erreur lors de l'ajout du message");
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}