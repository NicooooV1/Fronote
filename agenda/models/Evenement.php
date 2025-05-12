<?php
require_once __DIR__ . '/~u22405372/SAE/Pronote/login/config/database.php';

class Evenement {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function fetchBetween($start, $end) {
        $sql = "SELECT id, titre, description, date_debut, date_fin, couleur
                FROM evenements
                WHERE date_debut BETWEEN :start AND :end";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['start'=>$start,'end'=>$end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}