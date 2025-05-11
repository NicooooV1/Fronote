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
               m.sender_id as expediteur_id, 
               m.sender_type as expediteur_type,
               m.body as contenu,
               COALESCE(m.status, 'normal') as status,
               m.created_at as date_envoi,
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
    $messages = $stmt->fetchAll();

    $attachmentStmt = $pdo->prepare("
        SELECT id, message_id, file_name as nom_fichier, file_path as chemin
        FROM message_attachments 
        WHERE message_id = ?
    ");
    
    foreach ($messages as &$message) {
        $attachmentStmt->execute([$message['id']]);
        $message['pieces_jointes'] = $attachmentStmt->fetchAll();
        
        // Marquer comme lu si pas encore lu et si ce n'est pas notre propre message
        if (!$message['est_lu'] && !$message['is_self']) {
            markMessageAsRead($message['id'], $userId, $userType);
        }
        
        // Récupérer les informations de lecture pour ce message
        $message['read_status'] = getMessageReadStatus($message['id']);
    }

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
               m.sender_id as expediteur_id, 
               m.sender_type as expediteur_type,
               m.body as contenu,
               COALESCE(m.status, 'normal') as status,
               m.created_at as date_envoi,
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
        
        // Ne pas marquer comme lu automatiquement puisque la conversation est dans la corbeille
        
        // Récupérer les informations de lecture pour ce message
        $message['read_status'] = getMessageReadStatus($message['id']);
    }

    return $messages;
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
        $message['read_status'] = getMessageReadStatus($messageId);
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
 * @param bool $accuseReception
 * @param int|null $parentMessageId
 * @param string $typeMessage
 * @param array $filesData
 * @return int
 */
function addMessage($convId, $senderId, $senderType, $content, $importance = 'normal', 
                   $estAnnonce = false, $notificationObligatoire = false, 
                   $accuseReception = false, 
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
 * Marque un message comme lu avec contrôle optimiste de concurrence
 * @param int $messageId - ID du message
 * @param int $userId - ID de l'utilisateur
 * @param string $userType - Type d'utilisateur
 * @param int $maxRetries - Nombre maximum de tentatives en cas de conflit
 * @return bool
 */
function markMessageAsRead($messageId, $userId, $userType, $maxRetries = 3) {
    global $pdo;
    
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $convId = $stmt->fetchColumn();
    
    if (!$convId) return false;
    
    $retriesLeft = $maxRetries;
    
    while ($retriesLeft > 0) {
        try {
            $pdo->beginTransaction();
            
            // Récupérer la version actuelle avec FOR UPDATE pour bloquer la ligne
            $getVersionStmt = $pdo->prepare("
                SELECT last_read_message_id, version 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                FOR UPDATE
            ");
            $getVersionStmt->execute([$convId, $userId, $userType]);
            $participantInfo = $getVersionStmt->fetch();
            
            if (!$participantInfo) {
                $pdo->rollBack();
                return false;
            }
            
            $currentVersion = $participantInfo['version'];
            $currentLastReadId = $participantInfo['last_read_message_id'];
            
            // Ne mettre à jour que si le nouveau message ID est plus grand
            if ($currentLastReadId === null || $messageId > $currentLastReadId) {
                // Incrémenter la version et mettre à jour last_read_message_id
                $updateStmt = $pdo->prepare("
                    UPDATE conversation_participants 
                    SET last_read_message_id = ?, version = version + 1, last_read_at = NOW()
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND version = ?
                ");
                $updateStmt->execute([$messageId, $convId, $userId, $userType, $currentVersion]);
                
                // Mettre à jour les notifications et le compteur unread_count
                if ($updateStmt->rowCount() > 0) {
                    // Marquer les notifications comme lues
                    $updateNotif = $pdo->prepare("
                        UPDATE message_notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE message_id = ? AND user_id = ? AND user_type = ? AND is_read = 0
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
                    
                    // Attendre un peu avant de réessayer pour éviter les contentions
                    usleep(100000); // 100 ms
                    continue;
                }
            } else {
                // Aucune mise à jour nécessaire (message déjà lu)
                $pdo->commit();
                return true;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retriesLeft--;
            
            if ($retriesLeft <= 0) {
                return false;
            }
            
            // Attendre avant de réessayer
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
    
    static $cachedStatuses = []; // Cache en mémoire
    
    // Vérifier si le statut est déjà en cache
    if (isset($cachedStatuses[$messageId])) {
        return $cachedStatuses[$messageId];
    }
    
    // Requête optimisée qui fait tout en une seule fois
    $stmt = $pdo->prepare("
        SELECT 
            m.id AS message_id,
            m.conversation_id,
            COUNT(cp.id) AS total_participants,
            SUM(CASE WHEN cp.last_read_message_id >= m.id THEN 1 ELSE 0 END) AS read_count,
            (COUNT(cp.id) = SUM(CASE WHEN cp.last_read_message_id >= m.id THEN 1 ELSE 0 END)) AS all_read
        FROM messages m
        JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
        WHERE m.id = ? AND cp.is_deleted = 0
        AND cp.user_id != m.sender_id AND cp.user_type != m.sender_type
        GROUP BY m.id
    ");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        // Message non trouvé, créer une structure par défaut
        $status = [
            'message_id' => $messageId,
            'total_participants' => 0,
            'read_by_count' => 0,
            'all_read' => false,
            'percentage' => 0,
            'readers' => []
        ];
    } else {
        // Calculer le statut
        $status = [
            'message_id' => $messageId,
            'total_participants' => (int)$result['total_participants'],
            'read_by_count' => (int)$result['read_count'],
            'all_read' => (bool)$result['all_read'],
            'percentage' => $result['total_participants'] > 0 ? 
                round(($result['read_count'] / $result['total_participants']) * 100) : 0,
            'readers' => []
        ];
        
        // Récupérer les lecteurs uniquement si nécessaire
        if ($status['read_by_count'] > 0) {
            // Optimisation: n'exécuter cette requête que si nécessaire
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
            $readersStmt->execute([$result['conversation_id'], $messageId, $messageId, $messageId]);
            $status['readers'] = $readersStmt->fetchAll();
        }
    }
    
    // Mettre en cache
    $cachedStatuses[$messageId] = $status;
    
    return $status;
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

/**
 * Vérifie si un utilisateur peut répondre à une annonce
 * @param int $userId
 * @param string $userType
 * @param int $conversationId
 * @param string $convType
 * @return bool
 */
function canReplyToAnnouncement($userId, $userType, $conversationId, $convType) {
    // Si ce n'est pas une annonce, tout le monde peut répondre
    if ($convType !== 'annonce') {
        return true;
    }
    
    // Les administrateurs et modérateurs peuvent répondre
    if (isConversationModerator($userId, $userType, $conversationId)) {
        return true;
    }
    
    // Si c'est un élève, il ne peut pas répondre aux annonces sauf s'il est modérateur
    if ($userType === 'eleve') {
        return false;
    }
    
    // Pour les autres types d'utilisateurs (professeurs, parents, vie scolaire)
    // Ils peuvent répondre si promus modérateur, sinon lecture seule
    return isConversationModerator($userId, $userType, $conversationId);
}

/**
 * Vérifie si un utilisateur peut définir une importance pour un message
 * @param string $userType
 * @return bool
 */
function canSetMessageImportance($userType) {
    // Seuls les parents, professeurs, vie scolaire et administrateurs peuvent définir des priorités
    return in_array($userType, ['parent', 'professeur', 'vie_scolaire', 'administrateur']);
}