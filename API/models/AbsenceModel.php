<?php
/**
 * Modèle pour la gestion des absences
 */
class AbsenceModel extends Model
{
    protected $table = 'absences';
    protected $primaryKey = 'id';
    protected $fillable = ['id_eleve', 'date_debut', 'date_fin', 'motif', 'justifie', 'commentaire', 'signale_par', 'type_absence'];
    
    protected $validationRules = [
        'id_eleve' => [
            'required' => true,
            'integer' => true
        ],
        'date_debut' => [
            'required' => true,
            'date' => ['format' => 'Y-m-d H:i:s']
        ],
        'date_fin' => [
            'required' => true,
            'date' => ['format' => 'Y-m-d H:i:s']
        ],
        'type_absence' => [
            'required' => true,
            'in' => ['cours', 'demi-journee', 'journee']
        ]
    ];
    
    /**
     * Récupère les absences d'un élève
     * 
     * @param int    $id_eleve   ID de l'élève
     * @param string $date_debut Date de début (optionnel)
     * @param string $date_fin   Date de fin (optionnel)
     * @return array Liste des absences
     */
    public function getAbsencesEleve($id_eleve, $date_debut = null, $date_fin = null)
    {
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a
                JOIN eleves e ON a.id_eleve = e.id
                WHERE a.id_eleve = ?";
        
        $params = [$id_eleve];
        
        if ($date_debut && $date_fin) {
            $sql .= " AND a.date_debut BETWEEN ? AND ?";
            $params[] = $date_debut;
            $params[] = $date_fin;
        }
        
        $sql .= " ORDER BY a.date_debut DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère les absences d'une classe
     * 
     * @param string $classe     Nom de la classe
     * @param string $date_debut Date de début (optionnel)
     * @param string $date_fin   Date de fin (optionnel)
     * @return array Liste des absences
     */
    public function getAbsencesClasse($classe, $date_debut = null, $date_fin = null)
    {
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a
                JOIN eleves e ON a.id_eleve = e.id
                WHERE e.classe = ?";
        
        $params = [$classe];
        
        if ($date_debut && $date_fin) {
            $sql .= " AND a.date_debut BETWEEN ? AND ?";
            $params[] = $date_debut;
            $params[] = $date_fin;
        }
        
        $sql .= " ORDER BY e.nom, e.prenom, a.date_debut DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Justifie une absence
     * 
     * @param int    $id      ID de l'absence
     * @param string $motif   Motif de justification
     * @param string $comment Commentaire (optionnel)
     * @return bool Succès de l'opération
     */
    public function justifyAbsence($id, $motif, $comment = null)
    {
        $data = [
            'justifie' => 1,
            'motif' => $motif
        ];
        
        if ($comment) {
            // Récupérer le commentaire existant
            $stmt = $this->db->prepare("SELECT commentaire FROM {$this->table} WHERE id = ?");
            $stmt->execute([$id]);
            $existingComment = $stmt->fetchColumn();
            
            // Ajouter le nouveau commentaire
            if ($existingComment) {
                $data['commentaire'] = $existingComment . "\n" . $comment;
            } else {
                $data['commentaire'] = $comment;
            }
        }
        
        return $this->update($id, $data);
    }
}
