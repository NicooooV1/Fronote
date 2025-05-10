<?php
/**
 * Contrôleur pour les actions sur les conversations
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/conversation.php';
require_once __DIR__ . '/../models/participant.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Gère l'archivage d'une conversation
 * @param int $convId
 * @param array $user
 * @return array
 */
function handleArchiveConversation($convId, $user) {
    try {
        // Vérifier que l'utilisateur est participant à la conversation
        $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
        if (!$participantInfo || $participantInfo['is_deleted'] == 1) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à cette conversation"
            ];
        }
        
        // Archiver la conversation
        $result = archiveConversation($convId, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "La conversation a été archivée avec succès",
                'redirect' => "index.php?folder=archives"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de l'archivage de la conversation"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Gère la suppression d'une conversation
 * @param int $convId
 * @param array $user
 * @return array
 */
function handleDeleteConversation($convId, $user) {
    try {
        // Vérifier que l'utilisateur est participant à la conversation
        $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
        if (!$participantInfo) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à cette conversation"
            ];
        }
        
        // Supprimer la conversation
        $result = deleteConversation($convId, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "La conversation a été supprimée avec succès",
                'redirect' => "index.php?folder=corbeille"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la suppression de la conversation"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Gère la restauration d'une conversation depuis la corbeille
 * @param int $convId
 * @param array $user
 * @return array
 */
function handleRestoreConversation($convId, $user) {
    try {
        // Vérifier que l'utilisateur est participant à la conversation
        $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
        if (!$participantInfo) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à cette conversation"
            ];
        }
        
        // Restaurer la conversation
        $result = restoreConversation($convId, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "La conversation a été restaurée avec succès",
                'redirect' => "conversation.php?id=$convId"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la restauration de la conversation"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Gère la suppression définitive d'une conversation
 * @param int $convId
 * @param array $user
 * @return array
 */
function handlePermanentDelete($convId, $user) {
    try {
        // Vérifier que l'utilisateur est participant à la conversation
        $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
        if (!$participantInfo) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à cette conversation"
            ];
        }
        
        // Supprimer définitivement la conversation
        $result = deletePermanently($convId, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "La conversation a été définitivement supprimée",
                'redirect' => "index.php?folder=corbeille"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la suppression définitive de la conversation"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Gère la suppression multiple de conversations
 * @param array $convIds
 * @param array $user
 * @return array
 */
function handleMultipleDelete($convIds, $user) {
    try {
        global $pdo;
        
        $pdo->beginTransaction();
        
        // Supprimer les conversations
        $count = deleteMultipleConversations($convIds, $user['id'], $user['type']);
        
        $pdo->commit();
        
        if ($count > 0) {
            return [
                'success' => true,
                'message' => "$count conversation(s) supprimée(s) avec succès",
                'count' => $count
            ];
        } else {
            return [
                'success' => false,
                'message' => "Aucune conversation n'a pu être supprimée"
            ];
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Crée une nouvelle conversation
 * @param string $titre
 * @param string $type
 * @param array $user
 * @param array $participants
 * @return array
 */
function handleCreateConversation($titre, $type, $user, $participants) {
    try {
        // Créer la conversation
        $convId = createConversation(
            $titre,
            $type,
            $user['id'],
            $user['type'],
            $participants
        );
        
        if ($convId) {
            return [
                'success' => true,
                'message' => "Conversation créée avec succès",
                'convId' => $convId
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la création de la conversation"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}