<?php
/**
 * Modèle pour la gestion des participants
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Récupère les participants d'une conversation
 * @param int $convId
 * @return array
 */
function getParticipants($convId) {
    global $pdo;
    $sql = "
        SELECT cp.id, cp.user_id as utilisateur_id, cp.user_type as utilisateur_type, 
               cp.is_admin as est_administrateur, cp.is_moderator as est_moderateur, 
               cp.is_deleted as a_quitte, cp.last_read_message_id, cp.version,
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
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$convId]);
    return $stmt->fetchAll();
}

/**
 * Récupère les informations d'un participant dans une conversation
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return array|false
 */
function getParticipantInfo($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT * FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->fetch();
}

/**
 * Met à jour le last_read_message_id avec contrôle optimiste de concurrence
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @param int $messageId
 * @param int $maxRetries Nombre maximum de tentatives en cas de conflit
 * @return bool
 */
function updateLastReadMessageId($convId, $userId, $userType, $messageId, $maxRetries = 3) {
    global $pdo;
    
    $retriesLeft = $maxRetries;
    
    while ($retriesLeft > 0) {
        try {
            $pdo->beginTransaction();
            
            // Récupérer la version actuelle
            $getVersionStmt = $pdo->prepare("
                SELECT last_read_message_id, version 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                FOR UPDATE
            ");
            $getVersionStmt->execute([$convId, $userId, $userType]);
            $participantInfo = $getVersionStmt->fetch();
            
            if (!$participantInfo) {
                // Participant inexistant
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
                
                // Vérifier que la mise à jour a réussi
                if ($updateStmt->rowCount() > 0) {
                    $pdo->commit();
                    return true;
                } else {
                    // Conflit détecté, la version a changé, on fait un rollback
                    // et on réessaie
                    $pdo->rollBack();
                    $retriesLeft--;
                    // Attendre un peu avant de réessayer
                    usleep(100000); // 100 ms
                    continue;
                }
            } else {
                // Aucune mise à jour nécessaire
                $pdo->commit();
                return true;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retriesLeft--;
            
            if ($retriesLeft <= 0) {
                // Plus de tentatives, l'opération a échoué
                return false;
            }
            
            // Attendre un peu avant de réessayer
            usleep(100000); // 100 ms
        }
    }
    
    // Plus de tentatives, l'opération a échoué
    return false;
}

/**
 * Vérifie si un utilisateur est modérateur dans une conversation
 * @param int $userId
 * @param string $userType
 * @param int $conversationId
 * @return bool
 */
function isConversationModerator($userId, $userType, $conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? 
        AND (is_moderator = 1 OR is_admin = 1)
    ");
    $stmt->execute([$conversationId, $userId, $userType]);
    
    return $stmt->fetch() !== false;
}

/**
 * Vérifie si un utilisateur est le créateur d'une conversation
 * @param int $userId
 * @param string $userType
 * @param int $conversationId
 * @return bool
 */
function isConversationCreator($userId, $userType, $conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_admin = 1
    ");
    $stmt->execute([$conversationId, $userId, $userType]);
    
    return $stmt->fetch() !== false;
}

/**
 * Promouvoir un participant au statut de modérateur
 * @param int $participantId
 * @param int $promoterId
 * @param string $promoterType
 * @param int $conversationId
 * @return bool
 */
function promoteToModerator($participantId, $promoterId, $promoterType, $conversationId) {
    global $pdo;
    
    // Vérifier que le promoteur est admin de la conversation
    if (!isConversationCreator($promoterId, $promoterType, $conversationId)) {
        throw new Exception("Vous n'êtes pas autorisé à promouvoir des modérateurs");
    }
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_moderator = 1 
        WHERE id = ? AND conversation_id = ?
    ");
    $stmt->execute([$participantId, $conversationId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Rétrograder un modérateur au statut normal
 * @param int $participantId
 * @param int $demoterId
 * @param string $demoterType
 * @param int $conversationId
 * @return bool
 */
function demoteFromModerator($participantId, $demoterId, $demoterType, $conversationId) {
    global $pdo;
    
    // Vérifier que la personne qui rétrograde est admin de la conversation
    if (!isConversationCreator($demoterId, $demoterType, $conversationId)) {
        throw new Exception("Vous n'êtes pas autorisé à rétrograder des modérateurs");
    }
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_moderator = 0 
        WHERE id = ? AND conversation_id = ?
    ");
    $stmt->execute([$participantId, $conversationId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Ajouter un participant à une conversation
 * @param int $conversationId
 * @param int $userId
 * @param string $userType
 * @param int $adderId
 * @param string $adderType
 * @return bool
 */
function addParticipantToConversation($conversationId, $userId, $userType, $adderId, $adderType) {
    global $pdo;
    
    // Vérifier que l'ajouteur est participant à la conversation
    if (!isConversationModerator($adderId, $adderType, $conversationId)) {
        throw new Exception("Vous n'êtes pas autorisé à ajouter des participants");
    }
    
    // Vérifier que l'utilisateur n'est pas déjà participant
    $check = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $check->execute([$conversationId, $userId, $userType]);
    
    if ($check->fetch()) {
        throw new Exception("Cet utilisateur est déjà participant à la conversation");
    }
    
    // Ajouter le participant
    $add = $pdo->prepare("
        INSERT INTO conversation_participants 
        (conversation_id, user_id, user_type, joined_at, version) 
        VALUES (?, ?, ?, NOW(), 1)
    ");
    $add->execute([$conversationId, $userId, $userType]);
    
    return $add->rowCount() > 0;
}

/**
 * Supprime un participant d'une conversation
 * @param int $participantId
 * @param int $removerId
 * @param string $removerType
 * @param int $conversationId
 * @return bool
 */
function removeParticipant($participantId, $removerId, $removerType, $conversationId) {
    global $pdo;
    
    // Vérifier que la personne qui supprime est admin ou modérateur
    if (!isConversationModerator($removerId, $removerType, $conversationId)) {
        throw new Exception("Vous n'êtes pas autorisé à supprimer des participants");
    }
    
    // Récupérer les informations du participant à supprimer
    $stmt = $pdo->prepare("
        SELECT user_id, user_type FROM conversation_participants
        WHERE id = ? AND conversation_id = ?
    ");
    $stmt->execute([$participantId, $conversationId]);
    $participant = $stmt->fetch();
    
    if (!$participant) {
        throw new Exception("Participant introuvable");
    }
    
    // Vérifier qu'on ne supprime pas un admin (sauf si on est admin soi-même)
    $checkAdmin = $pdo->prepare("
        SELECT is_admin FROM conversation_participants
        WHERE id = ? AND is_admin = 1
    ");
    $checkAdmin->execute([$participantId]);
    $isAdmin = $checkAdmin->fetch();
    
    if ($isAdmin && !isConversationCreator($removerId, $removerType, $conversationId)) {
        throw new Exception("Vous ne pouvez pas supprimer l'administrateur de la conversation");
    }
    
    // Marquer comme supprimé pour l'utilisateur
    $deleteForUser = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_deleted = 1, version = version + 1
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $deleteForUser->execute([$conversationId, $participant['user_id'], $participant['user_type']]);
    
    return true;
}

/**
 * Récupère les participants disponibles pour ajout
 * @param int $convId
 * @param string $type
 * @return array
 */
function getAvailableParticipants($convId, $type) {
    global $pdo;
    
    // Récupérer la liste des participants déjà dans la conversation
    $currentParticipants = [];
    $stmt = $pdo->prepare("
        SELECT user_id FROM conversation_participants 
        WHERE conversation_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $type]);
    $currentParticipants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Récupérer la liste des participants potentiels
    $participants = [];
    $table = '';
    $champs = '';
    
    switch ($type) {
        case 'eleve':
            $table = 'eleves';
            $champs = "id, CONCAT(prenom, ' ', nom, ' (', classe, ')') as nom_complet";
            break;
        case 'parent':
            $table = 'parents';
            $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
            break;
        case 'professeur':
            $table = 'professeurs';
            $champs = "id, CONCAT(prenom, ' ', nom, ' (', matiere, ')') as nom_complet";
            break;
        case 'vie_scolaire':
            $table = 'vie_scolaire';
            $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
            break;
        case 'administrateur':
            $table = 'administrateurs';
            $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
            break;
    }
    
    if (!empty($table)) {
        $sql = "SELECT $champs FROM $table";
        
        if (!empty($currentParticipants)) {
            $placeholders = implode(',', array_fill(0, count($currentParticipants), '?'));
            $sql .= " WHERE id NOT IN ($placeholders)";
        }
        
        $sql .= " ORDER BY nom";
        
        $stmt = $pdo->prepare($sql);
        
        if (!empty($currentParticipants)) {
            $stmt->execute($currentParticipants);
        } else {
            $stmt->execute();
        }
        
        $participants = $stmt->fetchAll();
    }
    
    return $participants;
}