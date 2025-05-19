<?php
/**
 * Modèle pour la gestion des conversations
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Récupère les conversations d'un utilisateur
 * @param int $userId
 * @param string $userType
 * @param string $dossier
 * @return array
 */
function getConversations($userId, $userType, $dossier = 'reception') {
    global $pdo;
    
    $baseQuery = "
        SELECT c.id, c.subject as titre, 
               CASE 
                   WHEN EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce') THEN 'annonce'
                   ELSE 'standard'
               END as type,
               c.created_at as date_creation, 
               (SELECT MAX(created_at) FROM messages WHERE conversation_id = c.id) as dernier_message,
               (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as apercu,
               (SELECT status FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as status,
               cp.unread_count as non_lus
        FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id
        WHERE cp.user_id = ? AND cp.user_type = ?
    ";
    
    // Ajouter des conditions selon le dossier
    $params = [$userId, $userType];
    
    switch ($dossier) {
        case 'archives':
            $baseQuery .= " AND cp.is_archived = 1 AND cp.is_deleted = 0";
            break;
        case 'corbeille':
            $baseQuery .= " AND cp.is_deleted = 1";
            break;
        case 'envoyes':
            $baseQuery .= " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND EXISTS (
                            SELECT 1 FROM messages 
                            WHERE conversation_id = c.id 
                            AND sender_id = ? AND sender_type = ?
                          )";
            $params[] = $userId;
            $params[] = $userType;
            break;
        case 'information':
            $baseQuery .= " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce')";
            break;
        case 'reception':
        default:
            $baseQuery .= " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND NOT EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce')";
    }
    
    $baseQuery .= " ORDER BY c.updated_at DESC";
    
    $stmt = $pdo->prepare($baseQuery);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enrichir les données avec les participants
    foreach ($conversations as &$conversation) {
        $participantsStmt = $pdo->prepare("
            SELECT 
                cp.user_id, cp.user_type, cp.is_admin, cp.is_moderator,
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
            WHERE cp.conversation_id = ? AND cp.is_deleted = 0
        ");
        $participantsStmt->execute([$conversation['id']]);
        $conversation['participants'] = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $conversations;
}

/**
 * Crée une nouvelle conversation
 * @param string $titre
 * @param string $type
 * @param int $createurId
 * @param string $createurType
 * @param array $participants
 * @return int
 */
function createConversation($titre, $type, $createurId, $createurType, $participants) {
    global $pdo;
    
    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO conversations (subject, created_at, updated_at) VALUES (?, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$titre]);
        $convId = $pdo->lastInsertId();
        
        $sql = "INSERT INTO conversation_participants 
                (conversation_id, user_id, user_type, joined_at, is_admin) 
                VALUES (?, ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$convId, $createurId, $createurType]);
        
        $sql = "INSERT INTO conversation_participants 
                (conversation_id, user_id, user_type, joined_at) 
                VALUES (?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        foreach ($participants as $p) {
            $stmt->execute([$convId, $p['id'], $p['type']]);
        }
        
        $pdo->commit();
        return $convId;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Récupère les informations d'une conversation
 * @param int $convId
 * @return array|false
 */
function getConversationInfo($convId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
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
 * Archiver une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function archiveConversation($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_archived = 1 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Désarchive une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function unarchiveConversation($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_archived = 0 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Supprimer une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function deleteConversation($convId, $userId, $userType) {
    global $pdo;
    
    // Marquer les notifications comme lues d'abord
    $stmt = $pdo->prepare("
        UPDATE message_notifications AS mn
        JOIN messages AS m ON mn.message_id = m.id
        SET mn.is_read = 1
        WHERE m.conversation_id = ? AND mn.user_id = ? AND mn.user_type = ? AND mn.is_read = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    // Mettre à jour la dernière lecture
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    // Marquer comme supprimé
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_deleted = 1 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Restaure une conversation depuis la corbeille
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function restoreConversation($convId, $userId, $userType) {
    global $pdo;
    
    $pdo->beginTransaction();
    
    try {
        // Vérifier si un participant actif existe déjà
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkStmt->execute([$convId, $userId, $userType]);
        $exists = $checkStmt->fetchColumn() > 0;
        
        if ($exists) {
            $pdo->commit();
            return true;
        }
        
        // Récupérer l'ID du participant supprimé
        $getIdStmt = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 1
            ORDER BY id ASC LIMIT 1
        ");
        $getIdStmt->execute([$convId, $userId, $userType]);
        $recordId = $getIdStmt->fetchColumn();
        
        if ($recordId) {
            // Restaurer le participant
            $updateStmt = $pdo->prepare("
                UPDATE conversation_participants 
                SET is_deleted = 0, is_archived = 0 
                WHERE id = ?
            ");
            $updateStmt->execute([$recordId]);
            
            // Supprimer les doublons
            $deleteOthersStmt = $pdo->prepare("
                DELETE FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND id != ?
            ");
            $deleteOthersStmt->execute([$convId, $userId, $userType, $recordId]);
        } else {
            // Créer un nouveau participant
            $insertStmt = $pdo->prepare("
                INSERT INTO conversation_participants 
                (conversation_id, user_id, user_type, joined_at, is_deleted, is_archived)
                VALUES (?, ?, ?, NOW(), 0, 0)
            ");
            $insertStmt->execute([$convId, $userId, $userType]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Supprime définitivement une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function deletePermanently($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        DELETE FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Supprime définitivement plusieurs conversations pour un utilisateur
 * @param array $convIds
 * @param int $userId
 * @param string $userType
 * @return int
 */
function deleteMultipleConversations($convIds, $userId, $userType) {
    global $pdo;
    
    if (empty($convIds)) {
        return 0;
    }
    
    $placeholders = implode(',', array_fill(0, count($convIds), '?'));
    
    $stmt = $pdo->prepare("
        DELETE FROM conversation_participants 
        WHERE conversation_id IN ($placeholders) AND user_id = ? AND user_type = ?
    ");
    
    $params = array_merge($convIds, [$userId, $userType]);
    $stmt->execute($params);
    
    return $stmt->rowCount();
}