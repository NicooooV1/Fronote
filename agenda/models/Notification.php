<?php
require_once __DIR__ . '/../config/database.php';

class Notification {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    // Récupère les préférences de notification d'un utilisateur
    public function getUserPreferences($userId) {
        $sql = "SELECT id, utilisateur_id, emploi_id, type, delai_minute
                FROM notifications
                WHERE utilisateur_id = :uid";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Crée ou met à jour une préférence de notification
    public function savePreference($userId, $type, $delaiMinute, $emploiId = null) {
        // Vérifier si la préférence existe déjà
        $sql = "SELECT id FROM notifications 
                WHERE utilisateur_id = :uid AND type = :type";
        
        $params = [
            'uid' => $userId,
            'type' => $type
        ];
        
        if ($emploiId) {
            $sql .= " AND emploi_id = :eid";
            $params['eid'] = $emploiId;
        } else {
            $sql .= " AND emploi_id IS NULL";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Mise à jour
            $sql = "UPDATE notifications SET delai_minute = :delai
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'delai' => $delaiMinute,
                'id' => $existing['id']
            ]);
        } else {
            // Création
            $sql = "INSERT INTO notifications (utilisateur_id, emploi_id, type, delai_minute)
                    VALUES (:uid, :eid, :type, :delai)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'uid' => $userId,
                'eid' => $emploiId,
                'type' => $type,
                'delai' => $delaiMinute
            ]);
        }
    }
}