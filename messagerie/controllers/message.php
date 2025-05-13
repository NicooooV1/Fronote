<?php
/**
 * Contrôleur pour les actions sur les messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/message.php';
require_once __DIR__ . '/../models/conversation.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/../core/uploader.php';

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
        // Log le début de l'opération
        logUpload("Début de handleSendMessage pour user {$user['id']} (type {$user['type']}) - Conv #{$convId}");
        
        // Vérifier que l'utilisateur peut répondre à cette conversation
        $conversation = getConversationInfo($convId);
        if (!$conversation) {
            logUpload("Conversation #{$convId} introuvable");
            return [
                'success' => false,
                'message' => "Conversation introuvable"
            ];
        }
        
        // Vérifier si l'utilisateur peut répondre à une annonce
        if (!canReplyToAnnouncement($user['id'], $user['type'], $convId, $conversation['type'])) {
            logUpload("L'utilisateur ne peut pas répondre à cette annonce");
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à répondre à cette annonce"
            ];
        }
        
        // Vérifier si l'utilisateur peut définir l'importance
        if (!canSetMessageImportance($user['type'])) {
            $importance = 'normal';
        }
        
        // Vérifier les pièces jointes avant de commencer la transaction
        $uploadedFiles = [];
        if (!empty($filesData) && isset($filesData['name']) && !empty($filesData['name'][0])) {
            try {
                logUpload("Traitement des pièces jointes pour le message");
                $uploadedFiles = handleFileUploads($filesData);
                logUpload("Pièces jointes traitées avec succès", $uploadedFiles);
            } catch (Exception $e) {
                logUpload("Erreur lors du traitement des pièces jointes: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => "Erreur lors du traitement des pièces jointes: " . $e->getMessage()
                ];
            }
        }
        
        // Ajouter le message
        global $pdo;
        $pdo->beginTransaction();
        
        try {
            // Insérer le message en base de données
            logUpload("Insertion du message en base de données");
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
                [] // On traite les fichiers séparément
            );
            
            if (!$messageId) {
                throw new Exception("Échec de l'insertion du message en base de données");
            }
            
            // Sauvegarder les pièces jointes en base de données
            if (!empty($uploadedFiles)) {
                logUpload("Sauvegarde des métadonnées des pièces jointes en base de données");
                if (!saveAttachments($pdo, $messageId, $uploadedFiles)) {
                    throw new Exception("Échec de la sauvegarde des pièces jointes en base de données");
                }
            }
            
            $pdo->commit();
            
            logUpload("Message #{$messageId} envoyé avec succès");
            return [
                'success' => true,
                'message' => "Message envoyé avec succès",
                'messageId' => $messageId
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            logUpload("Exception lors de l'envoi du message: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    } catch (Exception $e) {
        logUpload("Exception externe dans handleSendMessage: " . $e->getMessage());
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
        logUpload("Début de handleSendAnnouncement pour user {$user['id']} (type {$user['type']})");
        
        // Vérifier que l'utilisateur a le droit de créer des annonces
        $canSendAnnouncement = in_array($user['type'], ['vie_scolaire', 'administrateur']);
        if (!$canSendAnnouncement) {
            logUpload("L'utilisateur n'est pas autorisé à créer des annonces");
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à créer des annonces"
            ];
        }
        
        // Vérifier les pièces jointes avant de commencer la transaction
        $uploadedFiles = [];
        if (!empty($filesData) && isset($filesData['name']) && !empty($filesData['name'][0])) {
            try {
                logUpload("Traitement des pièces jointes pour l'annonce");
                $uploadedFiles = handleFileUploads($filesData);
                logUpload("Pièces jointes traitées avec succès", $uploadedFiles);
            } catch (Exception $e) {
                logUpload("Erreur lors du traitement des pièces jointes: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => "Erreur lors du traitement des pièces jointes: " . $e->getMessage()
                ];
            }
        }
        
        global $pdo;
        $pdo->beginTransaction();
        
        try {
            // Création de la conversation
            logUpload("Création de la conversation d'annonce");
            $convId = createConversation(
                $titre,
                'annonce',
                $user['id'],
                $user['type'],
                $participants
            );
            
            if (!$convId) {
                throw new Exception("Échec de la création de la conversation d'annonce");
            }
            
            // Envoi du message d'annonce
            logUpload("Insertion du message d'annonce en base de données");
            $messageId = addMessage(
                $convId,
                $user['id'],
                $user['type'],
                $contenu,
                'important', // Importance
                true, // Est annonce
                $notificationObligatoire, // Notification obligatoire
                null, // Parent message ID 
                'annonce', // Type message
                [] // On traite les fichiers séparément
            );
            
            if (!$messageId) {
                throw new Exception("Échec de l'insertion du message d'annonce en base de données");
            }
            
            // Sauvegarder les pièces jointes en base de données
            if (!empty($uploadedFiles)) {
                logUpload("Sauvegarde des métadonnées des pièces jointes en base de données");
                if (!saveAttachments($pdo, $messageId, $uploadedFiles)) {
                    throw new Exception("Échec de la sauvegarde des pièces jointes en base de données");
                }
            }
            
            $pdo->commit();
            
            logUpload("Annonce #{$messageId} envoyée avec succès à " . count($participants) . " destinataires");
            return [
                'success' => true,
                'message' => "L'annonce a été envoyée avec succès à " . count($participants) . " destinataire(s)",
                'convId' => $convId,
                'messageId' => $messageId
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            logUpload("Exception lors de l'envoi de l'annonce: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    } catch (Exception $e) {
        logUpload("Exception externe dans handleSendAnnouncement: " . $e->getMessage());
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
        logUpload("Début de handleSendClassMessage pour user {$user['id']} (type {$user['type']}) - Classe: {$classe}");
        
        // Vérifier que l'utilisateur est un professeur
        if ($user['type'] !== 'professeur') {
            logUpload("L'utilisateur n'est pas autorisé à envoyer des messages à des classes");
            return [
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à envoyer des messages à des classes"
            ];
        }
        
        // Vérifier les pièces jointes avant de commencer la transaction
        $uploadedFiles = [];
        if (!empty($filesData) && isset($filesData['name']) && !empty($filesData['name'][0])) {
            try {
                logUpload("Traitement des pièces jointes pour le message à la classe");
                $uploadedFiles = handleFileUploads($filesData);
                logUpload("Pièces jointes traitées avec succès", $uploadedFiles);
            } catch (Exception $e) {
                logUpload("Erreur lors du traitement des pièces jointes: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => "Erreur lors du traitement des pièces jointes: " . $e->getMessage()
                ];
            }
        }
        
        global $pdo;
        $pdo->beginTransaction();
        
        try {
            // Envoyer le message à la classe
            logUpload("Création de la conversation pour la classe");
            $convId = sendMessageToClass(
                $user['id'],
                $classe,
                $titre,
                $contenu,
                $importance,
                $notificationObligatoire,
                $includeParents,
                [] // On traite les fichiers séparément
            );
            
            if (!$convId) {
                throw new Exception("Échec de la création de la conversation pour la classe");
            }
            
            // Récupérer l'ID du message créé
            $messageStmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $messageStmt->execute([$convId]);
            $messageId = $messageStmt->fetchColumn();
            
            if (!$messageId) {
                throw new Exception("Échec de la récupération de l'ID du message");
            }
            
            // Sauvegarder les pièces jointes en base de données
            if (!empty($uploadedFiles)) {
                logUpload("Sauvegarde des métadonnées des pièces jointes en base de données");
                if (!saveAttachments($pdo, $messageId, $uploadedFiles)) {
                    throw new Exception("Échec de la sauvegarde des pièces jointes en base de données");
                }
            }
            
            $pdo->commit();
            
            logUpload("Message à la classe #{$messageId} envoyé avec succès");
            return [
                'success' => true,
                'message' => "Votre message a été envoyé avec succès à la classe " . htmlspecialchars($classe),
                'convId' => $convId,
                'messageId' => $messageId
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            logUpload("Exception lors de l'envoi du message à la classe: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    } catch (Exception $e) {
        logUpload("Exception externe dans handleSendClassMessage: " . $e->getMessage());
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