<?php
/**
 * API pour les actions sur les participants
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/participant.php';
require_once __DIR__ . '/../models/participant.php';
require_once __DIR__ . '/../core/auth.php';

// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupération des participants disponibles pour ajout
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['type']) && isset($_GET['conv_id'])) {
    header('Content-Type: application/json');
    
    $type = $_GET['type'];
    $convId = (int)$_GET['conv_id'];

    if (empty($type) || empty($convId)) {
        echo json_encode(['error' => 'Paramètres manquants']);
        exit;
    }

    // Vérifier que l'utilisateur est participant à la conversation
    $participantCheck = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $participantCheck->execute([$convId, $user['id'], $user['type']]);
    if (!$participantCheck->fetch()) {
        echo json_encode(['error' => 'Droits insuffisants']);
        exit;
    }

    $participants = getAvailableParticipants($convId, $type);
    echo json_encode($participants);
    exit;
}

// Récupération HTML de la liste des participants
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'get_list') {
    header('Content-Type: text/html; charset=UTF-8');
    
    $convId = (int)$_GET['conv_id'];

    if (!$convId) {
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
        
        // Inclure le template de liste de participants
        include '../templates/components/participant-list.php';
    } catch (Exception $e) {
        echo "<p>Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    exit;
}

// Actions sur les participants
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['conv_id']) && isset($_POST['participant_id'])) {
    header('Content-Type: application/json');
    
    $convId = (int)$_POST['conv_id'];
    $participantId = (int)$_POST['participant_id'];
    $action = $_POST['action'];

    if (!$convId || !$participantId) {
        echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
        exit;
    }

    try {
        $result = null;
        
        switch ($action) {
            case 'add':
                if (!isset($_POST['participant_type'])) {
                    throw new Exception("Type de participant manquant");
                }
                $result = handleAddParticipant($convId, $participantId, $_POST['participant_type'], $user);
                break;
                
            case 'promote':
                $result = handlePromoteToModerator($convId, $participantId, $user);
                break;
                
            case 'demote':
                $result = handleDemoteFromModerator($convId, $participantId, $user);
                break;
                
            case 'remove':
                $result = handleRemoveParticipant($convId, $participantId, $user);
                break;
                
            default:
                throw new Exception("Action non supportée");
        }
        
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);