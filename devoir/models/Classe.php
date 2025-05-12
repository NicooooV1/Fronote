<?php
/**
 * Modèle pour la gestion des classes
 */
class Classe {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère toutes les classes
     * @return array Liste des classes
     */
    public function getAllClasses() {
        $sql = "SELECT * FROM classes ORDER BY nom";
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Récupère une classe par son ID
     * @param int $id ID de la classe
     * @return array|false Informations de la classe ou false si non trouvée
     */
    public function getClasseById($id) {
        $sql = "SELECT * FROM classes WHERE id = :id";
        return $this->db->fetch($sql, [':id' => $id]);
    }
    
    /**
     * Récupère les classes d'un professeur
     * @param int $professeurId ID du professeur
     * @return array Liste des classes
     */
    public function getClassesProfesseur($professeurId) {
        $sql = "SELECT c.* FROM classes c
                JOIN professeur_classe pc ON c.id = pc.classe_id
                WHERE pc.professeur_id = :professeur_id
                ORDER BY c.nom";
        
        return $this->db->fetchAll($sql, [':professeur_id' => $professeurId]);
    }
    
    /**
     * Récupère les classes d'un élève
     * @param int $eleveId ID de l'élève
     * @return array Liste des classes
     */
    public function getClassesEleve($eleveId) {
        $sql = "SELECT c.* FROM classes c
                JOIN eleve_classe ec ON c.id = ec.classe_id
                WHERE ec.eleve_id = :eleve_id
                ORDER BY c.nom";
        
        return $this->db->fetchAll($sql, [':eleve_id' => $eleveId]);
    }
    
    /**
     * Vérifie si un élève appartient à une classe
     * @param int $eleveId ID de l'élève
     * @param int $classeId ID de la classe
     * @return bool Vrai si l'élève est dans la classe
     */
    public function verifierEleveClasse($eleveId, $classeId) {
        $sql = "SELECT * FROM eleve_classe 
                WHERE eleve_id = :eleve_id AND classe_id = :classe_id";
        
        $result = $this->db->fetch($sql, [
            ':eleve_id' => $eleveId,
            ':classe_id' => $classeId
        ]);
        
        return $result !== false;
    }
    
    /**
     * Vérifie si un professeur est associé à une classe
     * @param int $professeurId ID du professeur
     * @param int $classeId ID de la classe
     * @return bool Vrai si le professeur est associé à la classe
     */
    public function verifierProfesseurClasse($professeurId, $classeId) {
        $sql = "SELECT * FROM professeur_classe 
                WHERE professeur_id = :professeur_id AND classe_id = :classe_id";
        
        $result = $this->db->fetch($sql, [
            ':professeur_id' => $professeurId,
            ':classe_id' => $classeId
        ]);
        
        return $result !== false;
    }
    
    /**
     * Crée une nouvelle classe
     * @param array $data Données de la classe
     * @return int|false ID de la classe créée ou false en cas d'échec
     */
    public function createClasse($data) {
        return $this->db->insert('classes', [
            'nom' => $data['nom'],
            'niveau' => $data['niveau'],
            'annee_scolaire' => $data['annee_scolaire'],
            'description' => $data['description'] ?? ''
        ]);
    }
    
    /**
     * Met à jour une classe existante
     * @param int $id ID de la classe
     * @param array $data Nouvelles données de la classe
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateClasse($id, $data) {
        $updateData = [];
        
        if (isset($data['nom'])) $updateData['nom'] = $data['nom'];
        if (isset($data['niveau'])) $updateData['niveau'] = $data['niveau'];
        if (isset($data['annee_scolaire'])) $updateData['annee_scolaire'] = $data['annee_scolaire'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        
        if (empty($updateData)) {
            return false;
        }
        
        return $this->db->update('classes', $updateData, 'id = :id', [':id' => $id]);
    }
    
    /**
     * Supprime une classe
     * @param int $id ID de la classe à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteClasse($id) {
        try {
            $this->db->beginTransaction();
            
            // Supprimer les associations élèves-classe
            $this->db->delete('eleve_classe', 'classe_id = :classe_id', [':classe_id' => $id]);
            
            // Supprimer les associations professeurs-classe
            $this->db->delete('professeur_classe', 'classe_id = :classe_id', [':classe_id' => $id]);
            
            // Supprimer les groupes associés à la classe
            $groupes = $this->db->fetchAll("SELECT id FROM groupes WHERE classe_id = :classe_id", [':classe_id' => $id]);
            foreach ($groupes as $groupe) {
                $this->db->delete('eleve_groupe', 'groupe_id = :groupe_id', [':groupe_id' => $groupe['id']]);
            }
            $this->db->delete('groupes', 'classe_id = :classe_id', [':classe_id' => $id]);
            
            // Supprimer la classe
            $this->db->delete('classes', 'id = :id', [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression de la classe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ajoute un élève à une classe
     * @param int $eleveId ID de l'élève
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function ajouterEleve($eleveId, $classeId) {
        // Vérifier si l'association existe déjà
        if ($this->verifierEleveClasse($eleveId, $classeId)) {
            return true;
        }
        
        try {
            return $this->db->insert('eleve_classe', [
                'eleve_id' => $eleveId,
                'classe_id' => $classeId
            ]);
        } catch (Exception $e) {
            error_log("Erreur lors de l'ajout de l'élève à la classe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retire un élève d'une classe
     * @param int $eleveId ID de l'élève
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function retirerEleve($eleveId, $classeId) {
        return $this->db->delete('eleve_classe', 
                              'eleve_id = :eleve_id AND classe_id = :classe_id', 
                              [':eleve_id' => $eleveId, ':classe_id' => $classeId]);
    }
    
    /**
     * Ajoute un professeur à une classe
     * @param int $professeurId ID du professeur
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function ajouterProfesseur($professeurId, $classeId) {
        // Vérifier si l'association existe déjà
        if ($this->verifierProfesseurClasse($professeurId, $classeId)) {
            return true;
        }
        
        try {
            return $this->db->insert('professeur_classe', [
                'professeur_id' => $professeurId,
                'classe_id' => $classeId
            ]);
        } catch (Exception $e) {
            error_log("Erreur lors de l'ajout du professeur à la classe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retire un professeur d'une classe
     * @param int $professeurId ID du professeur
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function retirerProfesseur($professeurId, $classeId) {
        return $this->db->delete('professeur_classe', 
                              'professeur_id = :professeur_id AND classe_id = :classe_id', 
                              [':professeur_id' => $professeurId, ':classe_id' => $classeId]);
    }
    
    /**
     * Récupère tous les groupes d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des groupes
     */
    public function getGroupes($classeId) {
        $sql = "SELECT * FROM groupes WHERE classe_id = :classe_id ORDER BY nom";
        return $this->db->fetchAll($sql, [':classe_id' => $classeId]);
    }
    
    /**
     * Récupère les élèves d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des élèves
     */
    public function getElevesClasse($classeId) {
        $sql = "SELECT u.* FROM users u
                JOIN eleve_classe ec ON u.id = ec.eleve_id
                WHERE ec.classe_id = :classe_id AND u.user_type = :user_type
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [
            ':classe_id' => $classeId,
            ':user_type' => TYPE_ELEVE
        ]);
    }
    
    /**
     * Récupère les professeurs d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des professeurs
     */
    public function getProfesseursClasse($classeId) {
        $sql = "SELECT u.*, m.nom as matiere_nom 
                FROM users u
                JOIN professeur_classe pc ON u.id = pc.professeur_id
                LEFT JOIN professeur_matiere pm ON u.id = pm.professeur_id
                LEFT JOIN matieres m ON pm.matiere_id = m.id
                WHERE pc.classe_id = :classe_id AND u.user_type = :user_type
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [
            ':classe_id' => $classeId,
            ':user_type' => TYPE_PROFESSEUR
        ]);
    }
}