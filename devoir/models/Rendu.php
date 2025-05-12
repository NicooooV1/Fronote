<?php
/**
 * Modèle pour la gestion des rendus de devoirs
 */
class Rendu {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère tous les rendus d'un devoir
     * @param int $devoirId ID du devoir
     * @return array Liste des rendus
     */
    public function getRendusByDevoir($devoirId) {
        $sql = "SELECT r.*, 
                   u.first_name AS eleve_prenom, 
                   u.last_name AS eleve_nom,
                   u.id AS eleve_id
                FROM rendus r
                JOIN users u ON r.eleve_id = u.id
                WHERE r.devoir_id = :devoir_id
                ORDER BY r.date_rendu DESC";
        
        $rendus = $this->db->fetchAll($sql, [':devoir_id' => $devoirId]);
        
        // Récupérer les fichiers associés à chaque rendu
        foreach ($rendus as &$rendu) {
            $rendu['fichiers'] = $this->getFichiersRendu($rendu['id']);
        }
        
        return $rendus;
    }
    
    /**
     * Récupère le rendu d'un élève pour un devoir
     * @param int $devoirId ID du devoir
     * @param int $eleveId ID de l'élève
     * @return array|false Informations du rendu ou false si non trouvé
     */
    public function getRenduEleve($devoirId, $eleveId) {
        $sql = "SELECT r.*, 
                   d.titre AS devoir_titre,
                   d.date_limite AS devoir_date_limite
                FROM rendus r
                JOIN devoirs d ON r.devoir_id = d.id
                WHERE r.devoir_id = :devoir_id AND r.eleve_id = :eleve_id";
        
        $rendu = $this->db->fetch($sql, [
            ':devoir_id' => $devoirId,
            ':eleve_id' => $eleveId
        ]);
        
        if ($rendu) {
            // Récupérer les fichiers associés au rendu
            $rendu['fichiers'] = $this->getFichiersRendu($rendu['id']);
        }
        
        return $rendu;
    }
    
    /**
     * Vérifie si un élève a déjà rendu un devoir
     * @param int $devoirId ID du devoir
     * @param int $eleveId ID de l'élève
     * @return bool Vrai si l'élève a déjà rendu le devoir
     */
    public function verifierRenduExistant($devoirId, $eleveId) {
        $sql = "SELECT id FROM rendus 
                WHERE devoir_id = :devoir_id AND eleve_id = :eleve_id";
        
        $result = $this->db->fetch($sql, [
            ':devoir_id' => $devoirId,
            ':eleve_id' => $eleveId
        ]);
        
        return $result !== false;
    }
    
    /**
     * Récupère un rendu par son ID
     * @param int $id ID du rendu
     * @return array|false Informations du rendu ou false si non trouvé
     */
    public function getRenduById($id) {
        $sql = "SELECT r.*, 
                   u.first_name AS eleve_prenom, 
                   u.last_name AS eleve_nom,
                   d.titre AS devoir_titre,
                   d.classe_id AS classe_id,
                   d.auteur_id AS professeur_id
                FROM rendus r
                JOIN users u ON r.eleve_id = u.id
                JOIN devoirs d ON r.devoir_id = d.id
                WHERE r.id = :id";
        
        $rendu = $this->db->fetch($sql, [':id' => $id]);
        
        if ($rendu) {
            // Récupérer les fichiers associés au rendu
            $rendu['fichiers'] = $this->getFichiersRendu($rendu['id']);
        }
        
        return $rendu;
    }
    
    /**
     * Crée un nouveau rendu
     * @param array $data Données du rendu
     * @return int|false ID du rendu créé ou false en cas d'échec
     */
    public function createRendu($data) {
        try {
            $this->db->beginTransaction();
            
            // Insertion du rendu
            $renduId = $this->db->insert('rendus', [
                'devoir_id' => $data['devoir_id'],
                'eleve_id' => $data['eleve_id'],
                'commentaire' => $data['commentaire'] ?? '',
                'date_rendu' => date('Y-m-d H:i:s'),
                'statut' => STATUT_RENDU
            ]);
            
            if (!$renduId) {
                $this->db->rollback();
                return false;
            }
            
            // Traitement des fichiers si présents
            if (!empty($data['fichiers']) && is_array($data['fichiers'])) {
                foreach ($data['fichiers'] as $fichier) {
                    $this->db->insert('fichiers_rendu', [
                        'rendu_id' => $renduId,
                        'nom' => $fichier['nom'],
                        'type' => $fichier['type'],
                        'fichier' => $fichier['fichier'],
                        'date_ajout' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            // Mettre à jour le statut du devoir si nécessaire
            $sql = "UPDATE devoirs SET statut = :statut 
                    WHERE id = :id AND statut != :statut_corrige";
            
            $this->db->query($sql, [
                ':statut' => STATUT_RENDU,
                ':id' => $data['devoir_id'],
                ':statut_corrige' => STATUT_CORRIGE
            ]);
            
            $this->db->commit();
            return $renduId;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la création du rendu: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Met à jour un rendu existant
     * @param int $id ID du rendu
     * @param array $data Nouvelles données du rendu
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateRendu($id, $data) {
        try {
            $this->db->beginTransaction();
            
            $updateData = [];
            
            if (isset($data['commentaire'])) $updateData['commentaire'] = $data['commentaire'];
            if (isset($data['statut'])) $updateData['statut'] = $data['statut'];
            if (isset($data['note'])) $updateData['note'] = $data['note'];
            if (isset($data['commentaire_prof'])) $updateData['commentaire_prof'] = $data['commentaire_prof'];
            if (isset($data['date_correction'])) $updateData['date_correction'] = $data['date_correction'];
            
            // Si des données à mettre à jour
            if (!empty($updateData)) {
                $this->db->update('rendus', $updateData, 'id = :id', [':id' => $id]);
            }
            
            // Traitement des nouveaux fichiers si présents
            if (!empty($data['nouveaux_fichiers']) && is_array($data['nouveaux_fichiers'])) {
                foreach ($data['nouveaux_fichiers'] as $fichier) {
                    $this->db->insert('fichiers_rendu', [
                        'rendu_id' => $id,
                        'nom' => $fichier['nom'],
                        'type' => $fichier['type'],
                        'fichier' => $fichier['fichier'],
                        'date_ajout' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            // Mise à jour du statut du devoir si nécessaire
            if (isset($data['statut']) && $data['statut'] === STATUT_CORRIGE) {
                $rendu = $this->getRenduById($id);
                
                if ($rendu) {
                    // Vérifier si tous les rendus du devoir sont corrigés
                    $sql = "SELECT COUNT(*) as total FROM rendus 
                            WHERE devoir_id = :devoir_id AND statut != :statut_corrige";
                    
                    $result = $this->db->fetch($sql, [
                        ':devoir_id' => $rendu['devoir_id'],
                        ':statut_corrige' => STATUT_CORRIGE
                    ]);
                    
                    // Si tous les rendus sont corrigés, mettre à jour le statut du devoir
                    if ($result['total'] == 0) {
                        $this->db->update('devoirs', 
                                        ['statut' => STATUT_CORRIGE], 
                                        'id = :id', 
                                        [':id' => $rendu['devoir_id']]);
                    }
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour du rendu: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime un rendu
     * @param int $id ID du rendu à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteRendu($id) {
        try {
            $this->db->beginTransaction();
            
            // Récupérer les informations du rendu
            $rendu = $this->getRenduById($id);
            
            if (!$rendu) {
                $this->db->rollback();
                return false;
            }
            
            // Supprimer les fichiers associés au rendu
            $fichiers = $this->getFichiersRendu($id);
            
            foreach ($fichiers as $fichier) {
                // Supprimer le fichier physique
                $cheminFichier = RENDUS_UPLOADS . '/' . $fichier['fichier'];
                if (file_exists($cheminFichier)) {
                    unlink($cheminFichier);
                }
                
                // Supprimer l'entrée dans la base de données
                $this->db->delete('fichiers_rendu', 'id = :id', [':id' => $fichier['id']]);
            }
            
            // Supprimer le rendu
            $this->db->delete('rendus', 'id = :id', [':id' => $id]);
            
            // Mettre à jour le statut du devoir si nécessaire
            $sql = "SELECT COUNT(*) as total FROM rendus WHERE devoir_id = :devoir_id";
            $result = $this->db->fetch($sql, [':devoir_id' => $rendu['devoir_id']]);
            
            if ($result['total'] == 0) {
                // Aucun rendu restant, remettre le statut à "À faire"
                $this->db->update('devoirs', 
                                ['statut' => STATUT_A_FAIRE], 
                                'id = :id', 
                                [':id' => $rendu['devoir_id']]);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression du rendu: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère les fichiers associés à un rendu
     * @param int $renduId ID du rendu
     * @return array Liste des fichiers
     */
    public function getFichiersRendu($renduId) {
        $sql = "SELECT * FROM fichiers_rendu WHERE rendu_id = :rendu_id ORDER BY date_ajout DESC";
        return $this->db->fetchAll($sql, [':rendu_id' => $renduId]);
    }
    
    /**
     * Ajoute un fichier à un rendu
     * @param int $renduId ID du rendu
     * @param array $fichier Informations du fichier
     * @return int|false ID du fichier créé ou false en cas d'échec
     */
    public function ajouterFichier($renduId, $fichier) {
        return $this->db->insert('fichiers_rendu', [
            'rendu_id' => $renduId,
            'nom' => $fichier['nom'],
            'type' => $fichier['type'],
            'fichier' => $fichier['fichier'],
            'date_ajout' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Supprime un fichier d'un rendu
     * @param int $fichierId ID du fichier
     * @return bool Succès ou échec de la suppression
     */
    public function supprimerFichier($fichierId) {
        // Récupérer les informations du fichier
        $sql = "SELECT * FROM fichiers_rendu WHERE id = :id";
        $fichier = $this->db->fetch($sql, [':id' => $fichierId]);
        
        if (!$fichier) {
            return false;
        }
        
        // Supprimer le fichier physique
        $cheminFichier = RENDUS_UPLOADS . '/' . $fichier['fichier'];
        if (file_exists($cheminFichier)) {
            unlink($cheminFichier);
        }
        
        // Supprimer l'entrée dans la base de données
        return $this->db->delete('fichiers_rendu', 'id = :id', [':id' => $fichierId]);
    }
    
    /**
     * Récupère les statistiques de rendus pour un devoir
     * @param int $devoirId ID du devoir
     * @return array Statistiques de rendus
     */
    public function getStatistiquesRendu($devoirId) {
        // Récupérer le nombre total d'élèves concernés par ce devoir
        $sql = "SELECT d.classe_id, d.travail_groupe 
                FROM devoirs d 
                WHERE d.id = :devoir_id";
        
        $devoir = $this->db->fetch($sql, [':devoir_id' => $devoirId]);
        
        if (!$devoir) {
            return [
                'total_eleves' => 0,
                'total_rendus' => 0,
                'total_corriges' => 0,
                'pourcentage_rendus' => 0,
                'pourcentage_corriges' => 0,
                'moyenne_notes' => 0,
                'note_min' => 0,
                'note_max' => 0
            ];
        }
        
        // Récupérer le nombre total d'élèves dans la classe
        $sql = "SELECT COUNT(*) as total 
                FROM eleve_classe 
                WHERE classe_id = :classe_id";
        
        $resultat = $this->db->fetch($sql, [':classe_id' => $devoir['classe_id']]);
        $totalEleves = $resultat['total'];
        
        // Si le devoir est associé à des groupes spécifiques
        $sql = "SELECT COUNT(*) as total 
                FROM devoir_groupe 
                WHERE devoir_id = :devoir_id";
        
        $resultat = $this->db->fetch($sql, [':devoir_id' => $devoirId]);
        
        if ($resultat['total'] > 0) {
            $totalEleves = 0;
            
            // Récupérer les IDs des groupes associés au devoir
            $sql = "SELECT groupe_id 
                    FROM devoir_groupe 
                    WHERE devoir_id = :devoir_id";
            
            $groupes = $this->db->fetchAll($sql, [':devoir_id' => $devoirId]);
            
            foreach ($groupes as $groupe) {
                // Compter les élèves dans chaque groupe
                $sql = "SELECT COUNT(*) as total 
                        FROM eleve_groupe 
                        WHERE groupe_id = :groupe_id";
                
                $resultat = $this->db->fetch($sql, [':groupe_id' => $groupe['groupe_id']]);
                $totalEleves += $resultat['total'];
            }
        }
        
        // Statistiques des rendus
        $sql = "SELECT 
                   COUNT(*) as total_rendus,
                   COUNT(CASE WHEN statut = :statut_corrige THEN 1 END) as total_corriges,
                   AVG(note) as moyenne_notes,
                   MIN(note) as note_min,
                   MAX(note) as note_max
                FROM rendus 
                WHERE devoir_id = :devoir_id";
        
        $stats = $this->db->fetch($sql, [
            ':devoir_id' => $devoirId,
            ':statut_corrige' => STATUT_CORRIGE
        ]);
        
        // Calculer les pourcentages
        $pourcentageRendus = ($totalEleves > 0) ? ($stats['total_rendus'] / $totalEleves) * 100 : 0;
        $pourcentageCorriges = ($stats['total_rendus'] > 0) ? ($stats['total_corriges'] / $stats['total_rendus']) * 100 : 0;
        
        return [
            'total_eleves' => $totalEleves,
            'total_rendus' => $stats['total_rendus'],
            'total_corriges' => $stats['total_corriges'],
            'pourcentage_rendus' => round($pourcentageRendus, 2),
            'pourcentage_corriges' => round($pourcentageCorriges, 2),
            'moyenne_notes' => round($stats['moyenne_notes'] ?? 0, 2),
            'note_min' => $stats['note_min'] ?? 0,
            'note_max' => $stats['note_max'] ?? 0
        ];
    }
    
    /**
     * Récupère tous les rendus d'un élève
     * @param int $eleveId ID de l'élève
     * @param array $filters Filtres à appliquer
     * @return array Liste des rendus
     */
    public function getRendusEleve($eleveId, $filters = []) {
        $params = [':eleve_id' => $eleveId];
        $where = ["r.eleve_id = :eleve_id"];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['statut'])) {
            $where[] = "r.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['devoir_id'])) {
            $where[] = "r.devoir_id = :devoir_id";
            $params[':devoir_id'] = $filters['devoir_id'];
        }
        
        // Requête SQL
        $sql = "SELECT r.*, 
                   d.titre AS devoir_titre,
                   d.date_limite AS devoir_date_limite,
                   d.classe_id AS classe_id,
                   c.nom AS classe_nom,
                   u.first_name AS professeur_prenom,
                   u.last_name AS professeur_nom
                FROM rendus r
                JOIN devoirs d ON r.devoir_id = d.id
                JOIN classes c ON d.classe_id = c.id
                JOIN users u ON d.auteur_id = u.id
                WHERE " . implode(" AND ", $where) . "
                ORDER BY r.date_rendu DESC";
        
        $rendus = $this->db->fetchAll($sql, $params);
        
        // Récupérer les fichiers associés à chaque rendu
        foreach ($rendus as &$rendu) {
            $rendu['fichiers'] = $this->getFichiersRendu($rendu['id']);
        }
        
        return $rendus;
    }
}