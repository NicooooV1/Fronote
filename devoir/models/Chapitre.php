<?php
/**
 * Modèle pour la gestion des chapitres
 */
class Chapitre {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère tous les chapitres avec filtres
     * @param array $filters Filtres à appliquer
     * @return array Liste des chapitres
     */
    public function getAllChapitres($filters = []) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['classe_id'])) {
            $where[] = "c.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        if (!empty($filters['matiere_id'])) {
            $where[] = "c.matiere_id = :matiere_id";
            $params[':matiere_id'] = $filters['matiere_id'];
        }
        
        if (!empty($filters['professeur_id'])) {
            $where[] = "c.professeur_id = :professeur_id";
            $params[':professeur_id'] = $filters['professeur_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(c.titre LIKE :search OR c.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL
        $sql = "SELECT c.*, 
                   m.nom AS matiere_nom,
                   cl.nom AS classe_nom,
                   u.first_name AS professeur_prenom,
                   u.last_name AS professeur_nom
                FROM chapitres c
                LEFT JOIN matieres m ON c.matiere_id = m.id
                LEFT JOIN classes cl ON c.classe_id = cl.id
                LEFT JOIN users u ON c.professeur_id = u.id
                $whereClause
                ORDER BY " . (!empty($filters['order_by']) ? $filters['order_by'] : "c.ordre ASC");
        
        $chapitres = $this->db->fetchAll($sql, $params);
        
        // Récupérer les compétences pour chaque chapitre
        foreach ($chapitres as &$chapitre) {
            $chapitre['competences'] = $this->getCompetencesByChapitre($chapitre['id']);
        }
        
        return $chapitres;
    }
    
    /**
     * Récupère un chapitre par son ID
     * @param int $id ID du chapitre
     * @return array|false Informations du chapitre ou false si non trouvé
     */
    public function getChapitreById($id) {
        $sql = "SELECT c.*, 
                   m.nom AS matiere_nom,
                   cl.nom AS classe_nom,
                   u.first_name AS professeur_prenom,
                   u.last_name AS professeur_nom
                FROM chapitres c
                LEFT JOIN matieres m ON c.matiere_id = m.id
                LEFT JOIN classes cl ON c.classe_id = cl.id
                LEFT JOIN users u ON c.professeur_id = u.id
                WHERE c.id = :id";
        
        $chapitre = $this->db->fetch($sql, [':id' => $id]);
        
        if ($chapitre) {
            // Récupérer les compétences associées au chapitre
            $chapitre['competences'] = $this->getCompetencesByChapitre($chapitre['id']);
            
            // Récupérer les séances associées au chapitre
            $chapitre['seances'] = $this->getSeancesByChapitre($chapitre['id']);
        }
        
        return $chapitre;
    }
    
    /**
     * Récupère les chapitres par matière et classe
     * @param int $matiereId ID de la matière
     * @param int $classeId ID de la classe
     * @return array Liste des chapitres
     */
    public function getChapitresByMatiereAndClasse($matiereId, $classeId) {
        $sql = "SELECT c.* 
                FROM chapitres c
                WHERE c.matiere_id = :matiere_id AND c.classe_id = :classe_id
                ORDER BY c.ordre ASC";
        
        return $this->db->fetchAll($sql, [
            ':matiere_id' => $matiereId,
            ':classe_id' => $classeId
        ]);
    }
    
    /**
     * Crée un nouveau chapitre
     * @param array $data Données du chapitre
     * @return int|false ID du chapitre créé ou false en cas d'échec
     */
    public function createChapitre($data) {
        try {
            $this->db->beginTransaction();
            
            // Déterminer l'ordre du chapitre (dernier + 1)
            $sql = "SELECT MAX(ordre) as max_ordre 
                    FROM chapitres 
                    WHERE matiere_id = :matiere_id AND classe_id = :classe_id";
            
            $result = $this->db->fetch($sql, [
                ':matiere_id' => $data['matiere_id'],
                ':classe_id' => $data['classe_id']
            ]);
            
            $ordre = ($result && isset($result['max_ordre'])) ? $result['max_ordre'] + 1 : 1;
            
            // Insertion du chapitre
            $chapitreId = $this->db->insert('chapitres', [
                'titre' => $data['titre'],
                'description' => $data['description'] ?? '',
                'objectifs' => $data['objectifs'] ?? '',
                'matiere_id' => $data['matiere_id'],
                'classe_id' => $data['classe_id'],
                'professeur_id' => $data['professeur_id'],
                'ordre' => $ordre,
                'date_creation' => date('Y-m-d H:i:s')
            ]);
            
            if (!$chapitreId) {
                $this->db->rollback();
                return false;
            }
            
            // Association avec les compétences si spécifiées
            if (!empty($data['competences']) && is_array($data['competences'])) {
                foreach ($data['competences'] as $competenceId) {
                    $this->db->insert('chapitre_competence', [
                        'chapitre_id' => $chapitreId,
                        'competence_id' => $competenceId
                    ]);
                }
            }
            
            $this->db->commit();
            return $chapitreId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la création du chapitre: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Met à jour un chapitre existant
     * @param int $id ID du chapitre
     * @param array $data Nouvelles données du chapitre
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateChapitre($id, $data) {
        try {
            $this->db->beginTransaction();
            
            $updateData = [];
            
            if (isset($data['titre'])) $updateData['titre'] = $data['titre'];
            if (isset($data['description'])) $updateData['description'] = $data['description'];
            if (isset($data['objectifs'])) $updateData['objectifs'] = $data['objectifs'];
            if (isset($data['ordre'])) $updateData['ordre'] = $data['ordre'];
            
            // Si des champs à mettre à jour
            if (!empty($updateData)) {
                $this->db->update('chapitres', $updateData, 'id = :id', [':id' => $id]);
            }
            
            // Mise à jour des compétences si spécifiées
            if (isset($data['competences'])) {
                // Supprimer les associations existantes
                $this->db->delete('chapitre_competence', 'chapitre_id = :chapitre_id', [':chapitre_id' => $id]);
                
                // Ajouter les nouvelles associations
                if (is_array($data['competences'])) {
                    foreach ($data['competences'] as $competenceId) {
                        $this->db->insert('chapitre_competence', [
                            'chapitre_id' => $id,
                            'competence_id' => $competenceId
                        ]);
                    }
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour du chapitre: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime un chapitre
     * @param int $id ID du chapitre à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteChapitre($id) {
        try {
            $this->db->beginTransaction();
            
            // Récupérer les informations du chapitre pour réorganiser l'ordre
            $chapitre = $this->getChapitreById($id);
            
            if (!$chapitre) {
                $this->db->rollback();
                return false;
            }
            
            // Supprimer les associations avec les compétences
            $this->db->delete('chapitre_competence', 'chapitre_id = :chapitre_id', [':chapitre_id' => $id]);
            
            // Supprimer le chapitre
            $this->db->delete('chapitres', 'id = :id', [':id' => $id]);
            
            // Réorganiser l'ordre des chapitres restants
            $sql = "UPDATE chapitres 
                    SET ordre = ordre - 1 
                    WHERE matiere_id = :matiere_id 
                      AND classe_id = :classe_id 
                      AND ordre > :ordre";
            
            $this->db->query($sql, [
                ':matiere_id' => $chapitre['matiere_id'],
                ':classe_id' => $chapitre['classe_id'],
                ':ordre' => $chapitre['ordre']
            ]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression du chapitre: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Réorganise l'ordre des chapitres
     * @param int $id ID du chapitre à déplacer
     * @param int $nouvelOrdre Nouvel ordre du chapitre
     * @return bool Succès ou échec de la réorganisation
     */
    public function reordonnerChapitre($id, $nouvelOrdre) {
        try {
            $this->db->beginTransaction();
            
            // Récupérer les informations du chapitre
            $chapitre = $this->getChapitreById($id);
            
            if (!$chapitre) {
                $this->db->rollback();
                return false;
            }
            
            $ancienOrdre = $chapitre['ordre'];
            
            // Si même ordre, rien à faire
            if ($ancienOrdre == $nouvelOrdre) {
                $this->db->rollback();
                return true;
            }
            
            // Si on déplace vers le haut (ordre plus petit)
            if ($nouvelOrdre < $ancienOrdre) {
                $sql = "UPDATE chapitres 
                        SET ordre = ordre + 1 
                        WHERE matiere_id = :matiere_id 
                          AND classe_id = :classe_id 
                          AND ordre >= :nouvel_ordre 
                          AND ordre < :ancien_ordre";
                
                $this->db->query($sql, [
                    ':matiere_id' => $chapitre['matiere_id'],
                    ':classe_id' => $chapitre['classe_id'],
                    ':nouvel_ordre' => $nouvelOrdre,
                    ':ancien_ordre' => $ancienOrdre
                ]);
            } 
            // Si on déplace vers le bas (ordre plus grand)
            else {
                $sql = "UPDATE chapitres 
                        SET ordre = ordre - 1 
                        WHERE matiere_id = :matiere_id 
                          AND classe_id = :classe_id 
                          AND ordre > :ancien_ordre 
                          AND ordre <= :nouvel_ordre";
                
                $this->db->query($sql, [
                    ':matiere_id' => $chapitre['matiere_id'],
                    ':classe_id' => $chapitre['classe_id'],
                    ':ancien_ordre' => $ancienOrdre,
                    ':nouvel_ordre' => $nouvelOrdre
                ]);
            }
            
            // Mettre à jour l'ordre du chapitre déplacé
            $this->db->update('chapitres', 
                           ['ordre' => $nouvelOrdre], 
                           'id = :id', 
                           [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la réorganisation du chapitre: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère les compétences associées à un chapitre
     * @param int $chapitreId ID du chapitre
     * @return array Liste des compétences
     */
    public function getCompetencesByChapitre($chapitreId) {
        $sql = "SELECT c.* 
                FROM competences c
                JOIN chapitre_competence cc ON c.id = cc.competence_id
                WHERE cc.chapitre_id = :chapitre_id
                ORDER BY c.code";
        
        return $this->db->fetchAll($sql, [':chapitre_id' => $chapitreId]);
    }
    
    /**
     * Récupère les séances associées à un chapitre
     * @param int $chapitreId ID du chapitre
     * @return array Liste des séances
     */
    public function getSeancesByChapitre($chapitreId) {
        $sql = "SELECT s.id, s.titre, s.date_debut, s.date_fin, s.statut
                FROM seances s
                WHERE s.chapitre_id = :chapitre_id
                ORDER BY s.date_debut ASC";
        
        return $this->db->fetchAll($sql, [':chapitre_id' => $chapitreId]);
    }
    
    /**
     * Récupère la progression dans un chapitre
     * @param int $chapitreId ID du chapitre
     * @return array Informations sur la progression
     */
    public function getProgressionChapitre($chapitreId) {
        // Compter le nombre total de séances et de séances réalisées
        $sql = "SELECT 
                   COUNT(*) as total_seances,
                   COUNT(CASE WHEN statut = 'REAL' THEN 1 END) as seances_realisees
                FROM seances
                WHERE chapitre_id = :chapitre_id";
        
        $progression = $this->db->fetch($sql, [':chapitre_id' => $chapitreId]);
        
        // Calculer le pourcentage de progression
        $pourcentage = 0;
        if ($progression['total_seances'] > 0) {
            $pourcentage = ($progression['seances_realisees'] / $progression['total_seances']) * 100;
        }
        
        return [
            'total_seances' => $progression['total_seances'],
            'seances_realisees' => $progression['seances_realisees'],
            'pourcentage' => round($pourcentage, 2)
        ];
    }
}