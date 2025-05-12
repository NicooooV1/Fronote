<?php
require_once __DIR__ . '/../config/database.php';

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // Récupère l'utilisateur par ID
    public function getById($id) {
        $sql = "SELECT id, nom, prenom, email, type
                FROM utilisateurs
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Récupère l'ID utilisateur associé à un ID professeur
    public function getUserIdByProfId($profId) {
        $sql = "SELECT user_id FROM profs WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $profId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['user_id'] : null;
    }

    // Récupère les IDs d'élèves par classe
    public function getStudentIdsByClass($classeId) {
        $sql = "SELECT u.id
                FROM utilisateurs u
                JOIN eleves e ON u.id = e.user_id
                WHERE e.classe_id = :classe_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['classe_id' => $classeId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $ids = [];
        foreach ($results as $row) {
            $ids[] = $row['id'];
        }
        
        return $ids;
    }

    // Enregistre un abonnement aux notifications push
    public function savePushSubscription($userId, $subscription) {
        $sql = "UPDATE utilisateurs 
                SET push_subscription = :subscription
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'subscription' => $subscription,
            'id' => $userId
        ]);
    }

    // Récupère l'abonnement aux notifications push
    public function getPushSubscription($userId) {
        $sql = "SELECT push_subscription
                FROM utilisateurs
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['push_subscription'] : null;
    }
}