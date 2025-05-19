<?php
/**
 * Modèle pour la gestion des participants
 */
require_once __DIR__ . '/../config/config.php';

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
    
    // Log debug info in development mode
    if (defined('APP_ENV') && APP_ENV === 'development') {
        error_log("getParticipantInfo called with: convId=$convId, userId=$userId, userType=$userType");
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (defined('APP_ENV') && APP_ENV === 'development' && !$result) {
        error_log("No participant found for convId=$convId, userId=$userId, userType=$userType");
        
        // Debug query to see if any participants exist for this conversation
        $debugStmt = $pdo->prepare("SELECT * FROM conversation_participants WHERE conversation_id = ?");
        $debugStmt->execute([$convId]);
        $debugResults = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($debugResults) . " participants for convId=$convId");
    }
    
    return $result;
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
        (conversation_id, user_id, user_type, joined_at) 
        VALUES (?, ?, ?, NOW())
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
        SET is_deleted = 1 
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
    while ($row = $stmt->fetch()) {
        $currentParticipants[] = $row['user_id'];
    }
    
    // Debug log in development mode
    if (defined('APP_ENV') && APP_ENV === 'development') {
        error_log("Current participants for convId=$convId, type=$type: " . 
                 json_encode($currentParticipants));
    }
    
    // Créer la clause d'exclusion des participants actuels
    $excludeClause = '';
    $params = [$type];
    
    if (!empty($currentParticipants)) {
        $placeholders = implode(',', array_fill(0, count($currentParticipants), '?'));
        $excludeClause = "AND id NOT IN ($placeholders)";
        $params = array_merge([$type], $currentParticipants);
    }
    
    // Requête SQL selon le type de participant
    switch ($type) {
        case 'eleve':
            $sql = "
                SELECT id, CONCAT(prenom, ' ', nom) as nom_complet, classe
                FROM eleves
                WHERE 1=1 $excludeClause
                ORDER BY nom, prenom
            ";
            break;
        case 'parent':
            $sql = "
                SELECT id, CONCAT(prenom, ' ', nom) as nom_complet
                FROM parents
                WHERE 1=1 $excludeClause
                ORDER BY nom, prenom
            ";
            break;
        case 'professeur':
            $sql = "
                SELECT id, CONCAT(prenom, ' ', nom, ' (', matiere, ')') as nom_complet
                FROM professeurs
                WHERE 1=1 $excludeClause
                ORDER BY nom, prenom
            ";
            break;
        case 'vie_scolaire':
            $sql = "
                SELECT id, CONCAT(prenom, ' ', nom) as nom_complet
                FROM vie_scolaire
                WHERE 1=1 $excludeClause
                ORDER BY nom, prenom
            ";
            break;
        case 'administrateur':
            $sql = "
                SELECT id, CONCAT(prenom, ' ', nom) as nom_complet
                FROM administrateurs
                WHERE 1=1 $excludeClause
                ORDER BY nom, prenom
            ";
            break;
    }
    
    // Exécuter la requête
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $availableParticipants = $stmt->fetchAll();
    
    return $availableParticipants;
}