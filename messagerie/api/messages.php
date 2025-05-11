<?php
/**
 * API pour les actions sur les messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/message.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';

// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

// Toujours répondre en JSON
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Point d'entrée SSE pour les mises à jour de messages en temps réel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id'], $_GET['action']) && $_GET['action'] === 'stream') {
    $convId = (int)$_GET['conv_id'];
    $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

    if (!$convId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID de conversation invalide']);
        exit;
    }

    // Configuration des en-têtes SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    // Empêcher le buffering
    ini_set('output_buffering', 'off');
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);

    // Vérifier que l'utilisateur est participant à la conversation
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $user['id'], $user['type']]);
    if (!$checkParticipant->fetch()) {
        echo "event: error\n";
        echo "data: {\"message\": \"Vous n'êtes pas autorisé à accéder à cette conversation\"}\n\n";
        exit;
    }

    // Envoyer un ping initial pour établir la connexion
    echo "event: ping\n";
    echo "data: {\"time\": " . time() . "}\n\n";
    flush();

    // Boucle principale
    while (true) {
        // Vérifier si la connexion client est toujours active
        if (connection_aborted()) {
            break;
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

        if (!empty($messages)) {
            // Récupérer les pièces jointes pour chaque message
            $attachmentStmt = $pdo->prepare("
                SELECT id, message_id, file_name as nom_fichier, file_path as chemin
                FROM message_attachments 
                WHERE message_id = ?
            ");
            
            foreach ($messages as &$message) {
                $attachmentStmt->execute([$message['id']]);
                $message['pieces_jointes'] = $attachmentStmt->fetchAll();
                
                // Mettre à jour le timestamp du dernier message
                if ($message['timestamp'] > $lastTimestamp) {
                    $lastTimestamp = $message['timestamp'];
                }
            }

            // Envoyer les messages au client
            echo "event: message\n";
            echo "data: " . json_encode($messages) . "\n\n";
            flush();
        }
        
        // Vérifier les changements de participants
        $participantsChangedStmt = $pdo->prepare("
            SELECT MAX(UNIX_TIMESTAMP(updated_at)) as last_update 
            FROM conversation_participants
            WHERE conversation_id = ?
        ");
        $participantsChangedStmt->execute([$convId]);
        $lastParticipantUpdate = $participantsChangedStmt->fetchColumn() ?: 0;
        
        if ($lastParticipantUpdate > $lastTimestamp) {
            echo "event: participants_changed\n";
            echo "data: {\"timestamp\": " . $lastParticipantUpdate . "}\n\n";
            flush();
        }
        
        // Envoyer un ping pour maintenir la connexion
        echo "event: ping\n";
        echo "data: {\"time\": " . time() . "}\n\n";
        flush();
        
        // Pause pour éviter de surcharger le serveur
        sleep(2);
    }
    
    exit;
}

// Envoi d'un nouveau message via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
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
        
        // Traiter les pièces jointes
        $filesData = isset($_FILES['attachments']) ? $_FILES['attachments'] : [];
        
        $result = handleSendMessage($convId, $user, $contenu, $importance, $parentMessageId, $filesData);
        
        if ($result['success'] && isset($result['messageId'])) {
            // Récupérer les informations du message créé pour les renvoyer
            $message = getMessageById($result['messageId']);
            
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
        } else {
            echo json_encode($result);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Récupération des nouveaux messages d'une conversation
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'get_new') {
    $convId = (int)$_GET['conv_id'];
    $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

    if (!$convId) {
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
        
        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Vérification des mises à jour d'une conversation
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'check_updates') {
    $convId = (int)$_GET['conv_id'];
    $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

    if (!$convId) {
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
        
        echo json_encode([
            'success' => true,
            'hasUpdates' => $newMessagesCount > 0,
            'updateCount' => $newMessagesCount,
            'participantsChanged' => $participantsChanged,
            'senders' => $sendersInfo,
            'timestamp' => $timestampToReturn // Utiliser le timestamp du dernier message
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Marquer un message comme lu/non lu
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && isset($_GET['action'])) {
    $messageId = (int)$_GET['id'];
    $action = $_GET['action'];

    if (!$messageId || !in_array($action, ['mark_read', 'mark_unread'])) {
        echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
        exit;
    }

    try {
        $result = null;
        
        if ($action === 'mark_read') {
            $result = handleMarkMessageAsRead($messageId, $user);
        } else {
            $result = handleMarkMessageAsUnread($messageId, $user);
        }
        
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
echo json_encode(['success' => false, 'error' => 'Action non supportée']);