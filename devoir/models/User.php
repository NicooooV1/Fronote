<?php
/**
 * Modèle pour la gestion des utilisateurs
 * Version modifiée pour intégrer le système de login existant
 */
class User {
    private $db;
    private $loginUser; // Instance de la classe User du système de login
    private $loginAuth; // Instance de la classe Auth du système de login
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
        
        // Initialiser les classes du système de login
        require_once ROOT_PATH . '/../login/src/user.php';
        require_once ROOT_PATH . '/../login/src/auth.php';
        
        $this->loginUser = new \User($this->db->getPDO());
        $this->loginAuth = new Auth($this->db->getPDO());
    }
    
    /**
     * Récupère tous les utilisateurs avec filtres et pagination
     * @param array $filters Filtres à appliquer
     * @param int $page Numéro de page
     * @param int $perPage Nombre d'éléments par page
     * @return array Liste des utilisateurs
     */
    public function getAllUsers($filters = [], $page = 1, $perPage = ITEMS_PER_PAGE) {
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['user_type'])) {
            $where[] = "user_type = :user_type";
            $params[':user_type'] = $filters['user_type'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(username LIKE :search OR email LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Calcul de l'offset pour la pagination
        $offset = ($page - 1) * $perPage;
        
        // Requête SQL pour récupérer les utilisateurs
        $sql = "SELECT * FROM users $whereClause ORDER BY last_name, first_name LIMIT :limit OFFSET :offset";
        
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        
        // Exécution de la requête
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Compte le nombre total d'utilisateurs selon les filtres
     * @param array $filters Filtres à appliquer
     * @return int Nombre total d'utilisateurs
     */
    public function countUsers($filters = []) {
        // Cette méthode peut être adaptée pour utiliser le système de login existant
        // Mais il est probable qu'elle soit spécifique à votre application
        
        $params = [];
        $where = [];
        
        // Construction des conditions WHERE selon les filtres
        if (!empty($filters['user_type'])) {
            $where[] = "user_type = :user_type";
            $params[':user_type'] = $filters['user_type'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(username LIKE :search OR email LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Préparation de la clause WHERE
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Requête SQL pour compter les utilisateurs
        $sql = "SELECT COUNT(*) as total FROM users $whereClause";
        
        // Exécution de la requête
        $result = $this->db->fetch($sql, $params);
        return (int) $result['total'];
    }
    
    /**
     * Récupère un utilisateur par son ID
     * @param int $id ID de l'utilisateur
     * @return array|false Informations de l'utilisateur ou false si non trouvé
     */
    public function getUserById($id) {
        // Déterminer le profil (type) de l'utilisateur
        $profil = $this->determinerProfil($id);
        
        if ($profil) {
            // Utiliser la classe User du système de login
            return $this->loginUser->getById($id, $profil);
        }
        
        // Fallback sur la méthode locale si le profil n'est pas déterminé
        $sql = "SELECT * FROM users WHERE id = :id";
        return $this->db->fetch($sql, [':id' => $id]);
    }
    
    /**
     * Récupère un utilisateur par son nom d'utilisateur
     * @param string $username Nom d'utilisateur
     * @return array|false Informations de l'utilisateur ou false si non trouvé
     */
    public function getUserByUsername($username) {
        // Pour cette méthode, nous devons chercher dans toutes les tables
        // car nous ne connaissons pas à l'avance le type d'utilisateur
        
        $tables = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'];
        
        foreach ($tables as $table) {
            $sql = "SELECT * FROM $table WHERE identifiant = :username LIMIT 1";
            $user = $this->db->fetch($sql, [':username' => $username]);
            
            if ($user) {
                $user['profil'] = $table;  // Ajouter l'information de profil
                return $user;
            }
        }
        
        return false;
    }
    
    /**
     * Récupère un utilisateur par son email
     * @param string $email Email de l'utilisateur
     * @return array|false Informations de l'utilisateur ou false si non trouvé
     */
    public function getUserByEmail($email) {
        // Même principe que getUserByUsername mais avec l'email
        
        $tables = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'];
        
        foreach ($tables as $table) {
            $sql = "SELECT * FROM $table WHERE mail = :email LIMIT 1";
            $user = $this->db->fetch($sql, [':email' => $email]);
            
            if ($user) {
                $user['profil'] = $table;
                return $user;
            }
        }
        
        return false;
    }
    
    /**
     * Crée un nouvel utilisateur
     * @param array $data Données de l'utilisateur
     * @return int|false ID de l'utilisateur créé ou false en cas d'échec
     */
    public function createUser($data) {
        // Utiliser la méthode create de la classe User du système de login
        $profil = $data['user_type'] ?? 'eleve';
        
        // Adapter le format des données si nécessaire
        $adaptedData = $this->adaptUserData($data, $profil);
        
        return $this->loginUser->create($profil, $adaptedData);
    }
    
    /**
     * Met à jour un utilisateur existant
     * @param int $id ID de l'utilisateur
     * @param array $data Nouvelles données de l'utilisateur
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateUser($id, $data) {
        // Pour l'instant, on garde la méthode existante
        // car elle peut être spécifique à votre application
        
        $updateData = [];
        
        // Vérifier les champs à mettre à jour
        if (isset($data['email'])) {
            // Vérifier si l'email existe déjà pour un autre utilisateur
            $existingEmail = $this->getUserByEmail($data['email']);
            if ($existingEmail && $existingEmail['id'] != $id) {
                return false;
            }
            
            $updateData['email'] = $data['email'];
        }
        
        if (isset($data['first_name'])) {
            $updateData['first_name'] = $data['first_name'];
        }
        
        if (isset($data['last_name'])) {
            $updateData['last_name'] = $data['last_name'];
        }
        
        if (isset($data['user_type'])) {
            $updateData['user_type'] = $data['user_type'];
        }
        
        // Si le mot de passe doit être mis à jour
        if (!empty($data['password'])) {
            $updateData['password'] = $this->loginAuth->hashPassword($data['password']);
        }
        
        // Si aucune donnée à mettre à jour
        if (empty($updateData)) {
            return true;
        }
        
        // Déterminer la table en fonction du profil
        $profil = $this->determinerProfil($id);
        $table = $this->getTableName($profil);
        
        // Mettre à jour l'utilisateur
        return $this->db->update($table, $updateData, 'id = :id', [':id' => $id]);
    }
    
    /**
     * Supprime un utilisateur
     * @param int $id ID de l'utilisateur à supprimer
     * @return bool Succès ou échec de la suppression
     */
    public function deleteUser($id) {
        // Vérifier si l'utilisateur existe
        $user = $this->getUserById($id);
        if (!$user) {
            return false;
        }
        
        // Déterminer la table en fonction du profil
        $profil = $this->determinerProfil($id);
        
        if (!$profil) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Supprimer les relations selon le type d'utilisateur
            switch ($profil) {
                case 'eleve':
                    // Supprimer les relations élève-classe
                    $this->db->delete('eleve_classe', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les relations élève-groupe
                    $this->db->delete('eleve_groupe', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les relations parent-élève
                    $this->db->delete('parent_eleve', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les rendus
                    $this->db->delete('rendus', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    break;
                    
                case 'professeur':
                    // Supprimer les relations professeur-classe
                    $this->db->delete('professeur_classe', 'professeur_id = :professeur_id', [':professeur_id' => $id]);
                    break;
                    
                case 'parent':
                    // Supprimer les relations parent-élève
                    $this->db->delete('parent_eleve', 'parent_id = :parent_id', [':parent_id' => $id]);
                    break;
            }
            
            // Supprimer l'utilisateur de sa table spécifique
            $table = $this->getTableName($profil);
            $this->db->delete($table, 'id = :id', [':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Erreur lors de la suppression de l'utilisateur: " . $e->getMessage());
            return false;
        }
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
     * Récupère les parents d'un élève
     * @param int $eleveId ID de l'élève
     * @return array Liste des parents
     */
    public function getParentsEleve($eleveId) {
        $sql = "SELECT u.* FROM parents u
                JOIN parent_eleve pe ON u.id = pe.parent_id
                WHERE pe.eleve_id = :eleve_id
                ORDER BY u.nom, u.prenom";
        
        return $this->db->fetchAll($sql, [':eleve_id' => $eleveId]);
    }
    
    /**
     * Récupère les enfants d'un parent
     * @param int $parentId ID du parent
     * @return array Liste des enfants
     */
    public function getEnfantsParent($parentId) {
        $sql = "SELECT u.* FROM eleves u
                JOIN parent_eleve pe ON u.id = pe.eleve_id
                WHERE pe.parent_id = :parent_id
                ORDER BY u.nom, u.prenom";
        
        return $this->db->fetchAll($sql, [':parent_id' => $parentId]);
    }
    
    /**
     * Récupère les élèves d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des élèves
     */
    public function getElevesClasse($classeId) {
        $sql = "SELECT u.* FROM eleves u
                JOIN eleve_classe ec ON u.id = ec.eleve_id
                WHERE ec.classe_id = :classe_id
                ORDER BY u.nom, u.prenom";
        
        return $this->db->fetchAll($sql, [':classe_id' => $classeId]);
    }
    
    /**
     * Détermine le profil (type) d'un utilisateur en fonction de son ID
     * @param int $id ID de l'utilisateur
     * @return string|false Type d'utilisateur ou false si non trouvé
     */
    private function determinerProfil($id) {
        $tables = [
            'eleve' => 'eleve',
            'parent' => 'parent',
            'professeur' => 'professeur',
            'vie_scolaire' => 'vie_scolaire',
            'administrateur' => 'administrateur'
        ];
        
        foreach ($tables as $table => $profil) {
            $sql = "SELECT id FROM $table WHERE id = :id LIMIT 1";
            $result = $this->db->fetch($sql, [':id' => $id]);
            if ($result) {
                return $profil;
            }
        }
        
        return false;
    }
    
    /**
     * Retourne le nom de la table correspondant au profil
     * @param string $profil Type de profil
     * @return string|null Nom de la table ou null si le profil est invalide
     */
    public function getTableName($profil) {
        $tables = [
            'eleve' => 'eleves',
            'parent' => 'parents',
            'professeur' => 'professeurs',
            'vie_scolaire' => 'vie_scolaire',
            'administrateur' => 'administrateurs'
        ];
        
        return $tables[$profil] ?? null;
    }
    
    /**
     * Adapte les données utilisateur au format attendu par le système de login
     * @param array $data Données de l'utilisateur (format de l'application)
     * @param string $profil Type de profil
     * @return array Données adaptées pour le système de login
     */
    private function adaptUserData($data, $profil) {
        $adaptedData = [];
        
        // Mappage des champs communs
        $fieldMap = [
            'first_name' => 'prenom',
            'last_name' => 'nom',
            'email' => 'mail',
            'password' => 'mot_de_passe',
            'phone' => 'telephone',
            'address' => 'adresse'
        ];
        
        foreach ($fieldMap as $appField => $loginField) {
            if (isset($data[$appField])) {
                $adaptedData[$loginField] = $data[$appField];
            }
        }
        
        // Champs spécifiques selon le profil
        switch ($profil) {
            case 'eleve':
                if (isset($data['birth_date'])) $adaptedData['date_naissance'] = $data['birth_date'];
                if (isset($data['birth_place'])) $adaptedData['lieu_naissance'] = $data['birth_place'];
                if (isset($data['class'])) $adaptedData['classe'] = $data['class'];
                break;
                
            case 'parent':
                if (isset($data['job'])) $adaptedData['metier'] = $data['job'];
                if (isset($data['is_parent'])) $adaptedData['est_parent_eleve'] = $data['is_parent'];
                break;
                
            case 'professeur':
                if (isset($data['subject'])) $adaptedData['matiere'] = $data['subject'];
                if (isset($data['is_principal'])) $adaptedData['professeur_principal'] = $data['is_principal'];
                break;
                
            case 'vie_scolaire':
                if (isset($data['is_cpe'])) $adaptedData['est_CPE'] = $data['is_cpe'];
                if (isset($data['is_nurse'])) $adaptedData['est_infirmerie'] = $data['is_nurse'];
                break;
        }
        
        return $adaptedData;
    }
}