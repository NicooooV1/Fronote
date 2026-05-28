<?php
/**
 * Contrôleur pour les actions sur les participants
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/participant.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Gère l'ajout d'un participant
 * @param int $convId
 * @param int $participantId
 * @param string $participantType
 * @param array $user
 * @return array
 */
function handleAddParticipant($convId, $participantId, $participantType, $user) {
    try {
        // Vérifier que l'utilisateur a les droits pour ajouter des participants
        if (!isConversationModerator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à ajouter des participants"
            ];
        }
        
        // Ajouter le participant
        $result = addParticipantToConversation($convId, $participantId, $participantType, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Participant ajouté avec succès"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de l'ajout du participant"
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
 * Gère la promotion d'un participant en modérateur
 * @param int $convId
 * @param int $participantId
 * @param array $user
 * @return array
 */
function handlePromoteToModerator($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est admin de la conversation
        if (!isConversationCreator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à promouvoir des modérateurs"
            ];
        }
        
        // Promouvoir le participant
        $result = promoteToModerator($participantId, $user['id'], $user['type'], $convId);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Participant promu modérateur avec succès"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la promotion du participant"
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
 * Gère la rétrogradation d'un modérateur
 * @param int $convId
 * @param int $participantId
 * @param array $user
 * @return array
 */
function handleDemoteFromModerator($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est admin de la conversation
        if (!isConversationCreator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à rétrograder des modérateurs"
            ];
        }
        
        // Rétrograder le participant
        $result = demoteFromModerator($participantId, $user['id'], $user['type'], $convId);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Modérateur rétrogradé avec succès"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la rétrogradation du modérateur"
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
 * Gère la suppression d'un participant
 * @param int $convId
 * @param int $participantId
 * @param array $user
 * @return array
 */
function handleRemoveParticipant($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est modérateur ou admin de la conversation
        if (!isConversationModerator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à supprimer des participants"
            ];
        }
        
        // Supprimer le participant
        $result = removeParticipant($participantId, $user['id'], $user['type'], $convId);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Participant supprimé avec succès"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la suppression du participant"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}