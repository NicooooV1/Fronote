<?php
/**
 * API pour les actions sur les messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/message.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';
require_once __DIR__ . '/../core/utils.php';

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

// Limiter le taux de requêtes API
enforceRateLimit('api_messages', 120, 60, true); // 120 requêtes/minute

// Vérifier le jeton CSRF pour toutes les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $requestData = file_get_contents('php://input');
    $data = json_decode($requestData, true) ?: [];
    
    // Vérifier le jeton CSRF soit dans les données JSON, soit dans l'en-tête
    $csrfToken = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Jeton CSRF invalide']);
        exit;
    }
}

// Point d'entrée SSE pour les mises à jour de messages en temps réel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id'], $_GET['action']) && $_GET['action'] === 'stream') {
    $convId = (int)$_GET['conv_id'];
    $lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;
    $token = isset($_GET['token']) ? $_GET['token'] : '';

    if (!$convId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID de conversation invalide']);
        exit;
    }
    
    // Vérifier le jeton SSE
    if (!validateSSEToken($token, $convId, $user['id'], $user['type'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Jeton SSE invalide']);
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
    
    // Définir une durée maximale pour la connexion SSE (30 minutes)
    $maxExecutionTime = 1800; // 30 minutes
    $startTime = time();
    
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
        
        // Vérifier si la durée maximale est atteinte
        if (time() - $startTime > $maxExecutionTime) {
            echo "event: timeout\n";
            echo "data: {\"message\": \"La connexion a expiré. Veuillez vous reconnecter.\"}\n\n";
            flush();
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

/**
 * Génère un jeton SSE sécurisé
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return string
 */
function generateSSEToken($convId, $userId, $userType) {
    $secret = 'BkTW#9f7@L!zP3vQ#Rx*8jN2'; // Change in production
    $expiry = time() + 3600; // 1 heure
    $data = $convId . '|' . $userId . '|' . $userType . '|' . $expiry;
    $signature = hash_hmac('sha256', $data, $secret);
    
    return base64_encode($data . '|' . $signature);
}

/**
 * Valide un jeton SSE
 * @param string $token
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function validateSSEToken($token, $convId, $userId, $userType) {
    $secret = 'BkTW#9f7@L!zP3vQ#Rx*8jN2'; // Same as above
    
    try {
        $decoded = base64_decode($token);
        if ($decoded === false) {
            return false;
        }
        
        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return false;
        }
        
        list($tokenConvId, $tokenUserId, $tokenUserType, $expiry, $signature) = $parts;
        
        // Vérifier l'expiration
        if (time() > (int)$expiry) {
            return false;
        }
        
        // Vérifier les paramètres
        if ((int)$tokenConvId !== (int)$convId || 
            (int)$tokenUserId !== (int)$userId || 
            $tokenUserType !== $userType) {
            return false;
        }
        
        // Vérifier la signature
        $data = $tokenConvId . '|' . $tokenUserId . '|' . $tokenUserType . '|' . $expiry;
        $computedSignature = hash_hmac('sha256', $data, $secret);
        
        return hash_equals($computedSignature, $signature);
        
    } catch (Exception $e) {
        logException($e, ['action' => 'validate_token']);
        return false;
    }
}

// Envoi d'un nouveau message via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    try {
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
        requirePermission($user, PERMISSION_SEND_MESSAGE, ['conversation_id' => $convId]);
        
        // Traiter les pièces jointes de manière sécurisée
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
        logException($e, ['action' => 'send_message', 'conv_id' => $convId ?? 0]);
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
        logException($e, ['action' => 'get_new', 'conv_id' => $convId]);
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
        logException($e, ['action' => 'check_updates', 'conv_id' => $convId]);
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
        logException($e, ['action' => $action, 'message_id' => $messageId]);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
echo json_encode(['success' => false, 'error' => 'Action non supportée']);