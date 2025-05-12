<?php
/**
 * Modèle pour la gestion des séances du cahier de texte
 */
class Seance {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère toutes les séances avec filtres et pagination
     * @param array $filters Filtres à appliquer
     * @param int $page Numéro de page
     * @param int $perPage Nombre d'éléments par page
     * @return array Liste des séances
     */
    public function getAllSeances($filters = [], $page = 1, $perPage = ITEMS_PER_PAGE) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['classe_id'])) {
            $where[] = "s.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        if (!empty($filters['matiere_id'])) {
            $where[] = "s.matiere_id = :matiere_id";
            $params[':matiere_id'] = $filters['matiere_id'];
        }
        
        if (!empty($filters['professeur_id'])) {
            $where[] = "s.professeur_id = :professeur_id";
            $params[':professeur_id'] = $filters['professeur_id'];
        }
        
        if (!empty($filters['chapitre_id'])) {
            $where[] = "s.chapitre_id = :chapitre_id";
            $params[':chapitre_id'] = $filters['chapitre_id'];
        }
        
        if (!empty($filters['statut'])) {
            $where[] = "s.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(s.titre LIKE :search OR s.contenu LIKE :search OR s.objectifs LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Filtrage par dates
        if (!empty($filters['date_debut']) && !empty($filters['date_fin'])) {
            $where[] = "s.date_debut BETWEEN :date_debut AND :date_fin";
            $params[':date_debut'] = $filters['date_debut'];
            $params[':date_fin'] = $filters['date_fin'];
        } else if (!empty($filters['date_debut'])) {
            $where[] = "s.date_debut >= :date_debut";
            $params[':date_debut'] = $filters['date_debut'];
        } else if (!empty($filters['date_fin'])) {
            $where[] = "s.date_debut <= :date_fin";
            $params[':date_fin'] = $filters['date_fin'];
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Calcul de l'offset pour la pagination
        $offset = ($page - 1) * $perPage;
        
        // Requête SQL pour récupérer les séances avec les informations associées
        $sql = "SELECT s.*, 
                   m.nom AS matiere_nom, 
                   m.couleur AS matiere_couleur,
                   c.nom AS classe_nom,
                   u.first_name AS professeur_prenom, 
                   u.last_name AS professeur_nom,
                   ch.titre AS chapitre_titre,
                   TIMESTAMPDIFF(MINUTE, s.date_debut, s.date_fin) AS duree_minutes
                FROM seances s
                LEFT JOIN matieres m ON s.matiere_id = m.id
                LEFT JOIN classes c ON s.classe_id = c.id
                LEFT JOIN users u ON s.professeur_id = u.id
                LEFT JOIN chapitres ch ON s.chapitre_id = ch.id
                $whereClause
                ORDER BY " . (!empty($filters['order_by']) ? $filters['order_by'] : "s.date_debut ASC") . "
                LIMIT :limit OFFSET :offset";
        
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        
        // Exécution de la requête
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Compte le nombre total de séances selon les filtres
     * @param array $filters Filtres à appliquer
     * @return int Nombre total de séances
     */
    public function countSeances($filters = []) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres (similaire à getAllSeances)
        if (!empty($filters['classe_id'])) {
            $where[] = "s.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        if (!empty($filters['matiere_id'])) {
            $where[] = "s.matiere_id = :matiere_id";
            $params[':matiere_id'] = $filters['matiere_id'];
        }
        
        if (!empty($filters['professeur_id'])) {
            $where[] = "s.professeur_id = :professeur_id";
            $params[':professeur_id'] = $filters['professeur_id'];
        }
        
        if (!empty($filters['chapitre_id'])) {
            $where[] = "s.chapitre_id = :chapitre_id";
            $params[':chapitre_id'] = $filters['chapitre_id'];
        }
        
        if (!empty($filters['statut'])) {
            $where[] = "s.statut = :statut";
            $params[':statut'] = $filters['statut'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(s.titre LIKE :search OR s.contenu LIKE :search OR s.objectifs LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Filtrage par dates
        if (!empty($filters['date_debut']) && !empty($filters['date_fin'])) {
            $where[] = "s.date_debut BETWEEN :date_debut AND :date_fin";
            $params[':date_debut'] = $filters['date_debut'];
            $params[':date_fin'] = $filters['date_fin'];
        } else if (!empty($filters['date_debut'])) {
            $where[] = "s.date_debut >= :date_debut";
            $params[':date_debut'] = $filters['date_debut'];
        } else if (!empty($filters['date_fin'])) {
            $where[] = "s.date_debut <= :date_fin";
            $params[':date_fin'] = $filters['date_fin'];
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL pour compter les séances
        $sql = "SELECT COUNT(*) as total FROM seances s $whereClause";
        
        // Exécution de la requête
        $result = $this->db->fetch($sql, $params);
        return (int) $result['total'];
    }
    
    /**
     * Récupère une séance par son ID
     * @param string $id ID de la séance
     * @return array|false Informations de la séance ou false si non trouvée
     */
    public function getSeanceById($id) {
        // Récupérer la séance
        $sql = "SELECT s.*, 
                   m.nom AS matiere_nom, 
                   m.couleur AS matiere_couleur,
                   c.nom AS classe_nom,
                   u.first_name AS professeur_prenom, 
                   u.last_name AS professeur_nom,
                   ch.titre AS chapitre_titre,
                   TIMESTAMPDIFF(MINUTE, s.date_debut, s.date_fin) AS duree_minutes
                FROM seances s
                LEFT JOIN matieres m ON s.matiere_id = m.id
                LEFT JOIN classes c ON s.classe_id = c.id
                LEFT JOIN users u ON s.professeur_id = u.id
                LEFT JOIN chapitres ch ON s.chapitre_id = ch.id
                WHERE s.id = :id";
        
        $seance = $this->db->fetch($sql, [':id' => $id]);
        
        if (!$seance) {
            return false;
        }
        
        // Récupérer les ressources associées à la séance
        $sql = "SELECT r.* 
                FROM ressources r
                JOIN seance_ressource sr ON r.id = sr.ressource_id
                WHERE sr.seance_id = :seance_id";
        
        $ressources = $this->db->fetchAll($sql, [':seance_id' => $id]);
        $seance['ressources'] = $ressources;
        
        // Récupérer les compétences associées à la séance
        $sql = "SELECT c.* 
                FROM competences c
                JOIN seance_competence sc ON c.id = sc.competence_id
                WHERE sc.seance_id = :seance_id";
        
        $competences = $this->db->fetchAll($sql, [':seance_id' => $id]);
        $seance['competences'] = $competences;
        
        return $seance;
    }
    
    /**
     * Crée une nouvelle séance
     * @param array $data Données de la séance
     * @return string|false ID de la séance créée ou false en cas d'échec
     */
    public function createSeance($data) {
        try {
            $this->db->beginTransaction();
            
            // Générer un UUID pour l'ID de la séance
            $id = $this->generateUUID();
            
            // Insertion de la séance
            $seanceData = [
                'id' => $id,
                'titre' => $data['titre'],
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'lieu' => $data['lieu'] ?? '',
                'statut' => $data['statut'] ?? STATUT_PREVISIONNELLE,
                'matiere_id' => $data['matiere_id'],
                'classe_id' => $data['classe_id'],
                'professeur_id' => $data['professeur_id'],
                'chapitre_id' => $data['chapitre_id'] ?? null,
                'contenu' => $data['contenu'],
                'objectifs' => $data['objectifs'] ?? '',
                'modalites' => $data['modalites'] ?? '',
                'est_recurrente' => $data['est_recurrente'] ?? 0,
                'recurrence' => $data['recurrence'] ?? null,
                'seance_parent_id' => $data['seance_parent_id'] ?? null
            ];
            
            $this->db->insert('seances', $seanceData);
            
            // Association avec les compétences si spécifiées
            if (!empty($data['competences']) && is_array($data['competences'])) {
                foreach ($data['competences'] as $competenceId) {
                    $this->db->insert('seance_competence', [
                        'seance_id' => $id,
                        'competence_id' => $competenceId
                    ]);
                }
            }
            
            // Association avec les ressources si spécifiées
            if (!empty($data['ressources']) && is_array($data['ressources'])) {
                foreach ($data['ressources'] as $ressourceId) {
                    $this->db->insert('seance_ressource', [
                        'seance_id' => $id,
                        'ressource_id' => $ressourceId
                    ]);
                }
            }
            
            // Si la séance est récurrente, générer les occurrences
            if (!empty($data['est_recurrente']) && !empty($data['recurrence']) && !empty($data['date_fin_recurrence'])) {
                $this->genererSeancesRecurrentes($id, $data['date_fin_recurrence']);
            }
            
            $this->db->commit();
            return $id;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la création de la séance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Met à jour une séance existante
     * @param string $id ID de la séance
     * @param array $data Nouvelles données de la séance
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateSeance($id, $data) {
        try {
            $this->db->beginTransaction();
            
            // Mise à jour de la séance
            $updateData = [];
            
            if (isset($data['titre'])) $updateData['titre'] = $data['titre'];
            if (isset($data['date_debut'])) $updateData['date_debut'] = $data['date_debut'];
            if (isset($data['date_fin'])) $updateData['date_fin'] = $data['date_fin'];
            if (isset($data['lieu'])) $updateData['lieu'] = $data['lieu'];
            if (isset($data['statut'])) $updateData['statut'] = $data['statut'];
            if (isset($data['matiere_id'])) $updateData['matiere_id'] = $data['matiere_id'];
            if (isset($data['classe_id'])) $updateData['classe_id'] = $data['classe_id'];
            if (isset($data['chapitre_id'])) $updateData['chapitre_id'] = $data['chapitre_id'];
            if (isset($data['contenu'])) $updateData['contenu'] = $data['contenu'];
            if (isset($data['objectifs'])) $updateData['objectifs'] = $data['objectifs'];
            if (isset($data['modalites'])) $updateData['modalites'] = $data['modalites'];
            if (isset($data['est_recurrente'])) $updateData['est_recurrente'] = $data['est_recurrente'];
            if (isset($data['recurrence'])) $updateData['recurrence'] = $data['recurrence'];
            
            // Incrémenter la version
            $updateData['version'] = $this->getSeanceVersion($id) + 1;
            
            if (!empty($updateData)) {
                $this->db->update('seances', $updateData, 'id = :id', [':id' => $id]);
            }
            
            // Mise à jour des compétences si spécifiées
            if (isset($data['competences']) && is_array($data['competences'])) {
                // Supprimer les associations existantes
                $this->db->delete('seance_competence', 'seance_id = :seance_id', [':seance_id' => $id]);
                
                // Ajouter les nouvelles associations
                foreach ($data['competences'] as $competenceId) {
                    $this->db->insert('seance_competence', [
                        'seance_id' => $id,
                        'competence_id' => $competenceId
                    ]);
                }
            }
            
            // Mise à jour des ressources si spécifiées
            if (isset($data['ressources']) && is_array($data['ressources'])) {
                // Supprimer les associations existantes
                $this->db->delete('seance_ressource', 'seance_id = :seance_id', [':seance_id' => $id]);
                
                // Ajouter les nouvelles associations
                foreach ($data['ressources'] as $ressourceId) {
                    $this->db->insert('seance_ressource', [
                        'seance_id' => $id,
                        'ressource_id' => $ressourceId
                    ]);
                }
            }
            
            // Mise à jour des séances récurrentes si demandé
            if (!empty($data['update_recurrence']) && $data['update_recurrence'] === true) {
                $this->updateSeancesRecurrentes($id, $data);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour de la séance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime une séance
     * @param string $id ID de la séance à supprimer
     * @param bool $deleteRecurrences Supprimer aussi les séances récurrentes
     * @return bool Succès ou échec de la suppression
     */
    public function deleteSeance($id, $deleteRecurrences = false) {
        try {
            $this->db->beginTransaction();
            
            // Si demandé, supprimer aussi les séances récurrentes
            if ($deleteRecurrences) {
                $seancesRecurrentes = $this->getSeancesRecurrentes($id);
                foreach ($seancesRecurrentes as $seance) {
                    // Supprimer les associations avec les compétences
                    $this->db->delete('seance_competence', 'seance_id = :seance_id', [':seance_id' => $seance['id']]);
                    
                    // Supprimer les associations avec les ressources
                    $this->db->delete('seance_ressource', 'seance_id = :seance_id', [':seance_id' => $seance['id']]);
                    
                    // Supprimer les notifications associées à la séance
                    $this->db->delete('notifications', 'seance_id = :seance_id', [':seance_id' => $seance['id']]);
                    
                    // Supprimer la séance
                    $this->db->delete('seances', 'id = :id', [':id' => $seance['id']]);
                }
            }
            
            // Supprimer les associations avec les compétences
            $this->db->delete('seance_competence', 'seance_id = :seance_id', [':seance_id' => $id]);
            
            // Supprimer les associations avec les ressources
            $this->db->delete('seance_ressource', 'seance_id = :seance_id', [':seance_id' => $id]);
            
            // Supprimer les notifications associées à la séance
            $this->db->delete('notifications', 'seance_id = :seance_id', [':seance_id' => $id]);
            
            // Supprimer la séance
            $this->db->delete('seances', 'id = :id', [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression de la séance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Génère des séances récurrentes à partir d'une séance parent
     * @param string $seanceParentId ID de la séance parent
     * @param string $dateFin Date de fin jusqu'à laquelle générer les récurrences (Y-m-d)
     * @return array Liste des IDs des séances générées
     */
    public function genererSeancesRecurrentes($seanceParentId, $dateFin) {
        $seanceParent = $this->getSeanceById($seanceParentId);
        
        if (!$seanceParent || empty($seanceParent['est_recurrente']) || empty($seanceParent['recurrence'])) {
            return [];
        }
        
        $seancesGenerees = [];
        
        // Analyser la règle de récurrence
        $rule = $seanceParent['recurrence']; // Format: 'FREQ=WEEKLY;BYDAY=MO' par exemple
        
        // Date de début et de fin
        $start = new DateTime($seanceParent['date_debut']);
        $end = new DateTime($dateFin);
        
        // Intervalle de temps entre la date de début et la date de fin de la séance parent
        $interval = (new DateTime($seanceParent['date_debut']))->diff(new DateTime($seanceParent['date_fin']));
        
        // Traiter la règle de récurrence
        $dates = $this->calculateRecurringDates($rule, $start, $end);
        
        // Créer les séances pour chaque date
        foreach ($dates as $date) {
            // Calculer la date de fin en ajoutant l'intervalle
            $dateFin = clone $date;
            $dateFin->add($interval);
            
            // Créer la nouvelle séance
            $nouvelleSeance = [
                'titre' => $seanceParent['titre'],
                'date_debut' => $date->format('Y-m-d H:i:s'),
                'date_fin' => $dateFin->format('Y-m-d H:i:s'),
                'lieu' => $seanceParent['lieu'],
                'statut' => STATUT_PREVISIONNELLE,
                'matiere_id' => $seanceParent['matiere_id'],
                'classe_id' => $seanceParent['classe_id'],
                'professeur_id' => $seanceParent['professeur_id'],
                'chapitre_id' => $seanceParent['chapitre_id'],
                'contenu' => $seanceParent['contenu'],
                'objectifs' => $seanceParent['objectifs'],
                'modalites' => $seanceParent['modalites'],
                'est_recurrente' => 0, // Les séances générées ne sont pas récurrentes
                'seance_parent_id' => $seanceParentId,
                'competences' => $this->getCompetencesIds($seanceParentId),
                'ressources' => $this->getRessourcesIds($seanceParentId)
            ];
            
            $nouvelleSeanceId = $this->createSeance($nouvelleSeance);
            
            if ($nouvelleSeanceId) {
                $seancesGenerees[] = $nouvelleSeanceId;
            }
        }
        
        return $seancesGenerees;
    }
    
    /**
     * Récupère les séances récurrentes générées à partir d'une séance parent
     * @param string $seanceParentId ID de la séance parent
     * @return array Liste des séances récurrentes
     */
    public function getSeancesRecurrentes($seanceParentId) {
        $sql = "SELECT s.* 
                FROM seances s
                WHERE s.seance_parent_id = :seance_parent_id
                ORDER BY s.date_debut ASC";
        
        return $this->db->fetchAll($sql, [':seance_parent_id' => $seanceParentId]);
    }
    
    /**
     * Met à jour les séances récurrentes après modification de la séance parent
     * @param string $seanceParentId ID de la séance parent
     * @param array $data Données mises à jour
     * @return bool Succès ou échec de l'opération
     */
    public function updateSeancesRecurrentes($seanceParentId, $data) {
        $seancesRecurrentes = $this->getSeancesRecurrentes($seanceParentId);
        
        if (empty($seancesRecurrentes)) {
            return true;
        }
        
        $fieldsToUpdate = [
            'titre', 'lieu', 'matiere_id', 'chapitre_id', 
            'contenu', 'objectifs', 'modalites'
        ];
        
        $updateData = [];
        foreach ($fieldsToUpdate as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return true;
        }
        
        try {
            $this->db->beginTransaction();
            
            foreach ($seancesRecurrentes as $seance) {
                // Mise à jour des données de base
                $this->db->update('seances', $updateData, 'id = :id', [':id' => $seance['id']]);
                
                // Mise à jour des compétences si spécifiées
                if (isset($data['competences']) && is_array($data['competences'])) {
                    // Supprimer les associations existantes
                    $this->db->delete('seance_competence', 'seance_id = :seance_id', [':seance_id' => $seance['id']]);
                    
                    // Ajouter les nouvelles associations
                    foreach ($data['competences'] as $competenceId) {
                        $this->db->insert('seance_competence', [
                            'seance_id' => $seance['id'],
                            'competence_id' => $competenceId
                        ]);
                    }
                }
                
                // Mise à jour des ressources si spécifiées
                if (isset($data['ressources']) && is_array($data['ressources'])) {
                    // Supprimer les associations existantes
                    $this->db->delete('seance_ressource', 'seance_id = :seance_id', [':seance_id' => $seance['id']]);
                    
                    // Ajouter les nouvelles associations
                    foreach ($data['ressources'] as $ressourceId) {
                        $this->db->insert('seance_ressource', [
                            'seance_id' => $seance['id'],
                            'ressource_id' => $ressourceId
                        ]);
                    }
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la mise à jour des séances récurrentes: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère les séances pour l'affichage en calendrier
     * @param string $dateDebut Date de début (Y-m-d)
     * @param string $dateFin Date de fin (Y-m-d)
     * @param array $filters Filtres supplémentaires
     * @return array Événements du calendrier
     */
    public function getSeancesCalendar($dateDebut, $dateFin, $filters = []) {
        $params = [
            ':date_debut' => $dateDebut . ' 00:00:00',
            ':date_fin' => $dateFin . ' 23:59:59'
        ];
        
        $where = ["s.date_debut BETWEEN :date_debut AND :date_fin"];
        
        // Filtrer par classe
        if (!empty($filters['classe_id'])) {
            $where[] = "s.classe_id = :classe_id";
            $params[':classe_id'] = $filters['classe_id'];
        }
        
        // Filtrer par matière
        if (!empty($filters['matiere_id'])) {
            $where[] = "s.matiere_id = :matiere_id";
            $params[':matiere_id'] = $filters['matiere_id'];
        }
        
        // Filtrer par professeur
        if (!empty($filters['professeur_id'])) {
            $where[] = "s.professeur_id = :professeur_id";
            $params[':professeur_id'] = $filters['professeur_id'];
        }
        
        $whereClause = implode(" AND ", $where);
        
        $sql = "SELECT 
                   s.id,
                   s.titre as title,
                   s.date_debut as start,
                   s.date_fin as end,
                   m.couleur as color,
                   s.classe_id as resourceId,
                   s.statut,
                   m.nom as matiere_nom,
                   c.nom as classe_nom,
                   CONCAT(u.first_name, ' ', u.last_name) as professeur_nom,
                   TIMESTAMPDIFF(MINUTE, s.date_debut, s.date_fin) as duree_minutes
                FROM seances s
                JOIN matieres m ON s.matiere_id = m.id
                JOIN classes c ON s.classe_id = c.id
                JOIN users u ON s.professeur_id = u.id
                WHERE $whereClause
                ORDER BY s.date_debut ASC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Récupère les ressources associées à une séance
     * @param string $seanceId ID de la séance
     * @return array Liste des ressources
     */
    public function getRessources($seanceId) {
        $sql = "SELECT r.* 
                FROM ressources r
                JOIN seance_ressource sr ON r.id = sr.ressource_id
                WHERE sr.seance_id = :seance_id";
        
        return $this->db->fetchAll($sql, [':seance_id' => $seanceId]);
    }
    
    /**
     * Récupère les IDs des ressources associées à une séance
     * @param string $seanceId ID de la séance
     * @return array Liste des IDs de ressources
     */
    public function getRessourcesIds($seanceId) {
        $sql = "SELECT ressource_id 
                FROM seance_ressource 
                WHERE seance_id = :seance_id";
        
        $result = $this->db->fetchAll($sql, [':seance_id' => $seanceId]);
        return array_column($result, 'ressource_id');
    }
    
    /**
     * Ajoute une ressource à une séance
     * @param string $seanceId ID de la séance
     * @param int $ressourceId ID de la ressource
     * @return bool Succès ou échec de l'opération
     */
    public function ajouterRessource($seanceId, $ressourceId) {
        try {
            $this->db->insert('seance_ressource', [
                'seance_id' => $seanceId,
                'ressource_id' => $ressourceId
            ]);
            return true;
        } catch (Exception $e) {
            error_log("Erreur lors de l'ajout de la ressource: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retire une ressource d'une séance
     * @param string $seanceId ID de la séance
     * @param int $ressourceId ID de la ressource
     * @return bool Succès ou échec de l'opération
     */
    public function retirerRessource($seanceId, $ressourceId) {
        try {
            $this->db->delete(
                'seance_ressource', 
                'seance_id = :seance_id AND ressource_id = :ressource_id', 
                [':seance_id' => $seanceId, ':ressource_id' => $ressourceId]
            );
            return true;
        } catch (Exception $e) {
            error_log("Erreur lors du retrait de la ressource: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère les compétences associées à une séance
     * @param string $seanceId ID de la séance
     * @return array Liste des compétences
     */
    public function getCompetences($seanceId) {
        $sql = "SELECT c.* 
                FROM competences c
                JOIN seance_competence sc ON c.id = sc.competence_id
                WHERE sc.seance_id = :seance_id";
        
        return $this->db->fetchAll($sql, [':seance_id' => $seanceId]);
    }
    
    /**
     * Récupère les IDs des compétences associées à une séance
     * @param string $seanceId ID de la séance
     * @return array Liste des IDs de compétences
     */
    public function getCompetencesIds($seanceId) {
        $sql = "SELECT competence_id 
                FROM seance_competence 
                WHERE seance_id = :seance_id";
        
        $result = $this->db->fetchAll($sql, [':seance_id' => $seanceId]);
        return array_column($result, 'competence_id');
    }
    
    /**
     * Change le statut d'une séance
     * @param string $id ID de la séance
     * @param string $statut Nouveau statut
     * @return bool Succès ou échec du changement de statut
     */
    public function changerStatut($id, $statut) {
        return $this->db->update('seances', 
                                ['statut' => $statut],
                                'id = :id',
                                [':id' => $id]);
    }
    
    /**
     * Duplique une séance à une nouvelle date
     * @param string $id ID de la séance à dupliquer
     * @param string $nouvelleDate Nouvelle date (Y-m-d)
     * @return string|false ID de la nouvelle séance ou false en cas d'échec
     */
    public function dupliquerSeance($id, $nouvelleDate) {
        $seance = $this->getSeanceById($id);
        
        if (!$seance) {
            return false;
        }
        
        // Calculer la différence de temps entre l'ancienne et la nouvelle date
        $oldStart = new DateTime($seance['date_debut']);
        $newStart = new DateTime($nouvelleDate . ' ' . $oldStart->format('H:i:s'));
        $diff = $oldStart->diff($newStart);
        
        // Calculer la nouvelle date de fin
        $oldEnd = new DateTime($seance['date_fin']);
        $newEnd = clone $oldEnd;
        $newEnd->add($diff);
        
        // Créer la nouvelle séance
        $nouvelleSeance = [
            'titre' => $seance['titre'],
            'date_debut' => $newStart->format('Y-m-d H:i:s'),
            'date_fin' => $newEnd->format('Y-m-d H:i:s'),
            'lieu' => $seance['lieu'],
            'statut' => STATUT_PREVISIONNELLE,
            'matiere_id' => $seance['matiere_id'],
            'classe_id' => $seance['classe_id'],
            'professeur_id' => $seance['professeur_id'],
            'chapitre_id' => $seance['chapitre_id'],
            'contenu' => $seance['contenu'],
            'objectifs' => $seance['objectifs'],
            'modalites' => $seance['modalites'],
            'est_recurrente' => 0, // La séance dupliquée n'est pas récurrente
            'competences' => $this->getCompetencesIds($id),
            'ressources' => $this->getRessourcesIds($id)
        ];
        
        return $this->createSeance($nouvelleSeance);
    }
    
    /**
     * Calcule le nombre de versions d'une séance
     * @param string $id ID de la séance
     * @return int Numéro de version actuel
     */
    private function getSeanceVersion($id) {
        $sql = "SELECT version FROM seances WHERE id = :id";
        $result = $this->db->fetch($sql, [':id' => $id]);
        return $result ? (int) $result['version'] : 0;
    }
    
    /**
     * Génère un UUID v4
     * @return string UUID
     */
    private function generateUUID() {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $data = openssl_random_pseudo_bytes(16);
        } else {
            for ($i = 0; $i < 16; $i++) {
                $data .= chr(mt_rand(0, 255));
            }
        }
        
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC4122
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Calcule les dates selon une règle de récurrence
     * @param string $rule Règle de récurrence (format iCalendar RRULE)
     * @param DateTime $start Date de début
     * @param DateTime $end Date de fin
     * @return array Liste des dates générées
     */
    private function calculateRecurringDates($rule, $start, $end) {
        $dates = [];
        $currentDate = clone $start;
        $currentDate->modify('+1 week'); // Commencer à la semaine suivante
        
        // Analyser la règle de récurrence
        $parts = explode(';', $rule);
        $freq = '';
        $byDay = '';
        
        foreach ($parts as $part) {
            if (strpos($part, 'FREQ=') === 0) {
                $freq = substr($part, 5);
            } elseif (strpos($part, 'BYDAY=') === 0) {
                $byDay = substr($part, 6);
            }
        }
        
        // Convertir BYDAY en jours de la semaine PHP
        $daysMap = [
            'MO' => 'Monday',
            'TU' => 'Tuesday',
            'WE' => 'Wednesday',
            'TH' => 'Thursday',
            'FR' => 'Friday',
            'SA' => 'Saturday',
            'SU' => 'Sunday'
        ];
        
        $days = explode(',', $byDay);
        $phpDays = [];
        foreach ($days as $day) {
            if (isset($daysMap[$day])) {
                $phpDays[] = $daysMap[$day];
            }
        }
        
        // Si FREQ=WEEKLY
        if ($freq === 'WEEKLY') {
            while ($currentDate <= $end) {
                $dayOfWeek = $currentDate->format('l'); // Jour de la semaine
                
                if (empty($phpDays) || in_array($dayOfWeek, $phpDays)) {
                    $cloneDate = clone $currentDate;
                    $cloneDate->setTime(
                        (int) $start->format('H'),
                        (int) $start->format('i'),
                        (int) $start->format('s')
                    );
                    $dates[] = $cloneDate;
                }
                
                $currentDate->modify('+1 day');
            }
        }
        // Si FREQ=DAILY
        elseif ($freq === 'DAILY') {
            while ($currentDate <= $end) {
                $cloneDate = clone $currentDate;
                $cloneDate->setTime(
                    (int) $start->format('H'),
                    (int) $start->format('i'),
                    (int) $start->format('s')
                );
                $dates[] = $cloneDate;
                
                $currentDate->modify('+1 day');
            }
        }
        // Si FREQ=MONTHLY
        elseif ($freq === 'MONTHLY') {
            $dayOfMonth = (int) $start->format('j'); // Jour du mois
            
            while ($currentDate <= $end) {
                // Définir le jour du mois
                $cloneDate = clone $currentDate;
                $cloneDate->setDate(
                    (int) $currentDate->format('Y'),
                    (int) $currentDate->format('m'),
                    $dayOfMonth
                );
                
                // Vérifier si la date est valide (pour gérer les mois avec moins de jours)
                if ($cloneDate->format('j') == $dayOfMonth) {
                    $cloneDate->setTime(
                        (int) $start->format('H'),
                        (int) $start->format('i'),
                        (int) $start->format('s')
                    );
                    $dates[] = $cloneDate;
                }
                
                $currentDate->modify('first day of next month');
            }
        }
        
        return $dates;
    }
}