<?php
/**
 * Repository pour les conversations
 */
class ConversationRepository {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Récupère une conversation par son ID
     */
    public function findById($id) {
        $stmt = $this->pdo->prepare("
            SELECT c.id, c.subject as titre, 
            CASE 
                WHEN EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce') THEN 'annonce'
                ELSE 'standard'
            END as type
            FROM conversations c
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Récupère les conversations d'un utilisateur
     */
    public function getByUser($userId, $userType, $folder = 'reception') {
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
        
        switch ($folder) {
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
     * Crée une nouvelle conversation
     */
    public function create($titre, $type, $createurId, $createurType, $participants) {
        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO conversations (subject, created_at, updated_at) VALUES (?, NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$titre]);
            $convId = $this->pdo->lastInsertId();
            
            $sql = "INSERT INTO conversation_participants 
                    (conversation_id, user_id, user_type, joined_at, is_admin) 
                    VALUES (?, ?, ?, NOW(), 1)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$convId, $createurId, $createurType]);
            
            $sql = "INSERT INTO conversation_participants 
                    (conversation_id, user_id, user_type, joined_at) 
                    VALUES (?, ?, ?, NOW())";
            $stmt = $this->pdo->prepare($sql);
            foreach ($participants as $p) {
                $stmt->execute([$convId, $p['id'], $p['type']]);
            }
            
            $this->pdo->commit();
            return $convId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Archive une conversation
     */
    public function archive($convId, $userId, $userType) {
        $stmt = $this->pdo->prepare("
            UPDATE conversation_participants 
            SET is_archived = 1 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $stmt->execute([$convId, $userId, $userType]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Désarchive une conversation
     */
    public function unarchive($convId, $userId, $userType) {
        $stmt = $this->pdo->prepare("
            UPDATE conversation_participants 
            SET is_archived = 0 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $stmt->execute([$convId, $userId, $userType]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Supprime une conversation
     */
    public function delete($convId, $userId, $userType) {
        $this->pdo->beginTransaction();
        try {
            // Marquer les notifications comme lues
            $stmt = $this->pdo->prepare("
                UPDATE message_notifications AS mn
                JOIN messages AS m ON mn.message_id = m.id
                SET mn.is_read = 1
                WHERE m.conversation_id = ? AND mn.user_id = ? AND mn.user_type = ? AND mn.is_read = 0
            ");
            $stmt->execute([$convId, $userId, $userType]);
            
            // Mettre à jour la dernière lecture
            $stmt = $this->pdo->prepare("
                UPDATE conversation_participants 
                SET last_read_at = NOW() 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $stmt->execute([$convId, $userId, $userType]);
            
            // Marquer comme supprimé
            $stmt = $this->pdo->prepare("
                UPDATE conversation_participants 
                SET is_deleted = 1 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $stmt->execute([$convId, $userId, $userType]);
            
            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Supprime définitivement une conversation
     */
    public function deletePermanently($convId, $userId, $userType) {
        $stmt = $this->pdo->prepare("
            DELETE FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $stmt->execute([$convId, $userId, $userType]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Restaure une conversation
     */
    public function restore($convId, $userId, $userType) {
        $this->pdo->beginTransaction();
        
        try {
            // Vérifier si un participant actif existe déjà
            $checkStmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
            ");
            $checkStmt->execute([$convId, $userId, $userType]);
            $exists = $checkStmt->fetchColumn() > 0;
            
            if ($exists) {
                $this->pdo->commit();
                return true;
            }
            
            // Récupérer l'ID du participant supprimé
            $getIdStmt = $this->pdo->prepare("
                SELECT id FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 1
                ORDER BY id ASC LIMIT 1
            ");
            $getIdStmt->execute([$convId, $userId, $userType]);
            $recordId = $getIdStmt->fetchColumn();
            
            if ($recordId) {
                // Restaurer le participant
                $updateStmt = $this->pdo->prepare("
                    UPDATE conversation_participants 
                    SET is_deleted = 0, is_archived = 0 
                    WHERE id = ?
                ");
                $updateStmt->execute([$recordId]);
                
                // Supprimer les doublons
                $deleteOthersStmt = $this->pdo->prepare("
                    DELETE FROM conversation_participants 
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND id != ?
                ");
                $deleteOthersStmt->execute([$convId, $userId, $userType, $recordId]);
            } else {
                // Créer un nouveau participant
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO conversation_participants 
                    (conversation_id, user_id, user_type, joined_at, is_deleted, is_archived)
                    VALUES (?, ?, ?, NOW(), 0, 0)
                ");
                $insertStmt->execute([$convId, $userId, $userType]);
            }
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}