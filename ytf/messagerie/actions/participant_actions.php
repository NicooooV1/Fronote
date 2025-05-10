<?php
/**
 * /actions/participant_actions.php - Actions sur les participants
 * 
 * Ce fichier contient les fonctions d'action sur les participants qui
 * servent d'intermédiaire entre les contrôleurs et les fonctions core.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/message_functions.php';
require_once __DIR__ . '/../includes/auth.php';

/**
 * Gère l'ajout d'un participant à une conversation
 * @param int $convId ID de la conversation
 * @param int $participantId ID du participant à ajouter
 * @param string $participantType Type du participant
 * @param array $user Informations sur l'utilisateur
 * @return array Résultat de l'action [success, message]
 */
function handleAddParticipant($convId, $participantId, $participantType, $user) {
    try {
        // Vérifier que l'utilisateur est modérateur de la conversation
        if (!isConversationModerator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à ajouter des participants"
            ];
        }
        
        // Ajouter le participant
        $result = addParticipantToConversation(
            $convId,
            $participantId,
            $participantType,
            $user['id'],
            $user['type']
        );
        
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
 * Gère la promotion d'un participant au rôle de modérateur
 * @param int $convId ID de la conversation
 * @param int $participantId ID du participant à promouvoir
 * @param array $user Informations sur l'utilisateur
 * @return array Résultat de l'action [success, message]
 */
function handlePromoteToModerator($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est administrateur de la conversation
        if (!isConversationCreator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à promouvoir des modérateurs"
            ];
        }
        
        // Promouvoir en modérateur
        $result = promoteToModerator(
            $participantId,
            $user['id'],
            $user['type'],
            $convId
        );
        
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
 * @param int $convId ID de la conversation
 * @param int $participantId ID du participant à rétrograder
 * @param array $user Informations sur l'utilisateur
 * @return array Résultat de l'action [success, message]
 */
function handleDemoteFromModerator($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est administrateur de la conversation
        if (!isConversationCreator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à rétrograder des modérateurs"
            ];
        }
        
        // Rétrograder le modérateur
        $result = demoteFromModerator(
            $participantId,
            $user['id'],
            $user['type'],
            $convId
        );
        
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
 * Gère la suppression d'un participant d'une conversation
 * @param int $convId ID de la conversation
 * @param int $participantId ID du participant à supprimer
 * @param array $user Informations sur l'utilisateur
 * @return array Résultat de l'action [success, message]
 */
function handleRemoveParticipant($convId, $participantId, $user) {
    try {
        // Vérifier que l'utilisateur est modérateur ou administrateur de la conversation
        if (!isConversationModerator($user['id'], $user['type'], $convId)) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à supprimer des participants"
            ];
        }
        
        // Supprimer le participant
        $result = removeParticipant(
            $participantId,
            $user['id'],
            $user['type'],
            $convId
        );
        
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

/**
 * Récupère la liste des participants disponibles pour ajout
 * @param int $convId ID de la conversation
 * @param string $type Type de participant
 * @return array Liste des participants disponibles
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

/**
 * Récupère le HTML pour la liste des participants
 * @param int $convId ID de la conversation
 * @param array $user Informations sur l'utilisateur
 * @return string HTML de la liste des participants
 */
function getParticipantsListHtml($convId, $user) {
    try {
        // Vérifier que l'utilisateur est participant à la conversation
        $checkParticipant = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkParticipant->execute([$convId, $user['id'], $user['type']]);
        if (!$checkParticipant->fetch()) {
            throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
        }
        
        // Récupérer les informations de l'utilisateur
        $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
        $isAdmin = $participantInfo && $participantInfo['is_admin'] == 1;
        $isModerator = $participantInfo && ($participantInfo['is_moderator'] == 1 || $isAdmin);
        $isDeleted = $participantInfo && $participantInfo['is_deleted'] == 1;
        
        // Récupérer les participants
        $participants = getParticipants($convId);
        
        // Générer le HTML (cette partie devrait être dans un template)
        ob_start();
        include __DIR__ . '/../templates/participant-list.php';
        return ob_get_clean();
        
    } catch (Exception $e) {
        return "<p>Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}