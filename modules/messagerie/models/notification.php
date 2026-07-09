<?php
declare(strict_types=1);
/**
 * Modèle pour la gestion des notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Compte le nombre de notifications non lues pour un utilisateur
 * REMARQUE: Utiliser la fonction du même nom depuis core/auth.php si elle existe déjà
 * @param int $userId
 * @param string $userType
 * @return int
 */
if (!function_exists('countUnreadNotifications')) {
    function countUnreadNotifications($userId, $userType) {
        global $pdo;
        if (!isset($pdo)) return 0;

        try {
            // Source unique : SUM(unread_count) dans conversation_participants
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(unread_count), 0)
                FROM conversation_participants
                WHERE user_id = ? AND user_type = ? AND is_deleted = 0 AND is_archived = 0
            ");
            $stmt->execute([$userId, $userType]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $ex) {
            error_log('Error in countUnreadNotifications: ' . $ex->getMessage());
            return 0;
        }
    }
}