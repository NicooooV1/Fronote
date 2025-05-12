<?php
/**
 * Modèle pour la gestion des devoirs
 */
class Devoir {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère tous les devoirs avec filtres et pagination
     * @param array $filters Filtres à appliquer
     * @param int $page Numéro de page
     * @param int $perPage Nombre d'éléments par page
     * @return array Liste des devoirs
     */
    public function getAllDevoirs($filters = [], $page = 1, $perPage = ITEMS_PER_PAGE) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['classe_id'])) {
            $where[] = "d.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        if (!empty($filters['statut'])) {
            $where[] = "d.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['auteur_id'])) {
            $where[] = "d.auteur_id = :auteur_id";
            $params[':auteur_id'] = $filters['auteur_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(d.titre LIKE :search OR d.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['date_debut']) && !empty($filters['date_fin'])) {
            $where[] = "d.date_limite BETWEEN :date_debut AND :date_fin";
            $params[':date_debut'] = $filters['date_debut'];
            $params[':date_fin'] = $filters['date_fin'];
        } else if (!empty($filters['date_debut'])) {
            $where[] = "d.date_limite >= :date_debut";
            $params[':date_debut'] = $filters['date_debut'];
        } else if (!empty($filters['date_fin'])) {
            $where[] = "d.date_limite <= :date_fin";
            $params[':date_fin'] = $filters['date_fin'];
        }
        
        // Visibilité des devoirs
        if (isset($filters['est_visible'])) {
            $where[] = "d.est_visible = :est_visible";
            $params[':est_visible'] = $filters['est_visible'];
        }
        
        // Condition pour les devoirs dont la date de début est passée
        if (isset($filters['date_debut_passee']) && $filters['date_debut_passee']) {
            $where[] = "d.date_debut <= NOW()";
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Calcul de l'offset pour la pagination
        $offset = ($page - 1) * $perPage;
        
        // Requête SQL pour récupérer les devoirs avec le nom de l'auteur et de la classe
        $sql = "SELECT d.*, 
                   u.first_name AS auteur_prenom, 
                   u.last_name AS auteur_nom, 
                   c.nom AS classe_nom,
                   (SELECT COUNT(*) FROM rendus r WHERE r.devoir_id = d.id) AS nb_rendus
                FROM devoirs d
                LEFT JOIN users u ON d.auteur_id = u.id
                LEFT JOIN classes c ON d.classe_id = c.id
                $whereClause
                ORDER BY " . (!empty($filters['order_by']) ? $filters['order_by'] : "d.date_limite ASC") . "
                LIMIT :limit OFFSET :offset";
        
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        
        // Exécution de la requête
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Compte le nombre total de devoirs selon les filtres
     * @param array $filters Filtres à appliquer
     * @return int Nombre total de devoirs
     */
    public function countDevoirs($filters = []) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres (similaire à getAllDevoirs)
        if (!empty($filters['classe_id'])) {
            $where[] = "d.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        if (!empty($filters['statut'])) {
            $where[] = "d.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['auteur_id'])) {
            $where[] = "d.auteur_id = :auteur_id";
            $params[':auteur_id'] = $filters['auteur_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(d.titre LIKE :search OR d.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['date_debut']) && !empty($filters['date_fin'])) {
            $where[] = "d.date_limite BETWEEN :date_debut AND :date_fin";
            $params[':date_debut'] = $filters['date_debut'];
            $params[':date_fin'] = $filters['date_fin'];
        } else if (!empty($filters['date_debut'])) {
            $where[] = "d.date_limite >= :date_debut";
            $params[':date_debut'] = $filters['date_debut'];
        } else if (!empty($filters['date_fin'])) {
            $where[] = "d.date_limite <= :date_fin";
            $params[':date_fin'] = $filters['date_fin'];
        }
        
        // Visibilité des devoirs
        if (isset($filters['est_visible'])) {
            $where[] = "d.est_visible = :est_visible";
            $params[':est_visible'] = $filters['est_visible'];
        }
        
        // Condition pour les devoirs dont la date de début est passée
        if (isset($filters['date_debut_passee']) && $filters['date_debut_passee']) {
            $where[] = "d.date_debut <= NOW()";
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL pour compter les devoirs
        $sql = "SELECT COUNT(*) as total FROM devoirs d $whereClause";
        
        // Exécution de la requête
        $result = $this->db->fetch($sql, $params);
        return (int) $result['total'];
    }
    
    /**
     * Récupère un devoir par son ID avec ses pièces jointes
     * @param int $id ID du devoir
     * @return array|false Informations du devoir ou false si non trouvé
     */
    public function getDevoirById($id) {
        // Récupérer le devoir
        $sql = "SELECT d.*, 
                   u.first_name AS auteur_prenom, 
                   u.last_name AS auteur_nom, 
                   c.nom AS classe_nom,
                   (SELECT COUNT(*) FROM rendus r WHERE r.devoir_id = d.id) AS nb_rendus
                FROM devoirs d
                LEFT JOIN users u ON d.auteur_id = u.id
                LEFT JOIN classes c ON d.classe_id = c.id
                WHERE d.id = :id";
        
        $devoir = $this->db->fetch($sql, [':id' => $id]);
        
        if (!$devoir) {
            return false;
        }
        
        // Récupérer les pièces jointes du devoir
        $sql = "SELECT pj.* 
                FROM pieces_jointes pj
                JOIN devoir_piece_jointe dpj ON pj.id = dpj.piece_jointe_id
                WHERE dpj.devoir_id = :devoir_id";
        
        $piecesJointes = $this->db->fetchAll($sql, [':devoir_id' => $id]);
        $devoir['pieces_jointes'] = $piecesJointes;
        
        // Récupérer les groupes associés au devoir
        $sql = "SELECT g.* 
                FROM groupes g
                JOIN devoir_groupe dg ON g.id = dg.groupe_id
                WHERE dg.devoir_id = :devoir_id";
        
        $groupes = $this->db->fetchAll($sql, [':devoir_id' => $id]);
        $devoir['groupes'] = $groupes;
        
        return $devoir;
    }
    
    /**
     * Crée un nouveau devoir
     * @param array $data Données du devoir
     * @return int|false ID du devoir créé ou false en cas d'échec
     */
    public function createDevoir($data) {
        try {
            $this->db->beginTransaction();
            
            // Insertion du devoir
            $devoirId = $this->db->insert('devoirs', [
                'titre' => $data['titre'],
                'description' => $data['description'],
                'instructions' => $data['instructions'] ?? '',
                'date_debut' => $data['date_debut'],
                'date_limite' => $data['date_limite'],
                'auteur_id' => $data['auteur_id'],
                'classe_id' => $data['classe_id'],
                'bareme_id' => $data['bareme_id'] ?? null,
                'statut' => $data['statut'] ?? STATUT_A_FAIRE,
                'travail_groupe' => $data['travail_groupe'] ?? 0,
                'est_visible' => $data['est_visible'] ?? 1,
                'est_obligatoire' => $data['est_obligatoire'] ?? 1,
                'confidentialite' => $data['confidentialite'] ?? 'classe'
            ]);
            
            // Association avec les groupes si spécifiés
            if (!empty($data['groupes']) && is_array($data['groupes'])) {
                foreach ($data['groupes'] as $groupeId) {
                    $this->db->insert('devoir_groupe', [
                        'devoir_id' => $devoirId,
                        'groupe_id' => $groupeId
                    ]);
                }
            }
            
            // Association avec les pièces jointes si spécifiées
            if (!empty($data['pieces_jointes']) && is_array($data['pieces_jointes'])) {
                foreach ($data['pieces_jointes'] as $pieceJointeId) {
                    $this->db->insert('devoir_piece_jointe', [
                        'devoir_id' => $devoirId,
                        'piece_jointe_id' => $pieceJointeId
                    ]);
                }
            }
            
            $this->db->commit();
            return $devoirId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la création du devoir: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Met à jour un devoir existant
     * @param int $id ID du devoir
     * @param array $data Nouvelles données du devoir
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateDevoir($id, $data) {
        try {
            $this->db->beginTransaction();
            
            // Mise à jour du devoir
            $updateData = [];
            
            if (isset($data['titre'])) $updateData['titre'] = $data['titre'];
            if (isset($data['description'])) $updateData['description'] = $data['description'];
            if (isset($data['instructions'])) $updateData['instructions'] = $data['instructions'];
            if (isset($data['date_debut'])) $updateData['date_debut'] = $data['date_debut'];
            if (isset($data['date_limite'])) $updateData['date_limite'] = $data['date_limite'];
            if (isset($data['classe_id'])) $updateData['classe_id'] = $data['classe_id'];
            if (isset($data['bareme_id'])) $updateData['bareme_id'] = $data['bareme_id'];
            if (isset($data['statut'])) $updateData['statut'] = $data['statut'];
            if (isset($data['travail_groupe'])) $updateData['travail_groupe'] = $data['travail_groupe'];
            if (isset($data['est_visible'])) $updateData['est_visible'] = $data['est_visible'];
            if (isset($data['est_obligatoire'])) $updateData['est_obligatoire'] = $data['est_obligatoire'];
            if (isset($data['confidentialite'])) $updateData['confidentialite'] = $data['confidentialite'];
            
            if (!empty($updateData)) {
                $this->db->update('devoirs', $updateData, 'id = :id', [':id' => $id]);
            }
            
            // Mise à jour des groupes si spécifiés
            if (isset($data['groupes']) && is_array($data['groupes'])) {
                // Supprimer les associations existantes
                $this->db->delete('devoir_groupe', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
                
                // Ajouter les nouvelles associations
                foreach ($data['groupes'] as $groupeId) {
                    $this->db->insert('devoir_groupe', [
                        'devoir_id' => $id,
                        'groupe_id' => $groupeId
                    ]);
                }
            }
            
            // Mise à jour des pièces jointes si spécifiées
            if (isset($data['pieces_jointes']) && is_array($data['pieces_jointes'])) {
                // Supprimer les associations existantes
                $this->db->delete('devoir_piece_jointe', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
                
                // Ajouter les nouvelles associations
                foreach ($data['pieces_jointes'] as $pieceJointeId) {
                    $this->db->insert('devoir_piece_jointe', [
                        'devoir_id' => $id,
                        'piece_jointe_id' => $pieceJointeId
                    ]);
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour du devoir: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime un devoir
     * @param int $id ID du devoir à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteDevoir($id) {
        try {
            $this->db->beginTransaction();
            
            // Supprimer les associations avec les groupes
            $this->db->delete('devoir_groupe', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
            
            // Supprimer les associations avec les pièces jointes
            $this->db->delete('devoir_piece_jointe', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
            
            // Supprimer les rendus associés au devoir
            $this->db->delete('rendus', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
            
            // Supprimer les notifications associées au devoir
            $this->db->delete('notifications', 'devoir_id = :devoir_id', [':devoir_id' => $id]);
            
            // Supprimer le devoir
            $this->db->delete('devoirs', 'id = :id', [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression du devoir: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Change le statut d'un devoir
     * @param int $id ID du devoir
     * @param string $statut Nouveau statut
     * @return bool Succès ou échec du changement de statut
     */
    public function changerStatut($id, $statut) {
        return $this->db->update('devoirs', 
                               ['statut' => $statut], 
                               'id = :id', 
                               [':id' => $id]);
    }
    
    /**
     * Récupère les devoirs d'un élève
     * @param int $eleveId ID de l'élève
     * @param array $filters Filtres supplémentaires
     * @return array Liste des devoirs de l'élève
     */
    public function getDevoirsEleve($eleveId, $filters = []) {
        $params = [':eleve_id' => $eleveId];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        $where[] = "(ec.eleve_id = :eleve_id)"; // L'élève est dans la classe
        
        if (!empty($filters['statut'])) {
            $where[] = "d.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(d.titre LIKE :search OR d.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['date_debut']) && !empty($filters['date_fin'])) {
            $where[] = "d.date_limite BETWEEN :date_debut AND :date_fin";
            $params[':date_debut'] = $filters['date_debut'];
            $params[':date_fin'] = $filters['date_fin'];
        }
        
        // Visibilité et date de début
        $where[] = "d.est_visible = 1";
        $where[] = "d.date_debut <= NOW()";
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL
        $sql = "SELECT d.*, 
                   u.first_name AS auteur_prenom, 
                   u.last_name AS auteur_nom, 
                   c.nom AS classe_nom,
                   r.id AS rendu_id,
                   r.statut AS rendu_statut,
                   r.date_rendu
                FROM devoirs d
                JOIN classes c ON d.classe_id = c.id
                JOIN users u ON d.auteur_id = u.id
                JOIN eleve_classe ec ON c.id = ec.classe_id
                LEFT JOIN rendus r ON d.id = r.devoir_id AND r.eleve_id = :eleve_id
                $whereClause
                ORDER BY " . (!empty($filters['order_by']) ? $filters['order_by'] : "d.date_limite ASC");
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Récupère les pièces jointes d'un devoir
     * @param int $devoirId ID du devoir
     * @return array Liste des pièces jointes
     */
    public function getPiecesJointes($devoirId) {
        $sql = "SELECT pj.* 
                FROM pieces_jointes pj
                JOIN devoir_piece_jointe dpj ON pj.id = dpj.piece_jointe_id
                WHERE dpj.devoir_id = :devoir_id";
        
        return $this->db->fetchAll($sql, [':devoir_id' => $devoirId]);
    }
    
    /**
     * Ajoute une pièce jointe à un devoir
     * @param int $devoirId ID du devoir
     * @param int $pieceJointeId ID de la pièce jointe
     * @return bool Succès ou échec de l'opération
     */
    public function ajouterPieceJointe($devoirId, $pieceJointeId) {
        try {
            $this->db->insert('devoir_piece_jointe', [
                'devoir_id' => $devoirId,
                'piece_jointe_id' => $pieceJointeId
            ]);
            return true;
        } catch (Exception $e) {
            error_log("Erreur lors de l'ajout de la pièce jointe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retire une pièce jointe d'un devoir
     * @param int $devoirId ID du devoir
     * @param int $pieceJointeId ID de la pièce jointe
     * @return bool Succès ou échec de l'opération
     */
    public function retirerPieceJointe($devoirId, $pieceJointeId) {
        try {
            $this->db->delete(
                'devoir_piece_jointe', 
                'devoir_id = :devoir_id AND piece_jointe_id = :piece_jointe_id', 
                [':devoir_id' => $devoirId, ':piece_jointe_id' => $pieceJointeId]
            );
            return true;
        } catch (Exception $e) {
            error_log("Erreur lors du retrait de la pièce jointe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtient le statut de rendu d'un devoir pour un élève
     * @param int $devoirId ID du devoir
     * @param int $eleveId ID de l'élève
     * @return string|null Statut du rendu ou null si aucun rendu
     */
    public function getStatutRenduEleve($devoirId, $eleveId) {
        $sql = "SELECT statut FROM rendus 
                WHERE devoir_id = :devoir_id AND eleve_id = :eleve_id";
        
        $result = $this->db->fetch($sql, [
            ':devoir_id' => $devoirId,
            ':eleve_id' => $eleveId
        ]);
        
        return $result ? $result['statut'] : null;
    }
    
    /**
     * Récupère les statistiques de rendu pour un devoir
     * @param int $devoirId ID du devoir
     * @return array Statistiques de rendu
     */
    public function getStatistiquesRendu($devoirId) {
        $sql = "SELECT 
                   COUNT(CASE WHEN r.id IS NOT NULL THEN 1 END) as total_rendus,
                   COUNT(CASE WHEN r.statut = 'RE' THEN 1 END) as nb_rendus,
                   COUNT(CASE WHEN r.statut = 'CO' THEN 1 END) as nb_corriges,
                   AVG(r.note) as moyenne_notes,
                   MIN(r.note) as note_min,
                   MAX(r.note) as note_max
                FROM devoirs d
                JOIN classes c ON d.classe_id = c.id
                JOIN eleve_classe ec ON c.id = ec.classe_id
                LEFT JOIN rendus r ON d.id = r.devoir_id AND ec.eleve_id = r.eleve_id
                WHERE d.id = :devoir_id";
        
        return $this->db->fetch($sql, [':devoir_id' => $devoirId]);
    }
}