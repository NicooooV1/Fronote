<?php
/**
 * Repository pour les messages
 */
class MessageRepository {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Récupère un message par son ID
     */
    public function findById($messageId) {
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        if ($message) {
            // Récupérer les pièces jointes
            $message['pieces_jointes'] = $this->getAttachments($messageId);
            
            // Récupérer les informations de lecture
            $message['read_status'] = $this->getReadStatus($messageId);
        }
        
        return $message;
    }
    
    /**
     * Récupère les messages d'une conversation
     */
    public function getByConversation($convId, $userId, $userType) {
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $userType, $userId, $userType, $convId]);
        $messages = $stmt->fetchAll();
        
        // Récupérer les pièces jointes et statuts de lecture pour chaque message
        foreach ($messages as &$message) {
            $message['pieces_jointes'] = $this->getAttachments($message['id']);
            $message['read_status'] = $this->getReadStatus($message['id']);
            
            // Marquer comme lu si pas encore lu et si ce n'est pas notre propre message
            if (!$message['est_lu'] && !$message['is_self']) {
                $this->markAsRead($message['id'], $userId, $userType);
            }
        }
        
        return $messages;
    }
    
    /**
     * Récupère les pièces jointes d'un message
     */
    public function getAttachments($messageId) {
        $stmt = $this->pdo->prepare("
            SELECT id, message_id, file_name as nom_fichier, file_path as chemin
            FROM message_attachments 
            WHERE message_id = ?
        ");
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère le statut de lecture d'un message
     */
    public function getReadStatus($messageId) {
        // Requête optimisée qui fait tout en une seule fois
        $stmt = $this->pdo->prepare("
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
                $readersStmt = $this->pdo->prepare("
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
        
        return $status;
    }
    
    /**
     * Ajoute un nouveau message
     */
    public function add($convId, $senderId, $senderType, $content, $importance = 'normal', 
                      $estAnnonce = false, $notificationObligatoire = false, 
                      $accuseReception = false, $parentMessageId = null, $typeMessage = 'standard', $filesData = []) {
        
        $this->pdo->beginTransaction();
        try {
            // Déterminer le statut du message
            $status = $estAnnonce ? 'annonce' : $importance;
            
            // Insérer le message
            $sql = "INSERT INTO messages (conversation_id, sender_id, sender_type, body, created_at, updated_at, status) 
                    VALUES (?, ?, ?, ?, NOW(), NOW(), ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$convId, $senderId, $senderType, $content, $status]);
            $messageId = $this->pdo->lastInsertId();
            
            // Mettre à jour la date du dernier message
            $upd = $this->pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
            $upd->execute([$convId]);
            
            // Récupérer les participants
            $participantsStmt = $this->pdo->prepare("
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
            $addNotification = $this->pdo->prepare("
                INSERT INTO message_notifications (user_id, user_type, message_id, notification_type, is_read, read_at) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            // Incrémenter le compteur de messages non lus
            $incrementUnread = $this->pdo->prepare("
                UPDATE conversation_participants 
                SET unread_count = unread_count + 1 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            
            // Mettre à jour le last_read_message_id pour l'expéditeur
            $updateReadId = $this->pdo->prepare("
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
                saveAttachments($this->pdo, $messageId, $uploadedFiles);
            }
            
            $this->pdo->commit();
            return $messageId;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Marque un message comme lu
     */
    public function markAsRead($messageId, $userId, $userType, $maxRetries = 3) {
        // Récupérer l'ID de la conversation pour ce message
        $stmt = $this->pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $convId = $stmt->fetchColumn();
        
        if (!$convId) return false;
        
        $retriesLeft = $maxRetries;
        
        while ($retriesLeft > 0) {
            try {
                $this->pdo->beginTransaction();
                
                // Récupérer la version actuelle avec FOR UPDATE pour bloquer la ligne
                $getVersionStmt = $this->pdo->prepare("
                    SELECT last_read_message_id, version 
                    FROM conversation_participants 
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                    FOR UPDATE
                ");
                $getVersionStmt->execute([$convId, $userId, $userType]);
                $participantInfo = $getVersionStmt->fetch();
                
                if (!$participantInfo) {
                    $this->pdo->rollBack();
                    return false;
                }
                
                $currentVersion = $participantInfo['version'];
                $currentLastReadId = $participantInfo['last_read_message_id'];
                
                // Ne mettre à jour que si le nouveau message ID est plus grand
                if ($currentLastReadId === null || $messageId > $currentLastReadId) {
                    // Incrémenter la version et mettre à jour last_read_message_id
                    $updateStmt = $this->pdo->prepare("
                        UPDATE conversation_participants 
                        SET last_read_message_id = ?, version = version + 1, last_read_at = NOW()
                        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND version = ?
                    ");
                    $updateStmt->execute([$messageId, $convId, $userId, $userType, $currentVersion]);
                    
                    // Mettre à jour les notifications et le compteur unread_count
                    if ($updateStmt->rowCount() > 0) {
                        // Marquer les notifications comme lues
                        $updateNotif = $this->pdo->prepare("
                            UPDATE message_notifications 
                            SET is_read = 1, read_at = NOW() 
                            WHERE message_id = ? AND user_id = ? AND user_type = ? AND is_read = 0
                        ");
                        $updateNotif->execute([$messageId, $userId, $userType]);
                        
                        // Recalculer précisément le compteur unread_count
                        $updateCount = $this->pdo->prepare("
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
                        
                        $this->pdo->commit();
                        return true;
                    } else {
                        // Conflit détecté, la version a changé entre-temps
                        $this->pdo->rollBack();
                        $retriesLeft--;
                        
                        // Attendre un peu avant de réessayer pour éviter les contentions
                        usleep(100000); // 100 ms
                        continue;
                    }
                } else {
                    // Aucune mise à jour nécessaire (message déjà lu)
                    $this->pdo->commit();
                    return true;
                }
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
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
     * Marque un message comme non lu
     */
    public function markAsUnread($messageId, $userId, $userType) {
        // Récupérer l'ID de la conversation pour ce message
        $stmt = $this->pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        $convId = $result['conversation_id'];
        
        $this->pdo->beginTransaction();
        try {
            // Récupérer tous les messages de la conversation triés par ID
            $messagesStmt = $this->pdo->prepare("
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
            $updateStmt = $this->pdo->prepare("
                UPDATE conversation_participants 
                SET last_read_message_id = ?, version = ?
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $updateStmt->execute([$prevMessageId, $version, $convId, $userId, $userType]);
            
            // Vérifier si la notification existe
            $checkStmt = $this->pdo->prepare("
                SELECT id, is_read FROM message_notifications 
                WHERE message_id = ? AND user_id = ? AND user_type = ?
            ");
            $checkStmt->execute([$messageId, $userId, $userType]);
            $notification = $checkStmt->fetch();
            
            if ($notification) {
                // Si la notification existe et est déjà lue, la marquer comme non lue
                if ($notification['is_read']) {
                    // Marquer comme non lu et réinitialiser la date de lecture
                    $updNotif = $this->pdo->prepare("
                        UPDATE message_notifications 
                        SET is_read = 0, read_at = NULL 
                        WHERE id = ?
                    ");
                    $updNotif->execute([$notification['id']]);
                    
                    // Recalculer le compteur de messages non lus
                    $recalcUnread = $this->pdo->prepare("
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
                $createNotif = $this->pdo->prepare("
                    INSERT INTO message_notifications 
                    (user_id, user_type, message_id, notification_type, is_read, read_at) 
                    VALUES (?, ?, ?, 'unread', 0, NULL)
                ");
                $createNotif->execute([$userId, $userType, $messageId]);
                
                // Incrémenter le compteur de messages non lus
                $updCount = $this->pdo->prepare("
                    UPDATE conversation_participants 
                    SET unread_count = unread_count + 1 
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                ");
                $updCount->execute([$convId, $userId, $userType]);
            }
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
}