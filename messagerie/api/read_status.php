<?php
/**
 * API pour la gestion des statuts de lecture de messages
 */
// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON/SSE
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/message.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';
require_once __DIR__ . '/../core/utils.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Limiter le taux de requêtes API
enforceRateLimit('api_read_status', 120, 60, true); // 120 requêtes/minute

// Vérifier le jeton CSRF pour toutes les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestData = file_get_contents('php://input');
    $data = json_decode($requestData, true) ?: [];
    
    // Vérifier le jeton CSRF soit dans les données JSON, soit dans l'en-tête
    $csrfToken = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Jeton CSRF invalide']);
        exit;
    }
}

// Point d'entrée SSE pour les statuts de lecture en temps réel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id'], $_GET['action']) && $_GET['action'] === 'stream') {
    $convId = (int)$_GET['conv_id'];
    $lastMessageId = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $lastVersionSum = isset($_GET['version']) ? (int)$_GET['version'] : 0;
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
    header('X-Accel-Buffering: no'); // Pour Nginx
    // Empêcher le buffering
    ini_set('output_buffering', 'off');
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);
    if (ob_get_level() > 0) ob_end_flush();

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

    // Récupérer la version actuelle
    $versionStmt = $pdo->prepare("
        SELECT SUM(version) as version_sum FROM conversation_participants
        WHERE conversation_id = ? AND is_deleted = 0
    ");
    $versionStmt->execute([$convId]);
    $currentVersionSum = (int)$versionStmt->fetchColumn();

    // Envoyer la version initiale
    echo "event: init\n";
    echo "data: {\"version\": " . $currentVersionSum . "}\n\n";
    flush();

    // Envoyer l'état initial si c'est la première connexion
    if ($lastVersionSum === 0) {
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
        
        echo "event: initial_state\n";
        echo "data: " . json_encode($initialState) . "\n\n";
        flush();
    }
    
    // Définir une durée maximale pour la connexion SSE (30 minutes)
    $maxExecutionTime = 1800; // 30 minutes
    $startTime = time();

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
        
        // Récupérer la version actuelle
        $versionStmt->execute([$convId]);
        $newVersionSum = (int)$versionStmt->fetchColumn();
        
        // Si la version a changé, envoyer les mises à jour
        if ($newVersionSum !== $currentVersionSum) {
            // Récupérer les messages depuis le dernier connu
            $messagesStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ? AND id > ?
                ORDER BY id ASC
            ");
            $messagesStmt->execute([$convId, $lastMessageId]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $updates = [];
            foreach ($messages as $messageId) {
                $updates[] = [
                    'messageId' => $messageId,
                    'read_status' => getMessageReadStatus($messageId)
                ];
                
                // Mettre à jour le dernier ID
                if ($messageId > $lastMessageId) {
                    $lastMessageId = $messageId;
                }
            }
            
            // Envoyer les mises à jour
            if (!empty($updates)) {
                echo "event: read_status\n";
                echo "data: " . json_encode([
                    'version' => $newVersionSum,
                    'updates' => $updates
                ]) . "\n\n";
                flush();
            }
            
            // Mettre à jour la version actuelle
            $currentVersionSum = $newVersionSum;
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
    $secret = 'BkTW#9f7@L!zP3vQ#Rx*8jN2'; // Même clé que dans sse_token.php
    
    try {
        $decoded = base64_decode($token);
        if ($decoded === false) {
            return false;
        }
        
        $parts = explode('|', $decoded);
        if (count($parts) !== 5) { // Vérifier qu'il y a 5 parties
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
        // Journal de l'erreur mais ne pas interrompre le flux
        error_log("SSE token validation error: " . $e->getMessage());
        return false;
    }
}

// Endpoint pour marquer un message comme lu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read') {
    header('Content-Type: application/json');
    
    try {
        $convId = (int)$_GET['conv_id'];
        
        // Récupérer les données JSON
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        
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
        logException($e, ['action' => 'read', 'conv_id' => $_GET['conv_id'] ?? 0]);
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
        logException($e, ['action' => 'read-polling', 'conv_id' => $_GET['conv_id'] ?? 0]);
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
            $queryData = buildSafeInQuery(
                $pdo,
                "SELECT id FROM messages WHERE conversation_id = :conv_id AND id",
                "IN",
                $messageIds,
                ['conv_id' => $convId]
            );
            
            $checkMessagesStmt = $pdo->prepare($queryData['query']);
            $checkMessagesStmt->execute($queryData['params']);
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
        logException($e, ['action' => 'read-status', 'conv_id' => $_GET['conv_id'] ?? 0]);
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
        logException($e, ['action' => 'read-updates', 'conv_id' => $_GET['conv_id'] ?? 0]);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);