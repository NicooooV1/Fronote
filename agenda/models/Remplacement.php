<?php
require_once __DIR__ . '/../config/database.php';

class Remplacement {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function fetchBetween($start, $end) {
        $sql = "SELECT r.id, r.emploi_id_src, r.emploi_id_dest, r.date, r.motif,
                       e.heure_debut, e.heure_fin,
                       m.label AS matiere_label,
                       s.label AS salle_label,
                       p.nom AS prof_label
                FROM remplacements r
                JOIN emplois_du_temps e ON r.emploi_id_dest = e.id
                JOIN matieres m ON e.matiere_id = m.id
                JOIN salles s ON e.salle_id = s.id
                JOIN profs p ON e.prof_id = p.id
                WHERE r.date BETWEEN :start AND :end";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function create($sourceId, $destId, $date, $motif) {
        $sql = "INSERT INTO remplacements (emploi_id_src, emploi_id_dest, date, motif)
                VALUES (:src, :dest, :date, :motif)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'src' => $sourceId,
            'dest' => $destId,
            'date' => $date,
            'motif' => $motif
        ]);
    }
}