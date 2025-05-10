<?php
/**
 * Contrôleur pour les actions sur les notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/notification.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Gère la mise à jour des préférences de notification
 * @param int $userId
 * @param string $userType
 * @param array $preferences
 * @return array
 */
function handleUpdateNotificationPreferences($userId, $userType, $preferences) {
    try {
        $result = updateUserNotificationPreferences($userId, $userType, $preferences);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Préférences de notification mises à jour avec succès"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors de la mise à jour des préférences"
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
 * Marque une notification comme lue
 * @param int $notificationId
 * @param array $user
 * @return array
 */
function handleMarkNotificationRead($notificationId, $user) {
    try {
        global $pdo;
        
        // Vérifier que la notification appartient à l'utilisateur
        $stmt = $pdo->prepare("
            SELECT n.id, m.id as message_id 
            FROM message_notifications n
            JOIN messages m ON n.message_id = m.id
            WHERE n.id = ? AND n.user_id = ? AND n.user_type = ? AND n.is_read = 0
        ");
        $stmt->execute([$notificationId, $user['id'], $user['type']]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            return [
                'success' => false,
                'message' => "Notification introuvable ou déjà lue"
            ];
        }
        
        // Marquer comme lu
        $messageId = $notification['message_id'];
        $result = markMessageAsRead($messageId, $user['id'], $user['type']);
        
        if ($result) {
            return [
                'success' => true,
                'message' => "Notification marquée comme lue"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Erreur lors du marquage de la notification"
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}