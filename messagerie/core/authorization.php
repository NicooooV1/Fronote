<?php
/**
 * Système d'autorisation centralisé
 */
require_once __DIR__ . '/../config/config.php';

/**
 * Permissions disponibles dans l'application
 */
const PERMISSION_VIEW_CONVERSATION = 'view_conversation';
const PERMISSION_SEND_MESSAGE = 'send_message';
const PERMISSION_ARCHIVE_CONVERSATION = 'archive_conversation';
const PERMISSION_DELETE_CONVERSATION = 'delete_conversation';
const PERMISSION_SEND_ANNOUNCEMENT = 'send_announcement';
const PERMISSION_MANAGE_PARTICIPANTS = 'manage_participants';
const PERMISSION_PROMOTE_MODERATOR = 'promote_moderator';
const PERMISSION_SEND_CLASS_MESSAGE = 'send_class_message';

/**
 * Vérifier si un utilisateur a une permission spécifique
 * @param array $user Utilisateur courant
 * @param string $permission Permission à vérifier
 * @param array $context Contexte (ex: ID de conversation)
 * @return bool
 */
function hasPermission($user, $permission, $context = []) {
    // Vérifier les permissions globales basées sur le rôle
    $rolePermissions = getRolePermissions($user['type']);
    
    if (in_array($permission, $rolePermissions)) {
        return true;
    }
    
    // Vérifier les permissions spécifiques au contexte
    switch ($permission) {
        case PERMISSION_VIEW_CONVERSATION:
        case PERMISSION_SEND_MESSAGE:
            return isConversationParticipant($user['id'], $user['type'], $context['conversation_id'] ?? 0);
            
        case PERMISSION_ARCHIVE_CONVERSATION:
        case PERMISSION_DELETE_CONVERSATION:
            return isConversationParticipant($user['id'], $user['type'], $context['conversation_id'] ?? 0);
            
        case PERMISSION_MANAGE_PARTICIPANTS:
            return isConversationModerator($user['id'], $user['type'], $context['conversation_id'] ?? 0);
            
        case PERMISSION_PROMOTE_MODERATOR:
            return isConversationCreator($user['id'], $user['type'], $context['conversation_id'] ?? 0);
    }
    
    return false;
}

/**
 * Obtient les permissions basées sur le rôle de l'utilisateur
 * @param string $role Rôle de l'utilisateur
 * @return array Liste des permissions
 */
function getRolePermissions($role) {
    $permissions = [
        // Permissions de base pour tous les rôles
        'eleve' => [
            PERMISSION_SEND_MESSAGE,
        ],
        'parent' => [
            PERMISSION_SEND_MESSAGE,
        ],
        'professeur' => [
            PERMISSION_SEND_MESSAGE,
            PERMISSION_SEND_CLASS_MESSAGE,
        ],
        'vie_scolaire' => [
            PERMISSION_SEND_MESSAGE,
            PERMISSION_SEND_ANNOUNCEMENT,
        ],
        'administrateur' => [
            PERMISSION_SEND_MESSAGE,
            PERMISSION_SEND_ANNOUNCEMENT,
            PERMISSION_MANAGE_PARTICIPANTS,
            PERMISSION_PROMOTE_MODERATOR,
        ],
    ];
    
    return $permissions[$role] ?? [];
}

/**
 * Vérifie que l'utilisateur a la permission requise ou lance une exception
 * @param array $user Utilisateur courant
 * @param string $permission Permission requise
 * @param array $context Contexte
 * @throws Exception
 */
function requirePermission($user, $permission, $context = []) {
    if (!hasPermission($user, $permission, $context)) {
        throw new Exception("Vous n'avez pas les droits nécessaires pour effectuer cette action");
    }
}

// Fonctions existantes réutilisées pour la compatibilité
function isConversationParticipant($userId, $userType, $conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$conversationId, $userId, $userType]);
    
    return $stmt->fetch() !== false;
}

function isConversationModerator($userId, $userType, $conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? 
        AND (is_moderator = 1 OR is_admin = 1) AND is_deleted = 0
    ");
    $stmt->execute([$conversationId, $userId, $userType]);
    
    return $stmt->fetch() !== false;
}

function isConversationCreator($userId, $userType, $conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_admin = 1 AND is_deleted = 0
    ");
    $stmt->execute([$conversationId, $userId, $userType]);
    
    return $stmt->fetch() !== false;
}