<?php
/**
 * Modèle pour la gestion des notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Récupère les préférences de notification d'un utilisateur
 * @param int $userId
 * @param string $userType
 * @return array
 */
function getUserNotificationPreferences($userId, $userType) {
    global $pdo;
    
    // Vérifier si les préférences existent déjà
    $stmt = $pdo->prepare("
        SELECT * FROM user_notification_preferences
        WHERE user_id = ? AND user_type = ?
    ");
    $stmt->execute([$userId, $userType]);
    $preferences = $stmt->fetch();
    
    // Si les préférences n'existent pas, les créer avec les valeurs par défaut
    if (!$preferences) {
        $stmt = $pdo->prepare("
            INSERT INTO user_notification_preferences
            (user_id, user_type, email_notifications, browser_notifications, 
             notification_sound, mention_notifications, reply_notifications, 
             important_notifications, digest_frequency)
            VALUES (?, ?, 0, 1, 1, 1, 1, 1, 'never')
        ");
        $stmt->execute([$userId, $userType]);
        
        // Récupérer les préférences nouvellement créées
        $stmt = $pdo->prepare("
            SELECT * FROM user_notification_preferences
            WHERE user_id = ? AND user_type = ?
        ");
        $stmt->execute([$userId, $userType]);
        $preferences = $stmt->fetch();
    }
    
    return $preferences;
}

/**
 * Met à jour les préférences de notification d'un utilisateur
 * @param int $userId
 * @param string $userType
 * @param array $preferences
 * @return bool
 */
function updateUserNotificationPreferences($userId, $userType, $preferences) {
    global $pdo;
    
    // Valider les préférences
    $validPreferences = [];
    
    // Valider les booléens
    $booleanFields = [
        'email_notifications', 'browser_notifications', 'notification_sound',
        'mention_notifications', 'reply_notifications', 'important_notifications'
    ];
    
    foreach ($booleanFields as $field) {
        if (isset($preferences[$field])) {
            $validPreferences[$field] = $preferences[$field] ? 1 : 0;
        }
    }
    
    // Valider digest_frequency
    if (isset($preferences['digest_frequency'])) {
        $validFrequencies = ['never', 'daily', 'weekly'];
        if (in_array($preferences['digest_frequency'], $validFrequencies)) {
            $validPreferences['digest_frequency'] = $preferences['digest_frequency'];
        }
    }
    
    // Si aucune préférence valide n'a été fournie, retourner false
    if (empty($validPreferences)) {
        return false;
    }
    
    // Créer la requête d'update
    $sql = "UPDATE user_notification_preferences SET ";
    $params = [];
    
    foreach ($validPreferences as $field => $value) {
        $sql .= "$field = ?, ";
        $params[] = $value;
    }
    
    // Supprimer la virgule finale et ajouter la condition WHERE
    $sql = rtrim($sql, ', ');
    $sql .= " WHERE user_id = ? AND user_type = ?";
    $params[] = $userId;
    $params[] = $userType;
    
    // Exécuter la requête
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    // Si aucune ligne n'a été mise à jour, les préférences n'existent peut-être pas encore
    if ($stmt->rowCount() === 0) {
        // Vérifier si les préférences existent
        $checkStmt = $pdo->prepare("
            SELECT id FROM user_notification_preferences
            WHERE user_id = ? AND user_type = ?
        ");
        $checkStmt->execute([$userId, $userType]);
        
        if (!$checkStmt->fetch()) {
            // Créer les préférences avec les valeurs par défaut + les valeurs fournies
            $defaults = [
                'email_notifications' => 0,
                'browser_notifications' => 1,
                'notification_sound' => 1,
                'mention_notifications' => 1,
                'reply_notifications' => 1,
                'important_notifications' => 1,
                'digest_frequency' => 'never'
            ];
            
            // Fusionner les valeurs par défaut avec les valeurs fournies
            $values = array_merge($defaults, $validPreferences);
            
            $insertSql = "
                INSERT INTO user_notification_preferences
                (user_id, user_type, email_notifications, browser_notifications,
                notification_sound, mention_notifications, reply_notifications,
                important_notifications, digest_frequency)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $insertParams = [
                $userId, $userType,
                $values['email_notifications'], $values['browser_notifications'],
                $values['notification_sound'], $values['mention_notifications'],
                $values['reply_notifications'], $values['important_notifications'],
                $values['digest_frequency']
            ];
            
            $insertStmt = $pdo->prepare($insertSql);
            return $insertStmt->execute($insertParams);
        }
    }
    
    return $result;
}

/**
 * Récupère les notifications non lues d'un utilisateur
 * @param int $userId
 * @param string $userType
 * @param int $limit
 * @return array
 */
function getUnreadNotifications($userId, $userType, $limit = 50) {
    global $pdo;
    
    // Assurez-vous que limit est un entier pour éviter les injections SQL
    $limit = (int)$limit;
    
    $stmt = $pdo->prepare("
        SELECT n.*, 
               m.body as contenu, m.sender_id as expediteur_id, m.sender_type as expediteur_type,
               m.conversation_id,
               c.subject as conversation_titre,
               CASE 
                   WHEN m.sender_type = 'eleve' THEN 
                       (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = m.sender_id)
                   WHEN m.sender_type = 'parent' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = m.sender_id)
                   WHEN m.sender_type = 'professeur' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = m.sender_id)
                   WHEN m.sender_type = 'vie_scolaire' THEN 
                       (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = m.sender_id)
                   WHEN m.sender_type = 'administrateur' THEN 
                       (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = m.sender_id)
                   ELSE 'Inconnu'
               END as expediteur_nom,
               m.status,
               notified_at as date_creation
        FROM message_notifications n
        JOIN messages m ON n.message_id = m.id
        JOIN conversations c ON m.conversation_id = c.id
        WHERE n.user_id = ? AND n.user_type = ? AND n.is_read = 0
        ORDER BY n.notified_at DESC
        LIMIT " . $limit
    );
    $stmt->execute([$userId, $userType]);
    
    return $stmt->fetchAll();
}

/**
 * Compte le nombre de notifications non lues pour un utilisateur
 * @param int $userId
 * @param string $userType
 * @return int
 */
function countUnreadNotifications($userId, $userType) {
    global $pdo;
    
    // Utiliser la somme des unread_count de toutes les conversations de l'utilisateur
    $stmt = $pdo->prepare("
        SELECT SUM(unread_count) as total_unread
        FROM conversation_participants
        WHERE user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$userId, $userType]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['total_unread'] ?: 0;
}