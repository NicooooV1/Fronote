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

// Toujours répondre en JSON
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Endpoint pour marquer un message comme lu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read') {
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
        
        // Marquer le message comme lu
        $result = markMessageAsRead($messageId, $user['id'], $user['type']);
        
        // Récupérer les informations de lecture actualisées
        $readStatus = getMessageReadStatus($messageId);
        
        echo json_encode([
            'success' => true,
            'messageId' => $messageId,
            'read_status' => $readStatus
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Endpoint Long Polling pour récupérer les mises à jour de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-updates') {
    try {
        $convId = (int)$_GET['conv_id'];
        $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        $maxWaitTime = 30; // Temps d'attente maximum en secondes
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Envoyer des headers pour éviter la mise en cache
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Fonction pour vérifier les mises à jour
        function checkForUpdates($pdo, $convId, $since) {
            // Récupérer tous les messages de la conversation depuis lastMessageId
            $messagesStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ? AND id > ?
                ORDER BY id ASC
            ");
            $messagesStmt->execute([$convId, $since]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($messages)) {
                return ['updates' => []];
            }
            
            // Récupérer les versions actuelles de tous les participants
            $versionStmt = $pdo->prepare("
                SELECT SUM(version) as version_sum FROM conversation_participants
                WHERE conversation_id = ? AND is_deleted = 0
            ");
            $versionStmt->execute([$convId]);
            $versionSum = $versionStmt->fetchColumn();
            
            $updates = [];
            
            // Pour chaque message, récupérer les informations de lecture
            foreach ($messages as $messageId) {
                $readStatus = getMessageReadStatus($messageId);
                
                $updates[] = [
                    'messageId' => $messageId,
                    'read_status' => $readStatus,
                    'readers' => array_column($readStatus['readers'], 'user_id')
                ];
            }
            
            return [
                'version_sum' => $versionSum,
                'updates' => $updates
            ];
        }
        
        // Premier contrôle immédiat
        $result = checkForUpdates($pdo, $convId, $since);
        $initialVersionSum = $result['version_sum'] ?? 0;
        $updates = $result['updates'];
        
        // Si des mises à jour sont disponibles immédiatement, les renvoyer
        if (!empty($updates)) {
            echo json_encode([
                'success' => true,
                'updates' => $updates,
                'timestamp' => time()
            ]);
            exit;
        }
        
        // Sinon, attendre les mises à jour en long polling
        $startTime = time();
        $currentVersionSum = $initialVersionSum;
        
        // Boucle de long polling
        while (time() - $startTime < $maxWaitTime) {
            // Pause pour éviter de surcharger la base de données
            sleep(1);
            
            // Vérifier si la somme des versions a changé
            $versionStmt = $pdo->prepare("
                SELECT SUM(version) as version_sum FROM conversation_participants
                WHERE conversation_id = ? AND is_deleted = 0
            ");
            $versionStmt->execute([$convId]);
            $newVersionSum = $versionStmt->fetchColumn();
            
            // Si la somme des versions a changé, récupérer les mises à jour
            if ($newVersionSum != $currentVersionSum) {
                $result = checkForUpdates($pdo, $convId, $since);
                $updates = $result['updates'];
                
                // Si des mises à jour sont disponibles, les renvoyer
                if (!empty($updates)) {
                    echo json_encode([
                        'success' => true,
                        'updates' => $updates,
                        'timestamp' => time()
                    ]);
                    exit;
                }
                
                // Mettre à jour la somme des versions pour éviter de boucler pour rien
                $currentVersionSum = $newVersionSum;
            }
            
            // Fermer et réouvrir la connexion pour éviter le timeout
            if (time() - $startTime > 15) {
                $pdo = null;
                $pdo = new PDO($dsn, $user, $pass, $options);
            }
            
            // Vider le tampon de sortie pour éviter les timeouts
            ob_flush();
            flush();
        }
        
        // Si aucune mise à jour n'est disponible après le temps d'attente, renvoyer une réponse vide
        echo json_encode([
            'success' => true,
            'updates' => [],
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Endpoint Server-Sent Events pour les mises à jour de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-sse') {
    try {
        $convId = (int)$_GET['conv_id'];
        $lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            header('HTTP/1.1 403 Forbidden');
            exit;
        }
        
        // Configuration des entêtes pour SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Désactiver la mise en mémoire tampon pour Nginx
        
        // Fonction pour envoyer un événement SSE
        function sendSSE($event, $data, $id = null) {
            echo "event: $event\n";
            if ($id !== null) {
                echo "id: $id\n";
            }
            echo "data: " . json_encode($data) . "\n\n";
            ob_flush();
            flush();
        }
        
        // Envoyer un événement de connexion
        sendSSE('connected', ['message' => 'Connected to SSE stream']);
        
        // Récupérer les versions initiales de tous les participants
        $versionStmt = $pdo->prepare("
            SELECT user_id, user_type, version FROM conversation_participants
            WHERE conversation_id = ? AND is_deleted = 0
        ");
        $versionStmt->execute([$convId]);
        $initialVersions = [];
        while ($row = $versionStmt->fetch()) {
            $initialVersions[$row['user_id'] . '_' . $row['user_type']] = $row['version'];
        }
        
        // Boucle principale de l'événement SSE
        $connectionTime = time();
        $eventId = $lastEventId;
        $keepAliveInterval = 15; // Envoyer un keep-alive toutes les 15 secondes
        $lastKeepAlive = time();
        
        // Récupérer tous les messages de la conversation
        $messagesStmt = $pdo->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");
        $messagesStmt->execute([$convId]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        while (true) {
            // Envoyer un keep-alive périodiquement
            if (time() - $lastKeepAlive >= $keepAliveInterval) {
                sendSSE('keep-alive', ['time' => time()]);
                $lastKeepAlive = time();
            }
            
            // Vérifier les nouvelles versions
            $versionStmt->execute([$convId]);
            $newVersions = [];
            while ($row = $versionStmt->fetch()) {
                $newVersions[$row['user_id'] . '_' . $row['user_type']] = $row['version'];
            }
            
            // Chercher les participants avec des versions modifiées
            $changedParticipants = [];
            foreach ($newVersions as $key => $version) {
                if (!isset($initialVersions[$key]) || $initialVersions[$key] != $version) {
                    list($userId, $userType) = explode('_', $key);
                    $changedParticipants[] = ['user_id' => $userId, 'user_type' => $userType];
                    $initialVersions[$key] = $version; // Mettre à jour la version
                }
            }
            
            // Si des participants ont changé, envoyer des mises à jour
            if (!empty($changedParticipants)) {
                foreach ($messages as $messageId) {
                    $readStatus = getMessageReadStatus($messageId);
                    
                    $eventId++;
                    sendSSE('read_update', [
                        'messageId' => $messageId,
                        'read_status' => $readStatus,
                        'readers' => array_column($readStatus['readers'], 'user_id')
                    ], $eventId);
                }
            }
            
            // Pause pour éviter de surcharger le serveur
            sleep(2);
            
            // Fermer et réouvrir la connexion pour éviter le timeout après un certain temps
            if (time() - $connectionTime > 120) { // 2 minutes
                $pdo = null;
                $pdo = new PDO($dsn, $user, $pass, $options);
                $connectionTime = time();
                
                // Recharger les versions des participants
                $versionStmt = $pdo->prepare("
                    SELECT user_id, user_type, version FROM conversation_participants
                    WHERE conversation_id = ? AND is_deleted = 0
                ");
                
                // Vérifier si le client est toujours connecté
                if (connection_aborted()) {
                    exit;
                }
            }
        }
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        exit;
    }
    exit;
}

// Récupération des statuts de lecture pour plusieurs messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id']) && isset($_GET['action']) && $_GET['action'] === 'read-status') {
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

// Si on arrive ici, c'est que l'action demandée n'existe pas
echo json_encode(['success' => false, 'error' => 'Action non supportée']);