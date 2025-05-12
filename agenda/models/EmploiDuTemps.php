<?php
require_once __DIR__ . '/~u22405372/SAE/Pronote/login/config/database.php';

class EmploiDuTemps {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function fetchBetween($userId, $start, $end) {
        $sql = "SELECT e.id, e.date, e.heure_debut, e.heure_fin,
                       m.label AS matiere_label, m.couleur,
                       s.label AS salle_label,
                       p.nom AS prof_label
                FROM emplois_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                JOIN salles s   ON e.salle_id   = s.id
                JOIN profs p    ON e.prof_id    = p.id
                WHERE e.utilisateur_id = :uid
                  AND e.date BETWEEN :start AND :end";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid'=>$userId,'start'=>$start,'end'=>$end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}