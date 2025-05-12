<?php
/**
 * Modèle pour la gestion des ressources pédagogiques
 */
class Ressource {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère toutes les ressources avec filtres
     * @param array $filters Filtres à appliquer
     * @return array Liste des ressources
     */
    public function getAllRessources($filters = []) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['type'])) {
            $where[] = "r.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['professeur_id'])) {
            $where[] = "r.professeur_id = :professeur_id";
            $params[':professeur_id'] = $filters['professeur_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(r.titre LIKE :search OR r.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (!empty($filters['tag'])) {
            $where[] = "r.id IN (
                SELECT ressource_id FROM ressource_tag 
                WHERE tag_id = :tag_id
            )";
            $params[':tag_id'] = $filters['tag'];
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL
        $sql = "SELECT r.*, 
                   u.first_name AS professeur_prenom,
                   u.last_name AS professeur_nom
                FROM ressources r
                LEFT JOIN users u ON r.professeur_id = u.id
                $whereClause
                ORDER BY " . (!empty($filters['order_by']) ? $filters['order_by'] : "r.date_creation DESC");
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Récupère une ressource par son ID
     * @param int $id ID de la ressource
     * @return array|false Informations de la ressource ou false si non trouvée
     */
    public function getRessourceById($id) {
        $sql = "SELECT r.*, 
                   u.first_name AS professeur_prenom,
                   u.last_name AS professeur_nom
                FROM ressources r
                LEFT JOIN users u ON r.professeur_id = u.id
                WHERE r.id = :id";
        
        $ressource = $this->db->fetch($sql, [':id' => $id]);
        
        if ($ressource) {
            // Récupérer les tags associés à la ressource
            $ressource['tags'] = $this->getTagsByRessource($ressource['id']);
            
            // Récupérer les fichiers associés (si type FILE ou GALLERY)
            if ($ressource['type'] === RESSOURCE_FILE || $ressource['type'] === RESSOURCE_GALLERY) {
                $ressource['fichiers'] = $this->getFichiersByRessource($ressource['id']);
            }
        }
        
        return $ressource;
    }
    
    /**
     * Récupère les ressources d'un professeur
     * @param int $professeurId ID du professeur
     * @return array Liste des ressources
     */
    public function getRessourcesByProfesseur($professeurId) {
        return $this->getAllRessources(['professeur_id' => $professeurId]);
    }
    
    /**
     * Récupère les ressources par type
     * @param string $type Type de ressource (FILE, LINK, VIDEO, etc.)
     * @return array Liste des ressources
     */
    public function getRessourcesByType($type) {
        return $this->getAllRessources(['type' => $type]);
    }
    
    /**
     * Crée une nouvelle ressource
     * @param array $data Données de la ressource
     * @return int|false ID de la ressource créée ou false en cas d'échec
     */
    public function createRessource($data) {
        try {
            $this->db->beginTransaction();
            
            // Insertion de la ressource
            $ressourceData = [
                'titre' => $data['titre'],
                'description' => $data['description'] ?? '',
                'type' => $data['type'],
                'professeur_id' => $data['professeur_id'],
                'date_creation' => date('Y-m-d H:i:s')
            ];
            
            // Ajouter des champs spécifiques selon le type
            switch ($data['type']) {
                case RESSOURCE_LINK:
                    $ressourceData['url'] = $data['url'] ?? '';
                    break;
                    
                case RESSOURCE_VIDEO:
                    $ressourceData['url'] = $data['url'] ?? '';
                    break;
                    
                case RESSOURCE_TEXT:
                    $ressourceData['contenu'] = $data['contenu'] ?? '';
                    break;
                    
                case RESSOURCE_QCM:
                    $ressourceData['contenu'] = isset($data['questions']) ? json_encode($data['questions']) : '[]';
                    break;
            }
            
            $ressourceId = $this->db->insert('ressources', $ressourceData);
            
            if (!$ressourceId) {
                $this->db->rollback();
                return false;
            }
            
            // Traitement des tags
            if (!empty($data['tags']) && is_array($data['tags'])) {
                foreach ($data['tags'] as $tagId) {
                    $this->db->insert('ressource_tag', [
                        'ressource_id' => $ressourceId,
                        'tag_id' => $tagId
                    ]);
                }
            }
            
            // Traitement des fichiers pour les types FILE et GALLERY
            if (($data['type'] === RESSOURCE_FILE || $data['type'] === RESSOURCE_GALLERY) && 
                !empty($data['fichiers']) && is_array($data['fichiers'])) {
                foreach ($data['fichiers'] as $fichier) {
                    $this->db->insert('fichiers_ressource', [
                        'ressource_id' => $ressourceId,
                        'nom' => $fichier['nom'],
                        'type' => $fichier['type'],
                        'fichier' => $fichier['fichier'],
                        'date_ajout' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            $this->db->commit();
            return $ressourceId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la création de la ressource: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Met à jour une ressource existante
     * @param int $id ID de la ressource
     * @param array $data Nouvelles données de la ressource
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateRessource($id, $data) {
        try {
            $this->db->beginTransaction();
            
            $updateData = [];
            
            if (isset($data['titre'])) $updateData['titre'] = $data['titre'];
            if (isset($data['description'])) $updateData['description'] = $data['description'];
            
            // Mise à jour des champs spécifiques selon le type
            $ressource = $this->getRessourceById($id);
            
            if ($ressource) {
                switch ($ressource['type']) {
                    case RESSOURCE_LINK:
                    case RESSOURCE_VIDEO:
                        if (isset($data['url'])) $updateData['url'] = $data['url'];
                        break;
                        
                    case RESSOURCE_TEXT:
                        if (isset($data['contenu'])) $updateData['contenu'] = $data['contenu'];
                        break;
                        
                    case RESSOURCE_QCM:
                        if (isset($data['questions'])) $updateData['contenu'] = json_encode($data['questions']);
                        break;
                }
            }
            
            // Si des champs à mettre à jour
            if (!empty($updateData)) {
                $this->db->update('ressources', $updateData, 'id = :id', [':id' => $id]);
            }
            
            // Mise à jour des tags
            if (isset($data['tags'])) {
                // Supprimer les associations existantes
                $this->db->delete('ressource_tag', 'ressource_id = :ressource_id', [':ressource_id' => $id]);
                
                // Ajouter les nouvelles associations
                if (is_array($data['tags'])) {
                    foreach ($data['tags'] as $tagId) {
                        $this->db->insert('ressource_tag', [
                            'ressource_id' => $id,
                            'tag_id' => $tagId
                        ]);
                    }
                }
            }
            
            // Traitement des nouveaux fichiers pour les types FILE et GALLERY
            if (($ressource['type'] === RESSOURCE_FILE || $ressource['type'] === RESSOURCE_GALLERY) && 
                !empty($data['nouveaux_fichiers']) && is_array($data['nouveaux_fichiers'])) {
                foreach ($data['nouveaux_fichiers'] as $fichier) {
                    $this->db->insert('fichiers_ressource', [
                        'ressource_id' => $id,
                        'nom' => $fichier['nom'],
                        'type' => $fichier['type'],
                        'fichier' => $fichier['fichier'],
                        'date_ajout' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour de la ressource: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime une ressource
     * @param int $id ID de la ressource à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteRessource($id) {
        try {
            $this->db->beginTransaction();
            
            // Récupérer les informations de la ressource
            $ressource = $this->getRessourceById($id);
            
            if (!$ressource) {
                $this->db->rollback();
                return false;
            }
            
            // Supprimer les tags associés
            $this->db->delete('ressource_tag', 'ressource_id = :ressource_id', [':ressource_id' => $id]);
            
            // Pour les types FILE et GALLERY, supprimer les fichiers
            if ($ressource['type'] === RESSOURCE_FILE || $ressource['type'] === RESSOURCE_GALLERY) {
                $fichiers = $this->getFichiersByRessource($id);
                
                foreach ($fichiers as $fichier) {
                    // Supprimer le fichier physique
                    $cheminFichier = RESSOURCES_UPLOADS . '/' . $fichier['fichier'];
                    if (file_exists($cheminFichier)) {
                        unlink($cheminFichier);
                    }
                    
                    // Supprimer l'entrée dans la base de données
                    $this->db->delete('fichiers_ressource', 'id = :id', [':id' => $fichier['id']]);
                }
            }
            
            // Supprimer les associations avec les séances
            $this->db->delete('seance_ressource', 'ressource_id = :ressource_id', [':ressource_id' => $id]);
            
            // Supprimer la ressource
            $this->db->delete('ressources', 'id = :id', [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression de la ressource: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère les tags associés à une ressource
     * @param int $ressourceId ID de la ressource
     * @return array Liste des tags
     */
    public function getTagsByRessource($ressourceId) {
        $sql = "SELECT t.* 
                FROM tags t
                JOIN ressource_tag rt ON t.id = rt.tag_id
                WHERE rt.ressource_id = :ressource_id
                ORDER BY t.nom";
        
        return $this->db->fetchAll($sql, [':ressource_id' => $ressourceId]);
    }
    
    /**
     * Récupère les fichiers associés à une ressource
     * @param int $ressourceId ID de la ressource
     * @return array Liste des fichiers
     */
    public function getFichiersByRessource($ressourceId) {
        $sql = "SELECT * FROM fichiers_ressource WHERE ressource_id = :ressource_id ORDER BY date_ajout DESC";
        return $this->db->fetchAll($sql, [':ressource_id' => $ressourceId]);
    }
    
    /**
     * Ajoute un fichier à une ressource
     * @param int $ressourceId ID de la ressource
     * @param array $fichier Informations du fichier
     * @return int|false ID du fichier créé ou false en cas d'échec
     */
    public function ajouterFichier($ressourceId, $fichier) {
        return $this->db->insert('fichiers_ressource', [
            'ressource_id' => $ressourceId,
            'nom' => $fichier['nom'],
            'type' => $fichier['type'],
            'fichier' => $fichier['fichier'],
            'date_ajout' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Supprime un fichier d'une ressource
     * @param int $fichierId ID du fichier
     * @return bool Succès ou échec de la suppression
     */
    public function supprimerFichier($fichierId) {
        // Récupérer les informations du fichier
        $sql = "SELECT * FROM fichiers_ressource WHERE id = :id";
        $fichier = $this->db->fetch($sql, [':id' => $fichierId]);
        
        if (!$fichier) {
            return false;
        }
        
        // Supprimer le fichier physique
        $cheminFichier = RESSOURCES_UPLOADS . '/' . $fichier['fichier'];
        if (file_exists($cheminFichier)) {
            unlink($cheminFichier);
        }
        
        // Supprimer l'entrée dans la base de données
        return $this->db->delete('fichiers_ressource', 'id = :id', [':id' => $fichierId]);
    }
    
    /**
     * Récupère tous les tags disponibles
     * @return array Liste des tags
     */
    public function getAllTags() {
        $sql = "SELECT * FROM tags ORDER BY nom";
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Crée un nouveau tag
     * @param string $nom Nom du tag
     * @return int|false ID du tag créé ou false en cas d'échec
     */
    public function createTag($nom) {
        // Vérifier si le tag existe déjà
        $sql = "SELECT id FROM tags WHERE nom = :nom";
        $tag = $this->db->fetch($sql, [':nom' => $nom]);
        
        if ($tag) {
            return $tag['id'];
        }
        
        // Créer le tag
        return $this->db->insert('tags', ['nom' => $nom]);
    }
    
    /**
     * Récupère les ressources associées à une séance
     * @param string $seanceId ID de la séance
     * @return array Liste des ressources
     */
    public function getRessourcesBySeance($seanceId) {
        $sql = "SELECT r.* 
                FROM ressources r
                JOIN seance_ressource sr ON r.id = sr.ressource_id
                WHERE sr.seance_id = :seance_id
                ORDER BY r.titre";
        
        $ressources = $this->db->fetchAll($sql, [':seance_id' => $seanceId]);
        
        // Récupérer les fichiers pour les ressources de type FILE ou GALLERY
        foreach ($ressources as &$ressource) {
            if ($ressource['type'] === RESSOURCE_FILE || $ressource['type'] === RESSOURCE_GALLERY) {
                $ressource['fichiers'] = $this->getFichiersByRessource($ressource['id']);
            }
        }
        
        return $ressources;
    }
}