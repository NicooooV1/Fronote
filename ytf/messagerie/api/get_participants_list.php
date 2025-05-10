<?php
/**
 * /api/get_participants_list.php - Récupération HTML de la liste des participants
 */

 require_once __DIR__ . '/../config/config.php';
 require_once __DIR__ . '/../config/constants.php';
 require_once __DIR__ . '/../includes/functions.php';
 require_once __DIR__ . '/../includes/message_functions.php';
 require_once __DIR__ . '/../includes/auth.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<p>Non authentifié</p>";
    exit;
}

$convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;

if (!$convId) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<p>ID de conversation invalide</p>";
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
    
    // Récupérer les informations de l'utilisateur
    $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
    $isAdmin = $participantInfo && $participantInfo['is_admin'] == 1;
    $isModerator = $participantInfo && ($participantInfo['is_moderator'] == 1 || $isAdmin);
    $isDeleted = $participantInfo && $participantInfo['is_deleted'] == 1;
    
    // Récupérer les participants
    $participants = getParticipants($convId);
    
    header('Content-Type: text/html; charset=UTF-8');
    
    // Inclure le template de liste de participants
    include '../templates/participant-list.php';
    
} catch (Exception $e) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<p>Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
}