<?php
/**
 * Modèle pour la gestion des messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/../core/uploader.php';

/**
 * Récupère les messages d'une conversation
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return array
 */
function getMessages($convId, $userId, $userType) {
    global $pdo;
    
    // Vérifier que l'utilisateur est participant à la conversation
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $userId, $userType]);
    if (!$checkParticipant->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    // Récupérer les messages
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
        WHERE m.conversation_id = ? AND m.is_deleted = 0
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$userId, $userType, $userId, $userType, $convId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Marquer la conversation comme lue
    markConversationAsRead($convId, $userId, $userType);
    
    return $messages;
}

/**
 * Récupère les messages d'une conversation même si elle est dans la corbeille
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return array
 */
function getMessagesEvenIfDeleted($convId, $userId, $userType) {
    global $pdo;
    
    // Ne pas vérifier is_deleted=0 pour les participants
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $checkParticipant->execute([$convId, $userId, $userType]);
    if (!$checkParticipant->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    $sql = "
        SELECT m.*, 
               CASE 
                   WHEN cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id THEN 0
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
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userType, $userId, $userType, $convId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère un message par son ID
 * @param int $messageId
 * @return array|false
 */
function getMessageById($messageId) {
    global $pdo;
    
    $sql = "
        SELECT m.*, 
               1 as est_lu,
               1 as is_self,
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
               m.sender_id as expediteur_id, 
               m.sender_type as expediteur_type,
               m.body as contenu,
               m.status as status,
               m.created_at as date_envoi,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        WHERE m.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    
    if ($message) {
        $attachmentStmt = $pdo->prepare("
            SELECT id, message_id, file_name as nom_fichier, file_path as chemin
            FROM message_attachments 
            WHERE message_id = ?
        ");
        $attachmentStmt->execute([$messageId]);
        $message['pieces_jointes'] = $attachmentStmt->fetchAll();
        
        // Récupérer les informations de lecture pour ce message
        $readInfoStmt = $pdo->prepare("
            SELECT COUNT(*) as total_participants,
                   SUM(CASE WHEN cp.last_read_message_id >= ? THEN 1 ELSE 0 END) as read_count
            FROM conversation_participants cp
            WHERE cp.conversation_id = ? AND cp.is_deleted = 0
        ");
        $readInfoStmt->execute([$messageId, $message['conversation_id']]);
        $readInfo = $readInfoStmt->fetch();
        
        $message['read_status'] = [
            'message_id' => $message['id'],
            'total_participants' => (int)$readInfo['total_participants'],
            'read_by_count' => (int)$readInfo['read_count'],
            'all_read' => (int)$readInfo['read_count'] === (int)$readInfo['total_participants'],
            'percentage' => $readInfo['total_participants'] > 0 ? 
                          round(($readInfo['read_count'] / $readInfo['total_participants']) * 100) : 0
        ];
    }
    
    return $message;
}

/**
 * Ajoute un nouveau message
 * @param int $convId
 * @param int $senderId
 * @param string $senderType
 * @param string $content
 * @param string $importance
 * @param bool $estAnnonce
 * @param bool $notificationObligatoire
 * @param int|null $parentMessageId
 * @param string $typeMessage
 * @param array $filesData
 * @return int
 */
function addMessage($convId, $senderId, $senderType, $content, $importance = 'normal', 
                   $estAnnonce = false, $notificationObligatoire = false,
                   $parentMessageId = null, $typeMessage = 'standard', $filesData = []) {
    global $pdo;
    
    // Vérifier que l'expéditeur est participant à la conversation
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $senderId, $senderType]);
    if (!$checkParticipant->fetch()) {
        throw new Exception("Vous n'êtes pas autorisé à envoyer des messages dans cette conversation");
    }
    
    // Vérification de la longueur maximale
    $maxLength = 10000;
    if (mb_strlen($content) > $maxLength) {
        throw new Exception("Votre message est trop long (maximum $maxLength caractères)");
    }
    
    $pdo->beginTransaction();
    try {
        // Déterminer le statut du message
        $status = $estAnnonce ? 'annonce' : $importance;
        
        // Insérer le message
        $sql = "INSERT INTO messages (conversation_id, sender_id, sender_type, body, created_at, updated_at, status) 
                VALUES (?, ?, ?, ?, NOW(), NOW(), ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$convId, $senderId, $senderType, $content, $status]);
        $messageId = $pdo->lastInsertId();
        
        // Mettre à jour la date du dernier message
        $upd = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
        $upd->execute([$convId]);
        
        // Récupérer les participants
        $participantsStmt = $pdo->prepare("
            SELECT user_id, user_type FROM conversation_participants 
            WHERE conversation_id = ? AND is_deleted = 0
        ");
        $participantsStmt->execute([$convId]);
        $participants = $participantsStmt->fetchAll();
        
        // Déterminer le type de notification
        $notificationType = 'unread';
        if ($estAnnonce) {
            $notificationType = 'broadcast';
        } elseif ($importance === 'important' || $importance === 'urgent') {
            $notificationType = 'important';
        } elseif ($parentMessageId) {
            $notificationType = 'reply';
        }
        
        // Créer des notifications pour chaque participant
        $addNotification = $pdo->prepare("
            INSERT INTO message_notifications (user_id, user_type, message_id, notification_type, is_read, read_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        // Incrémenter le compteur de messages non lus
        $incrementUnread = $pdo->prepare("
            UPDATE conversation_participants 
            SET unread_count = unread_count + 1 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        
        // Mettre à jour le last_read_message_id pour l'expéditeur
        $updateReadId = $pdo->prepare("
            UPDATE conversation_participants 
            SET last_read_message_id = ?, version = version + 1
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateReadId->execute([$messageId, $convId, $senderId, $senderType]);
        
        foreach ($participants as $p) {
            // Mettre à jour last_read_message_id pour l'expéditeur
            if ($p['user_id'] == $senderId && $p['user_type'] == $senderType) {
                continue; // Déjà fait au-dessus
            }
            
            // Pour les autres participants, créer une notification et incrémenter le compteur
            $isRead = 0;
            $readAt = null;
            
            // Créer la notification
            $addNotification->execute([
                $p['user_id'], 
                $p['user_type'], 
                $messageId, 
                $notificationType,
                $isRead,
                $readAt
            ]);
            
            // Incrémenter le compteur non lu pour ce participant
            $incrementUnread->execute([
                $convId,
                $p['user_id'],
                $p['user_type']
            ]);
        }
        
        // Traiter les pièces jointes
        if (!empty($filesData) && isset($filesData['name']) && is_array($filesData['name'])) {
            $uploadedFiles = handleFileUploads($filesData);
            saveAttachments($pdo, $messageId, $uploadedFiles);
        }
        
        $pdo->commit();
        return $messageId;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Marque un message comme lu
 *
 * @param int $messageId ID du message
 * @param int $userId ID de l'utilisateur
 * @param string $userType Type d'utilisateur
 * @param int $maxRetries Nombre maximum de tentatives en cas d'échec
 * @return bool Succès de l'opération
 */
function markMessageAsRead($messageId, $userId, $userType, $maxRetries = 3) {
    global $pdo;
    
    $retriesLeft = $maxRetries;
    
    while ($retriesLeft > 0) {
        try {
            // Récupérer l'ID de la conversation
            $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $convId = $stmt->fetchColumn();
            
            if (!$convId) {
                return false;
            }
            
            // Commencer une transaction
            $pdo->beginTransaction();
            
            // Vérifier le dernier message lu
            $stmt = $pdo->prepare("
                SELECT last_read_message_id 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                FOR UPDATE
            ");
            $stmt->execute([$convId, $userId, $userType]);
            $currentLastReadId = $stmt->fetchColumn();
            
            if ($currentLastReadId === null || $messageId > $currentLastReadId) {
                // Mettre à jour le dernier message lu
                $updateStmt = $pdo->prepare("
                    UPDATE conversation_participants 
                    SET last_read_message_id = ?, last_read_at = NOW()
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateStmt->execute([$messageId, $convId, $userId, $userType]);
                
                // Mettre à jour les notifications
                $updateNotif = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = true, read_at = NOW()
                    WHERE related_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateNotif->execute([$messageId, $userId, $userType]);
                
                // Recalculer précisément le compteur unread_count
                $updateCount = $pdo->prepare("
                    UPDATE conversation_participants
                    SET unread_count = (
                        SELECT COUNT(*) 
                        FROM messages m
                        LEFT JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
                        WHERE m.conversation_id = ? 
                        AND cp.user_id = ? AND cp.user_type = ?
                        AND (cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id)
                        AND m.sender_id != ? AND m.sender_type != ?
                    )
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateCount->execute([$convId, $userId, $userType, $userId, $userType, $convId, $userId, $userType]);
                
                $pdo->commit();
                return true;
            } else {
                // Conflit détecté, la version a changé entre-temps
                $pdo->rollBack();
                $retriesLeft--;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retriesLeft--;
            
            if ($retriesLeft === 0) {
                // Journaliser l'erreur après toutes les tentatives
                error_log("Erreur lors du marquage du message comme lu: " . $e->getMessage());
                return false;
            }
            
            // Attendre avant la prochaine tentative
            usleep(100000); // 100 ms
        }
    }
    
    return false;
}

/**
 * Récupère le statut de lecture d'un message avec des informations détaillées
 * @param int $messageId
 * @return array
 */
function getMessageReadStatus($messageId) {
    global $pdo;
    
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return [
            'message_id' => $messageId,
            'total_participants' => 0,
            'read_by_count' => 0,
            'all_read' => false,
            'percentage' => 0,
            'readers' => []
        ];
    }
    
    $convId = $result['conversation_id'];
    
    // Récupérer le nombre total de participants et le nombre de participants qui ont lu
    $readInfoStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_participants,
            SUM(CASE WHEN cp.last_read_message_id >= ? THEN 1 ELSE 0 END) as read_count
        FROM conversation_participants cp
        WHERE cp.conversation_id = ? AND cp.is_deleted = 0
        AND cp.user_id != (SELECT sender_id FROM messages WHERE id = ?)
        AND cp.user_type != (SELECT sender_type FROM messages WHERE id = ?)
    ");
    $readInfoStmt->execute([$messageId, $convId, $messageId, $messageId]);
    $readInfo = $readInfoStmt->fetch();
    
    // Récupérer les participants qui ont lu
    $readersStmt = $pdo->prepare("
        SELECT cp.user_id, cp.user_type,
               CASE 
                   WHEN cp.user_type = 'eleve' THEN 
                       (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = cp.user_id)
                   WHEN cp.user_type = 'parent' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = cp.user_id)
                   WHEN cp.user_type = 'professeur' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = cp.user_id)
                   WHEN cp.user_type = 'vie_scolaire' THEN 
                       (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = cp.user_id)
                   WHEN cp.user_type = 'administrateur' THEN 
                       (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = cp.user_id)
                   ELSE 'Inconnu'
               END as nom_complet
        FROM conversation_participants cp
        WHERE cp.conversation_id = ? AND cp.last_read_message_id >= ? AND cp.is_deleted = 0
        AND cp.user_id != (SELECT sender_id FROM messages WHERE id = ?)
        AND cp.user_type != (SELECT sender_type FROM messages WHERE id = ?)
    ");
    $readersStmt->execute([$convId, $messageId, $messageId, $messageId]);
    $readers = $readersStmt->fetchAll();
    
    return [
        'message_id' => $messageId,
        'total_participants' => (int)$readInfo['total_participants'],
        'read_by_count' => (int)$readInfo['read_count'],
        'all_read' => (int)$readInfo['read_count'] === (int)$readInfo['total_participants'] && (int)$readInfo['total_participants'] > 0,
        'percentage' => $readInfo['total_participants'] > 0 ? 
                      round(($readInfo['read_count'] / $readInfo['total_participants']) * 100) : 0,
        'readers' => $readers
    ];
}

/**
 * Marque un message comme non lu
 * @param int $messageId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function markMessageAsUnread($messageId, $userId, $userType) {
    global $pdo;
    
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return false;
    }
    
    $convId = $result['conversation_id'];
    
    $pdo->beginTransaction();
    try {
        // Récupérer tous les messages de la conversation triés par ID
        $messagesStmt = $pdo->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");
        $messagesStmt->execute([$convId]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Trouver le message précédent
        $prevMessageId = null;
        foreach ($messages as $mId) {
            if ((int)$mId === (int)$messageId) {
                break;
            }
            $prevMessageId = $mId;
        }
        
        // Mettre à jour le last_read_message_id avec le message précédent
        $version = time(); // Utiliser le timestamp comme nouvelle version
        $updateStmt = $pdo->prepare("
            UPDATE conversation_participants 
            SET last_read_message_id = ?, version = ?
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateStmt->execute([$prevMessageId, $version, $convId, $userId, $userType]);
        
        // Vérifier si la notification existe
        $checkStmt = $pdo->prepare("
            SELECT id, is_read FROM message_notifications 
            WHERE message_id = ? AND user_id = ? AND user_type = ?
        ");
        $checkStmt->execute([$messageId, $userId, $userType]);
        $notification = $checkStmt->fetch();
        
        if ($notification) {
            // Si la notification existe et est déjà lue, la marquer comme non lue
            if ($notification['is_read']) {
                // Marquer comme non lu et réinitialiser la date de lecture
                $updNotif = $pdo->prepare("
                    UPDATE message_notifications 
                    SET is_read = 0, read_at = NULL 
                    WHERE id = ?
                ");
                $updNotif->execute([$notification['id']]);
                
                // Recalculer le compteur de messages non lus
                $recalcUnread = $pdo->prepare("
                    UPDATE conversation_participants cp
                    SET unread_count = (
                        SELECT COUNT(*) 
                        FROM messages m
                        LEFT JOIN message_notifications mn ON m.id = mn.message_id AND mn.user_id = ? AND mn.user_type = ?
                        WHERE m.conversation_id = ? 
                        AND (mn.id IS NULL OR mn.is_read = 0)
                        AND m.sender_id != ? AND m.sender_type != ?
                    )
                    WHERE cp.conversation_id = ? AND cp.user_id = ? AND cp.user_type = ?
                ");
                $recalcUnread->execute([
                    $userId, $userType, $convId, $userId, $userType, $convId, $userId, $userType
                ]);
            }
        } else {
            // Si la notification n'existe pas, on la crée comme non lue
            $createNotif = $pdo->prepare("
                INSERT INTO message_notifications 
                (user_id, user_type, message_id, notification_type, is_read, read_at) 
                VALUES (?, ?, ?, 'unread', 0, NULL)
            ");
            $createNotif->execute([$userId, $userType, $messageId]);
            
            // Incrémenter le compteur de messages non lus
            $updCount = $pdo->prepare("
                UPDATE conversation_participants 
                SET unread_count = unread_count + 1 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $updCount->execute([$convId, $userId, $userType]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/* 
* La fonction canReplyToAnnouncement() est déjà déclarée dans core/utils.php
* Ne pas la redéclarer ici pour éviter l'erreur
*/

/* 
* La fonction canSetMessageImportance() est déjà déclarée dans core/utils.php
* Ne pas la redéclarer ici pour éviter l'erreur
*/

/**
 * Fonctions liées aux messages
 */
require_once __DIR__ . '/../core/utils.php';

/**
 * Envoie un message groupé à une classe
 * @param int $userId ID de l'utilisateur
 * @param string $classe Nom de la classe
 * @param string $titre Titre du message
 * @param string $contenu Contenu du message
 * @param string $importance Niveau d'importance du message
 * @param bool $notificationObligatoire Si la notification est obligatoire
 * @param bool $includeParents Si les parents d'élèves doivent être inclus
 * @param array $files Fichiers à joindre
 * @return int ID de la conversation créée
 */
function sendMessageToClass($userId, $classe, $titre, $contenu, $importance = 'normal', $notificationObligatoire = false, $includeParents = false, $files = []) {
    global $pdo;
    
    // ...existing code...
}

/* 
* La fonction canReplyToAnnouncement() est déjà déclarée dans core/utils.php
* Ne pas la redéclarer ici pour éviter l'erreur
*/