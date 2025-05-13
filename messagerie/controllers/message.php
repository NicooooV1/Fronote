<?php
/**
 * Contrôleur pour les actions sur les messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../models/conversation.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Gère l'envoi d'un nouveau message
 * @param int $convId
 * @param array $user
 * @param string $contenu
 * @param string $importance
 * @param int|null $parentMessageId
 * @param array $filesData
 * @return array
 */
function handleSendMessage($convId, $user, $contenu, $importance = 'normal', $parentMessageId = null, $filesData = []) {
    try {
        // Vérifier que l'utilisateur peut répondre à cette conversation
        $conversation = getConversationInfo($convId);
        if (!$conversation) {
            return [
                'success' => false,
                'message' => "Conversation introuvable"
            ];
        }
        
        // Vérifier si l'utilisateur peut répondre à une annonce
        if (!canReplyToAnnouncement($user['id'], $user['type'], $convId, $conversation['type'])) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à répondre à cette annonce"
            ];
        }
        
        // Vérifier si l'utilisateur peut définir l'importance
        if (!canSetMessageImportance($user['type'])) {
            $importance = 'normal';
        }
        
        // Ajouter le message
        $messageId = addMessage(
            $convId,
            $user['id'],
            $user['type'],
            $contenu,
            $importance,
            false, // Est annonce
            false, // Notification obligatoire
            $parentMessageId,
            'standard',
            $filesData
        );
        
        if ($messageId) {
            return [
                'success' => true,
                'message' => "Message envoyé avec succès",
                'messageId' => $messageId
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de l'envoi du message"
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
 * Gère l'envoi d'une annonce
 * @param array $user
 * @param string $titre
 * @param string $contenu
 * @param array $participants
 * @param bool $notificationObligatoire
 * @param array $filesData
 * @return array
 */
function handleSendAnnouncement($user, $titre, $contenu, $participants, $notificationObligatoire = true, $filesData = []) {
    try {
        // Vérifier que l'utilisateur a le droit de créer des annonces
        $canSendAnnouncement = in_array($user['type'], ['vie_scolaire', 'administrateur']);
        if (!$canSendAnnouncement) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à créer des annonces"
            ];
        }
        
        // Création de la conversation
        $convId = createConversation(
            $titre,
            'annonce',
            $user['id'],
            $user['type'],
            $participants
        );
        
        // Envoi du message d'annonce
        $messageId = addMessage(
            $convId,
            $user['id'],
            $user['type'],
            $contenu,
            'important', // Importance
            true, // Est annonce
            $notificationObligatoire, // Notification obligatoire
            false, // Accusé de réception
            null, // Parent message ID 
            'annonce', // Type message
            $filesData
        );
        
        if ($messageId) {
            return [
                'success' => true,
                'message' => "L'annonce a été envoyée avec succès à " . count($participants) . " destinataire(s)",
                'convId' => $convId,
                'messageId' => $messageId
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de l'envoi de l'annonce"
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
 * Gère l'envoi d'un message à une classe
 * @param array $user
 * @param string $classe
 * @param string $titre
 * @param string $contenu
 * @param string $importance
 * @param bool $notificationObligatoire
 * @param bool $includeParents
 * @param array $filesData
 * @return array
 */
function handleSendClassMessage($user, $classe, $titre, $contenu, $importance = 'normal', $notificationObligatoire = false, $includeParents = false, $filesData = []) {
    try {
        // Vérifier que l'utilisateur est un professeur
        if ($user['type'] !== 'professeur') {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à envoyer des messages à des classes"
            ];
        }
        
        // Envoi du message à la classe
        $convId = sendMessageToClass(
            $user['id'],
            $classe,
            $titre,
            $contenu,
            $importance,
            $notificationObligatoire,
            $includeParents,
            $filesData
        );
        
        if ($convId) {
            return [
                'success' => true,
                'message' => "Votre message a été envoyé avec succès à la classe " . htmlspecialchars($classe),
                'convId' => $convId
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de l'envoi du message à la classe"
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
 * Gère le marquage d'un message comme lu
 * @param int $messageId
 * @param array $user
 * @return array
 */
function handleMarkMessageAsRead($messageId, $user) {
    try {
        // Récupérer l'ID de la conversation pour ce message
        global $pdo;
        $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [
                'success' => false,
                'message' => "Message introuvable"
            ];
        }
        
        $convId = $result['conversation_id'];
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkStmt = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkStmt->execute([$convId, $user['id'], $user['type']]);
        
        if (!$checkStmt->fetch()) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à ce message"
            ];
        }
        
        // Marquer comme lu
        $result = markMessageAsRead($messageId, $user['id'], $user['type']);
        
        if ($result) {
            // Récupérer le statut de lecture mis à jour
            $readStatus = getMessageReadStatus($messageId);
            
            return [
                'success' => true,
                'message' => "Message marqué comme lu",
                'readStatus' => $readStatus
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors du marquage du message comme lu"
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
 * Gère le marquage d'un message comme non lu
 * @param int $messageId
 * @param array $user
 * @return array
 */
function handleMarkMessageAsUnread($messageId, $user) {
    try {
        // Récupérer l'ID de la conversation pour ce message
        global $pdo;
        $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [
                'success' => false,
                'message' => "Message introuvable"
            ];
        }
        
        $convId = $result['conversation_id'];
        
        // Vérifier que l'utilisateur est participant à la conversation
        $checkStmt = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkStmt->execute([$convId, $user['id'], $user['type']]);
        
        if (!$checkStmt->fetch()) {
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à accéder à ce message"
            ];
        }
        
        // Marquer comme non lu
        $result = markMessageAsUnread($messageId, $user['id'], $user['type']);
        
        if ($result) {
            // Récupérer le statut de lecture mis à jour
            $readStatus = getMessageReadStatus($messageId);
            
            return [
                'success' => true,
                'message' => "Message marqué comme non lu",
                'readStatus' => $readStatus
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors du marquage du message comme non lu"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}