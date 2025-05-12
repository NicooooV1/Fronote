<?php
require_once __DIR__ . '/../config/database.php';

class EmploiDuTemps {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function fetchBetween($userId, $start, $end, $userType) {
        // Requête adaptée selon le type d'utilisateur
        $sql = "SELECT e.id, e.date, e.heure_debut, e.heure_fin,
                       m.label AS matiere_label, m.couleur,
                       s.label AS salle_label,
                       p.nom AS prof_label,
                       (SELECT COUNT(*) FROM remplacements r WHERE r.emploi_id_src = e.id) > 0 AS has_replacement
                FROM emplois_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                JOIN salles s ON e.salle_id = s.id
                JOIN profs p ON e.prof_id = p.id";
                
        // Ajustement de la clause WHERE selon le type d'utilisateur
        if ($userType == 'eleve' || $userType == 'parent') {
            $sql .= " WHERE e.utilisateur_id = :uid";
        } elseif ($userType == 'professeur') {
            $sql .= " WHERE (e.utilisateur_id = :uid OR e.prof_id = (SELECT id FROM profs WHERE user_id = :uid))";
        } else {
            // Admin ou CPE : tous les emplois du temps
            $sql .= " WHERE 1=1";
        }
        
        $sql .= " AND e.date BETWEEN :start AND :end ORDER BY e.date, e.heure_debut";
        
        $stmt = $this->db->prepare($sql);
        
        // Bind des paramètres communs
        $params = ['start' => $start, 'end' => $end];
        
        // Bind du userId si nécessaire
        if ($userType != 'admin' && $userType != 'cpe') {
            $params['uid'] = $userId;
        }
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Mise à jour d'un emploi du temps
    public function update($id, $data) {
        $updateFields = [];
        $params = ['id' => $id];
        
        // Construction dynamique de la requête UPDATE
        foreach ($data as $field => $value) {
            if ($value !== null) {
                $updateFields[] = "$field = :$field";
                $params[$field] = $value;
            }
        }
        
        if (empty($updateFields)) {
            return false;
        }
        
        $sql = "UPDATE emplois_du_temps SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}