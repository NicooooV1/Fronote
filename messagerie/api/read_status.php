<?php
/**
 * API pour les statuts de lecture des messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../controllers/message.php';

// Activer l'affichage des erreurs en développement
ini_set('display_errors', defined('APP_ENV') && APP_ENV === 'development' ? 1 : 0);
error_reporting(defined('APP_ENV') && APP_ENV === 'development' ? E_ALL : 0);

// S'assurer que le dossier de logs existe
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Journaliser les requêtes API
function logReadStatus($message, $data = null) {
    $logFile = __DIR__ . '/../logs/api_read_status_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $logMessage .= " - Data: " . json_encode($data);
    }
    
    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}

// Toujours répondre en JSON
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// -- IMPORTANT: Fonction pour sécuriser les transactions PDO --
function safeTransaction($callback) {
    global $pdo;
    
    // Vérifier si une transaction est déjà active
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
            logReadStatus("Une transaction active a été détectée et annulée avant d'en démarrer une nouvelle");
        } catch (Exception $e) {
            logReadStatus("Erreur lors de l'annulation d'une transaction active: " . $e->getMessage());
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Marquer un message comme lu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['conv_id'])) {
    try {
        // Récupérer les données JSON du corps de la requête
        $requestBody = file_get_contents('php://input');
        $data = json_decode($requestBody, true);
        
        if (!isset($data['messageId']) || !is_numeric($data['messageId'])) {
            throw new Exception("ID de message invalide");
        }
        
        $messageId = (int)$data['messageId'];
        $convId = (int)$_GET['conv_id'];
        
        $result = safeTransaction(function() use ($pdo, $messageId, $convId, $user) {
            // Vérifier que l'utilisateur est participant à la conversation
            $checkStmt = $pdo->prepare("
                SELECT conversation_id FROM messages WHERE id = ?
            ");
            $checkStmt->execute([$messageId]);
            $messageConvId = $checkStmt->fetchColumn();
            
            if ($messageConvId != $convId) {
                throw new Exception("Le message n'appartient pas à cette conversation");
            }
            
            $participantStmt = $pdo->prepare("
                SELECT id FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
            ");
            $participantStmt->execute([$convId, $user['id'], $user['type']]);
            
            if (!$participantStmt->fetch()) {
                throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
            }
            
            // Insérer ou mettre à jour le statut de lecture
            $upsertStmt = $pdo->prepare("
                INSERT INTO message_read_status (message_id, user_id, user_type, read_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ");
            $upsertStmt->execute([$messageId, $user['id'], $user['type']]);
            
            // Mettre à jour le compteur de non lus
            $updateCountStmt = $pdo->prepare("
                UPDATE conversation_participants
                SET unread_count = (
                    SELECT COUNT(*) FROM messages m
                    LEFT JOIN message_read_status mrs ON 
                        mrs.message_id = m.id AND 
                        mrs.user_id = ? AND 
                        mrs.user_type = ?
                    WHERE m.conversation_id = ?
                    AND m.sender_id != ?
                    AND m.sender_type != ?
                    AND mrs.read_at IS NULL
                )
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $updateCountStmt->execute([
                $user['id'], $user['type'], 
                $convId, 
                $user['id'], $user['type'],
                $convId, $user['id'], $user['type']
            ]);
            
            // Récupérer le statut complet de lecture pour ce message
            $readStatusStmt = $pdo->prepare("
                SELECT 
                    m.id as message_id,
                    COUNT(DISTINCT cp.id) - 1 as total_participants,
                    SUM(CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END) as read_by_count,
                    (COUNT(DISTINCT cp.id) - 1) = SUM(CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END) as all_read
                FROM messages m
                JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id AND cp.is_deleted = 0
                LEFT JOIN message_read_status mrs ON 
                    mrs.message_id = m.id AND
                    mrs.user_id = cp.user_id AND
                    mrs.user_type = cp.user_type
                WHERE m.id = ?
                AND NOT (cp.user_id = m.sender_id AND cp.user_type = m.sender_type) -- Exclure l'expéditeur
                GROUP BY m.id
            ");
            $readStatusStmt->execute([$messageId]);
            $readStatus = $readStatusStmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'read_status' => $readStatus
            ];
        });
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        logReadStatus("Erreur lors du marquage comme lu: " . $e->getMessage());
        
        // Assurer qu'aucune transaction n'est laissée ouverte
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Endpoint de polling pour les mises à jour de statut de lecture
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'read-polling') {
    try {
        $convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;
        $version = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $sinceMessageId = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        
        if (!$convId) {
            throw new Exception("ID de conversation invalide");
        }
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkStmt = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkStmt->execute([$convId, $user['id'], $user['type']]);
        
        if (!$checkStmt->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Récupérer les mises à jour de statut de lecture
        $updatesStmt = $pdo->prepare("
            SELECT 
                m.id as messageId, 
                (
                    SELECT 
                        JSON_OBJECT(
                            'message_id', m.id,
                            'total_participants', COUNT(DISTINCT cp.id) - 1,
                            'read_by_count', SUM(CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END),
                            'all_read', (COUNT(DISTINCT cp.id) - 1) = SUM(CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END)
                        )
                    FROM conversation_participants cp
                    LEFT JOIN message_read_status mrs ON 
                        mrs.message_id = m.id AND
                        mrs.user_id = cp.user_id AND
                        mrs.user_type = cp.user_type
                    WHERE cp.conversation_id = m.conversation_id
                    AND cp.is_deleted = 0
                    AND NOT (cp.user_id = m.sender_id AND cp.user_type = m.sender_type) -- Exclure l'expéditeur
                    GROUP BY m.id
                ) as read_status
            FROM messages m
            WHERE m.conversation_id = ?
            AND m.id > ?
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $updatesStmt->execute([$convId, $sinceMessageId]);
        $updates = $updatesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Transformer les JSON strings en objets PHP
        foreach ($updates as &$update) {
            if (isset($update['read_status']) && is_string($update['read_status'])) {
                $update['read_status'] = json_decode($update['read_status'], true);
            }
        }
        
        // Calculer un hash/version pour détecter les changements
        $newVersion = count($updates) > 0 ? crc32(json_encode($updates)) : $version;
        
        echo json_encode([
            'success' => true,
            'hasUpdates' => count($updates) > 0,
            'updates' => $updates,
            'version' => $newVersion
        ]);
        
    } catch (Exception $e) {
        logReadStatus("Erreur de polling: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si aucune action reconnue n'est trouvée
echo json_encode(['success' => false, 'error' => 'Action non supportée']);
?>