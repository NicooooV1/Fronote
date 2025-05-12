<?php
/**
 * Service centralisé pour les opérations de base de données
 */
class DatabaseService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Récupère les informations d'un participant à une conversation
     */
    public function getParticipantByConversation($userId, $userType, $convId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $stmt->execute([$convId, $userId, $userType]);
        return $stmt->fetch();
    }
    
    /**
     * Récupère les participants d'une conversation
     */
    public function getParticipants($convId) {
        $sql = "
            SELECT cp.id, cp.user_id as utilisateur_id, cp.user_type as utilisateur_type, 
                   cp.is_admin as est_administrateur, cp.is_moderator as est_moderateur, 
                   cp.is_deleted as a_quitte,
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
            WHERE cp.conversation_id = ?
            ORDER BY cp.is_admin DESC, cp.is_moderator DESC, cp.is_deleted ASC, nom_complet ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$convId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère les informations d'une conversation
     */
    public function getConversationInfo($convId) {
        $stmt = $this->pdo->prepare("
            SELECT c.id, c.subject as titre, 
            CASE 
                WHEN EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce') THEN 'annonce'
                ELSE 'standard'
            END as type
            FROM conversations c
            WHERE c.id = ?
        ");
        $stmt->execute([$convId]);
        return $stmt->fetch();
    }
    
    /**
     * Récupère les messages d'une conversation
     */
    public function getMessagesByConversation($convId, $userId, $userType) {
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
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère les pièces jointes d'un message
     */
    public function getAttachmentsByMessage($messageId) {
        $stmt = $this->pdo->prepare("
            SELECT id, message_id, file_name as nom_fichier, file_path as chemin
            FROM message_attachments 
            WHERE message_id = ?
        ");
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère les conversations d'un utilisateur
     */
    public function getConversations($userId, $userType, $dossier = 'reception') {
        $baseQuery = "
            SELECT DISTINCT c.id, c.subject as titre, 
                   CASE WHEN EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce') THEN 'annonce' ELSE 'standard' END as type,
                   c.created_at as date_creation, 
                   c.updated_at as dernier_message,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce') THEN 'annonce'
                       ELSE (SELECT status FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1)
                   END as status,
                   cp.unread_count as non_lus
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id
            WHERE cp.user_id = ? AND cp.user_type = ?
        ";
        
        $params = [$userId, $userType];
        
        switch ($dossier) {
            case 'reception':
                $baseQuery .= " AND cp.is_deleted = 0 AND cp.is_archived = 0
                               AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND (sender_id != ? OR sender_type != ?))";
                $params[] = $userId;
                $params[] = $userType;
                break;
                
            case 'envoyes':
                $baseQuery .= " AND cp.is_deleted = 0 AND cp.is_archived = 0
                               AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND sender_id = ? AND sender_type = ?)";
                $params[] = $userId;
                $params[] = $userType;
                break;
                
            case 'archives':
                $baseQuery .= " AND cp.is_archived = 1 AND cp.is_deleted = 0";
                break;
                
            case 'information':
                $baseQuery .= " AND cp.is_deleted = 0 
                               AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce')";
                break;
                
            case 'corbeille':
                $baseQuery .= " AND cp.is_deleted = 1";
                break;
                
            default:
                $baseQuery .= " AND cp.is_deleted = 0 AND cp.is_archived = 0";
        }
        
        $baseQuery .= " ORDER BY c.updated_at DESC";
        
        $stmt = $this->pdo->prepare($baseQuery);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Vérifie si un utilisateur est modérateur d'une conversation
     */
    public function isConversationModerator($userId, $userType, $conversationId) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM conversation_participants
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? 
            AND (is_moderator = 1 OR is_admin = 1) AND is_deleted = 0
        ");
        $stmt->execute([$conversationId, $userId, $userType]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Exécute une requête SQL optimisée avec des clauses IN
     */
    public function executeInQuery($baseQuery, $field, $values, $additionalParams = []) {
        if (empty($values)) {
            return [
                'query' => $baseQuery . " WHERE 1=0",
                'params' => []
            ];
        }
        
        // Assainir les valeurs
        $sanitizedValues = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $sanitizedValues[] = (int)$value;
            } elseif (is_string($value)) {
                $sanitizedValues[] = $value;
            }
        }
        
        // Créer des placeholders nommés
        $placeholders = [];
        $params = [];
        
        foreach ($sanitizedValues as $index => $value) {
            $paramName = "in_param_" . $index;
            $placeholders[] = ":" . $paramName;
            $params[$paramName] = $value;
        }
        
        // Ajouter les paramètres supplémentaires
        if (!empty($additionalParams)) {
            $params = array_merge($params, $additionalParams);
        }
        
        // Construire la requête finale
        $query = $baseQuery . " WHERE " . $field . " IN (" . implode(", ", $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Marque un message comme lu avec contrôle optimiste de concurrence
     */
    public function markMessageAsRead($messageId, $userId, $userType, $maxRetries = 3) {
        // Récupérer l'ID de la conversation pour ce message
        $stmt = $this->pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $convId = $stmt->fetchColumn();
        
        if (!$convId) return false;
        
        $retriesLeft = $maxRetries;
        
        while ($retriesLeft > 0) {
            try {
                $this->pdo->beginTransaction();
                
                // Récupérer la version actuelle
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
                    $updateStmt = $this->pdo->prepare("
                        UPDATE conversation_participants 
                        SET last_read_message_id = ?, version = version + 1, last_read_at = NOW()
                        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND version = ?
                    ");
                    $updateStmt->execute([$messageId, $convId, $userId, $userType, $currentVersion]);
                    
                    if ($updateStmt->rowCount() > 0) {
                        // Marquer les notifications comme lues
                        $updateNotif = $this->pdo->prepare("
                            UPDATE message_notifications 
                            SET is_read = 1, read_at = NOW() 
                            WHERE message_id = ? AND user_id = ? AND user_type = ? AND is_read = 0
                        ");
                        $updateNotif->execute([$messageId, $userId, $userType]);
                        
                        // Recalculer le compteur
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
                        $this->pdo->rollBack();
                        $retriesLeft--;
                        usleep(100000); // 100 ms
                        continue;
                    }
                } else {
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
                
                usleep(100000); // 100 ms
            }
        }
        
        return false;
    }
}