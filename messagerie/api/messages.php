<?php
/**
 * API pour les actions sur les messages
 */
// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/autoloader.php'; // Nouvel autoloader
require_once __DIR__ . '/../controllers/message.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/../core/error_handler.php';

// Initialiser les services
$apiHandler = new ApiHandler($pdo);
$dbService = new DatabaseService($pdo);
$messageRepo = new MessageRepository($pdo);

// Gérer la requête
try {
    $user = $apiHandler->getUser();

    // Envoi d'un nouveau message via AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
        // Vérifier le jeton CSRF
        if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception("Jeton CSRF invalide");
        }
        
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
        
        // Limiter la taille du message
        if (strlen($contenu) > 10000) {
            throw new Exception("Le message est trop long (maximum 10000 caractères)");
        }
        
        // Vérifier que l'utilisateur a le droit de répondre à cette conversation
        $apiHandler->requirePermission(PERMISSION_SEND_MESSAGE, ['conversation_id' => $convId]);
        
        // Traiter les pièces jointes de manière sécurisée
        $filesData = isset($_FILES['attachments']) ? $_FILES['attachments'] : [];
        
        $result = handleSendMessage($convId, $user, $contenu, $importance, $parentMessageId, $filesData);
        
        if ($result['success'] && isset($result['messageId'])) {
            // Récupérer les informations du message créé pour les renvoyer
            $message = $messageRepo->findById($result['messageId']);
            
            $apiHandler->sendResponse(true, ['message' => $message]);
        } else {
            $apiHandler->sendResponse(false, $result['message']);
        }
    }

    // Récupération des nouveaux messages d'une conversation
    else if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'get_new') {
        $convId = (int)$_GET['conv_id'];
        $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

        if (!$convId) {
            handleValidationError('ID de conversation invalide');
        }

        // Vérifier que l'utilisateur est participant à la conversation
        $participant = $dbService->getParticipantByConversation($user['id'], $user['type'], $convId);
        if (!$participant || $participant['is_deleted'] == 1) {
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
                   m.body as contenu, 
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
        foreach ($messages as &$message) {
            $message['pieces_jointes'] = $dbService->getAttachmentsByMessage($message['id']);
            
            // Marquer comme lu automatiquement
            if (!$message['est_lu'] && !$message['is_self']) {
                $dbService->markMessageAsRead($message['id'], $user['id'], $user['type']);
            }
        }
        
        $apiHandler->sendResponse(true, ['messages' => $messages]);
    }

    // Vérification des mises à jour d'une conversation
    else if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'check_updates') {
        $convId = (int)$_GET['conv_id'];
        $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

        if (!$convId) {
            handleValidationError('ID de conversation invalide');
        }

        // Vérifier que l'utilisateur est participant à la conversation
        $participant = $dbService->getParticipantByConversation($user['id'], $user['type'], $convId);
        if (!$participant || $participant['is_deleted'] == 1) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Vérifier s'il y a de nouveaux messages après le timestamp donné
        $newMessagesStmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(UNIX_TIMESTAMP(created_at)) as last_message_timestamp 
            FROM messages
            WHERE conversation_id = ? AND UNIX_TIMESTAMP(created_at) > ?
        ");
        $newMessagesStmt->execute([$convId, $lastTimestamp]);
        $newMessagesInfo = $newMessagesStmt->fetch();
        $newMessagesCount = $newMessagesInfo['count'];
        $lastMessageTimestamp = $newMessagesInfo['last_message_timestamp'];
        
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
        
        // Utiliser le timestamp du dernier message plutôt que le timestamp actuel
        $timestampToReturn = $lastMessageTimestamp ? $lastMessageTimestamp : $lastTimestamp;
        
        $apiHandler->sendResponse(true, [
            'hasUpdates' => $newMessagesCount > 0,
            'updateCount' => $newMessagesCount,
            'participantsChanged' => $participantsChanged,
            'senders' => $sendersInfo,
            'timestamp' => $timestampToReturn // Utiliser le timestamp du dernier message
        ]);
    }

    // Marquer un message comme lu/non lu
    else if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && isset($_GET['action'])) {
        $messageId = (int)$_GET['id'];
        $action = $_GET['action'];

        if (!$messageId || !in_array($action, ['mark_read', 'mark_unread'])) {
            handleValidationError('Paramètres invalides');
        }

        $result = null;
        
        if ($action === 'mark_read') {
            $result = handleMarkMessageAsRead($messageId, $user);
        } else {
            $result = handleMarkMessageAsUnread($messageId, $user);
        }
        
        $apiHandler->sendResponse($result['success'], $result['success'] ? $result : $result['message']);
    }

    // Si on arrive ici, c'est que l'action demandée n'existe pas
    else {
        handleNotFoundError('Action non supportée');
    }
} catch (Exception $e) {
    $apiHandler->handleError($e, ['endpoint' => 'messages.php']);
}