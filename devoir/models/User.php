<?php
/**
 * Modèle pour la gestion des utilisateurs
 */
class User {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
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
        $sql = "SELECT * FROM users WHERE id = :id";
        return $this->db->fetch($sql, [':id' => $id]);
    }
    
    /**
     * Récupère un utilisateur par son nom d'utilisateur
     * @param string $username Nom d'utilisateur
     * @return array|false Informations de l'utilisateur ou false si non trouvé
     */
    public function getUserByUsername($username) {
        $sql = "SELECT * FROM users WHERE username = :username";
        return $this->db->fetch($sql, [':username' => $username]);
    }
    
    /**
     * Récupère un utilisateur par son email
     * @param string $email Email de l'utilisateur
     * @return array|false Informations de l'utilisateur ou false si non trouvé
     */
    public function getUserByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = :email";
        return $this->db->fetch($sql, [':email' => $email]);
    }
    
    /**
     * Crée un nouvel utilisateur
     * @param array $data Données de l'utilisateur
     * @return int|false ID de l'utilisateur créé ou false en cas d'échec
     */
    public function createUser($data) {
        // Vérifier si le nom d'utilisateur ou l'email existe déjà
        $existingUser = $this->getUserByUsername($data['username']);
        if ($existingUser) {
            return false;
        }
        
        $existingEmail = $this->getUserByEmail($data['email']);
        if ($existingEmail) {
            return false;
        }
        
        // Hasher le mot de passe
        require_once ROOT_PATH . '/utils/Authentication.php';
        $auth = new Authentication();
        $hashedPassword = $auth->hashPassword($data['password']);
        
        // Insérer l'utilisateur
        return $this->db->insert('users', [
            'username' => $data['username'],
            'password' => $hashedPassword,
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'user_type' => $data['user_type'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Met à jour un utilisateur existant
     * @param int $id ID de l'utilisateur
     * @param array $data Nouvelles données de l'utilisateur
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateUser($id, $data) {
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
            require_once ROOT_PATH . '/utils/Authentication.php';
            $auth = new Authentication();
            $updateData['password'] = $auth->hashPassword($data['password']);
        }
        
        // Si aucune donnée à mettre à jour
        if (empty($updateData)) {
            return true;
        }
        
        // Mettre à jour l'utilisateur
        return $this->db->update('users', $updateData, 'id = :id', [':id' => $id]);
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
        
        try {
            $this->db->beginTransaction();
            
            // Supprimer les relations selon le type d'utilisateur
            switch ($user['user_type']) {
                case TYPE_ELEVE:
                    // Supprimer les relations élève-classe
                    $this->db->delete('eleve_classe', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les relations élève-groupe
                    $this->db->delete('eleve_groupe', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les relations parent-élève
                    $this->db->delete('parent_eleve', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    
                    // Supprimer les rendus
                    $this->db->delete('rendus', 'eleve_id = :eleve_id', [':eleve_id' => $id]);
                    break;
                    
                case TYPE_PROFESSEUR:
                    // Supprimer les relations professeur-classe
                    $this->db->delete('professeur_classe', 'professeur_id = :professeur_id', [':professeur_id' => $id]);
                    
                    // Note : Ne pas supprimer les chapitres, devoirs et séances créés par le professeur
                    // pour préserver l'historique. On pourrait les marquer comme "orphelins" ou les
                    // réassigner à un autre professeur.
                    break;
                    
                case TYPE_PARENT:
                    // Supprimer les relations parent-élève
                    $this->db->delete('parent_eleve', 'parent_id = :parent_id', [':parent_id' => $id]);
                    break;
            }
            
            // Supprimer les notifications
            $this->db->delete('notifications', 'destinataire_id = :destinataire_id', [':destinataire_id' => $id]);
            
            // Supprimer la configuration des notifications
            $this->db->delete('configurations_notifications', 'user_id = :user_id', [':user_id' => $id]);
            
            // Supprimer les tokens de session
            $this->db->delete('remember_tokens', 'user_id = :user_id', [':user_id' => $id]);
            $this->db->delete('password_reset_tokens', 'user_id = :user_id', [':user_id' => $id]);
            
            // Supprimer l'utilisateur
            $this->db->delete('users', 'id = :id', [':id' => $id]);
            
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
     * Récupère les groupes d'un élève
     * @param int $eleveId ID de l'élève
     * @return array Liste des groupes
     */
    public function getGroupesEleve($eleveId) {
        $sql = "SELECT g.*, c.nom as classe_nom FROM groupes g
                JOIN eleve_groupe eg ON g.id = eg.groupe_id
                JOIN classes c ON g.classe_id = c.id
                WHERE eg.eleve_id = :eleve_id
                ORDER BY g.nom";
        
        return $this->db->fetchAll($sql, [':eleve_id' => $eleveId]);
    }
    
    /**
     * Récupère les parents d'un élève
     * @param int $eleveId ID de l'élève
     * @return array Liste des parents
     */
    public function getParentsEleve($eleveId) {
        $sql = "SELECT u.* FROM users u
                JOIN parent_eleve pe ON u.id = pe.parent_id
                WHERE pe.eleve_id = :eleve_id
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [':eleve_id' => $eleveId]);
    }
    
    /**
     * Récupère les enfants d'un parent
     * @param int $parentId ID du parent
     * @return array Liste des enfants
     */
    public function getEnfantsParent($parentId) {
        $sql = "SELECT u.* FROM users u
                JOIN parent_eleve pe ON u.id = pe.eleve_id
                WHERE pe.parent_id = :parent_id
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [':parent_id' => $parentId]);
    }
    
    /**
     * Associe un élève à une classe
     * @param int $eleveId ID de l'élève
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function assignEleveToClasse($eleveId, $classeId) {
        // Vérifier si l'association existe déjà
        $sql = "SELECT * FROM eleve_classe 
                WHERE eleve_id = :eleve_id AND classe_id = :classe_id";
        
        $existing = $this->db->fetch($sql, [
            ':eleve_id' => $eleveId,
            ':classe_id' => $classeId
        ]);
        
        if ($existing) {
            return true; // L'association existe déjà
        }
        
        // Ajouter l'association
        return $this->db->insert('eleve_classe', [
            'eleve_id' => $eleveId,
            'classe_id' => $classeId
        ]);
    }
    
    /**
     * Retire un élève d'une classe
     * @param int $eleveId ID de l'élève
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function removeEleveFromClasse($eleveId, $classeId) {
        return $this->db->delete('eleve_classe', 
                              'eleve_id = :eleve_id AND classe_id = :classe_id', 
                              [':eleve_id' => $eleveId, ':classe_id' => $classeId]);
    }
    
    /**
     * Associe un professeur à une classe
     * @param int $professeurId ID du professeur
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function assignProfesseurToClasse($professeurId, $classeId) {
        // Vérifier si l'association existe déjà
        $sql = "SELECT * FROM professeur_classe 
                WHERE professeur_id = :professeur_id AND classe_id = :classe_id";
        
        $existing = $this->db->fetch($sql, [
            ':professeur_id' => $professeurId,
            ':classe_id' => $classeId
        ]);
        
        if ($existing) {
            return true; // L'association existe déjà
        }
        
        // Ajouter l'association
        return $this->db->insert('professeur_classe', [
            'professeur_id' => $professeurId,
            'classe_id' => $classeId
        ]);
    }
    
    /**
     * Retire un professeur d'une classe
     * @param int $professeurId ID du professeur
     * @param int $classeId ID de la classe
     * @return bool Succès ou échec de l'opération
     */
    public function removeProfesseurFromClasse($professeurId, $classeId) {
        return $this->db->delete('professeur_classe', 
                              'professeur_id = :professeur_id AND classe_id = :classe_id', 
                              [':professeur_id' => $professeurId, ':classe_id' => $classeId]);
    }
    
    /**
     * Associe un parent à un élève
     * @param int $parentId ID du parent
     * @param int $eleveId ID de l'élève
     * @return bool Succès ou échec de l'opération
     */
    public function assignParentToEleve($parentId, $eleveId) {
        // Vérifier si l'association existe déjà
        $sql = "SELECT * FROM parent_eleve 
                WHERE parent_id = :parent_id AND eleve_id = :eleve_id";
        
        $existing = $this->db->fetch($sql, [
            ':parent_id' => $parentId,
            ':eleve_id' => $eleveId
        ]);
        
        if ($existing) {
            return true; // L'association existe déjà
        }
        
        // Ajouter l'association
        return $this->db->insert('parent_eleve', [
            'parent_id' => $parentId,
            'eleve_id' => $eleveId
        ]);
    }
    
    /**
     * Retire un parent d'un élève
     * @param int $parentId ID du parent
     * @param int $eleveId ID de l'élève
     * @return bool Succès ou échec de l'opération
     */
    public function removeParentFromEleve($parentId, $eleveId) {
        return $this->db->delete('parent_eleve', 
                              'parent_id = :parent_id AND eleve_id = :eleve_id', 
                              [':parent_id' => $parentId, ':eleve_id' => $eleveId]);
    }
    
    /**
     * Vérifie si un utilisateur est dans une classe
     * @param int $userId ID de l'utilisateur
     * @param int $classeId ID de la classe
     * @return bool Vrai si l'utilisateur est dans la classe
     */
    public function isUserInClasse($userId, $classeId) {
        $user = $this->getUserById($userId);
        
        if (!$user) {
            return false;
        }
        
        if ($user['user_type'] === TYPE_ELEVE) {
            $sql = "SELECT * FROM eleve_classe 
                    WHERE eleve_id = :eleve_id AND classe_id = :classe_id";
            
            $result = $this->db->fetch($sql, [
                ':eleve_id' => $userId,
                ':classe_id' => $classeId
            ]);
            
            return $result !== false;
        } elseif ($user['user_type'] === TYPE_PROFESSEUR) {
            $sql = "SELECT * FROM professeur_classe 
                    WHERE professeur_id = :professeur_id AND classe_id = :classe_id";
            
            $result = $this->db->fetch($sql, [
                ':professeur_id' => $userId,
                ':classe_id' => $classeId
            ]);
            
            return $result !== false;
        }
        
        return false;
    }
    
    /**
     * Récupère les élèves d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des élèves
     */
    public function getElevesClasse($classeId) {
        $sql = "SELECT u.* FROM users u
                JOIN eleve_classe ec ON u.id = ec.eleve_id
                WHERE ec.classe_id = :classe_id AND u.user_type = 'eleve'
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [':classe_id' => $classeId]);
    }
    
    /**
     * Récupère les professeurs d'une classe
     * @param int $classeId ID de la classe
     * @return array Liste des professeurs
     */
    public function getProfesseursClasse($classeId) {
        $sql = "SELECT u.* FROM users u
                JOIN professeur_classe pc ON u.id = pc.professeur_id
                WHERE pc.classe_id = :classe_id AND u.user_type = 'professeur'
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [':classe_id' => $classeId]);
    }
    
    /**
     * Récupère les élèves d'un groupe
     * @param int $groupeId ID du groupe
     * @return array Liste des élèves
     */
    public function getElevesGroupe($groupeId) {
        $sql = "SELECT u.* FROM users u
                JOIN eleve_groupe eg ON u.id = eg.eleve_id
                WHERE eg.groupe_id = :groupe_id AND u.user_type = 'eleve'
                ORDER BY u.last_name, u.first_name";
        
        return $this->db->fetchAll($sql, [':groupe_id' => $groupeId]);
    }
    
    /**
     * Associe un élève à un groupe
     * @param int $eleveId ID de l'élève
     * @param int $groupeId ID du groupe
     * @return bool Succès ou échec de l'opération
     */
    public function assignEleveToGroupe($eleveId, $groupeId) {
        // Vérifier si l'association existe déjà
        $sql = "SELECT * FROM eleve_groupe 
                WHERE eleve_id = :eleve_id AND groupe_id = :groupe_id";
        
        $existing = $this->db->fetch($sql, [
            ':eleve_id' => $eleveId,
            ':groupe_id' => $groupeId
        ]);
        
        if ($existing) {
            return true; // L'association existe déjà
        }
        
        // Ajouter l'association
        return $this->db->insert('eleve_groupe', [
            'eleve_id' => $eleveId,
            'groupe_id' => $groupeId
        ]);
    }
    
    /**
     * Retire un élève d'un groupe
     * @param int $eleveId ID de l'élève
     * @param int $groupeId ID du groupe
     * @return bool Succès ou échec de l'opération
     */
    public function removeEleveFromGroupe($eleveId, $groupeId) {
        return $this->db->delete('eleve_groupe', 
                              'eleve_id = :eleve_id AND groupe_id = :groupe_id', 
                              [':eleve_id' => $eleveId, ':groupe_id' => $groupeId]);
    }
    
    /**
     * Met à jour la configuration des notifications d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @param array $config Configuration des notifications
     * @return bool Succès ou échec de la mise à jour
     */
    public function updateNotificationConfig($userId, $config) {
        // Vérifier si la configuration existe déjà
        $sql = "SELECT * FROM configurations_notifications WHERE user_id = :user_id";
        $existing = $this->db->fetch($sql, [':user_id' => $userId]);
        
        $data = [
            'rappels_email' => isset($config['rappels_email']) ? $config['rappels_email'] : 1,
            'rappels_app' => isset($config['rappels_app']) ? $config['rappels_app'] : 1,
            'recapitulatif_hebdo' => isset($config['recapitulatif_hebdo']) ? $config['recapitulatif_hebdo'] : 1,
            'delai_rappel' => isset($config['delai_rappel']) ? $config['delai_rappel'] : 24
        ];
        
        if ($existing) {
            // Mettre à jour la configuration existante
            return $this->db->update('configurations_notifications', 
                                   $data, 
                                   'user_id = :user_id', 
                                   [':user_id' => $userId]);
        } else {
            // Créer une nouvelle configuration
            $data['user_id'] = $userId;
            return $this->db->insert('configurations_notifications', $data);
        }
    }
    
    /**
     * Récupère la configuration des notifications d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return array Configuration des notifications
     */
    public function getNotificationConfig($userId) {
        $sql = "SELECT * FROM configurations_notifications WHERE user_id = :user_id";
        $config = $this->db->fetch($sql, [':user_id' => $userId]);
        
        if ($config) {
            return $config;
        }
        
        // Valeurs par défaut si aucune configuration n'existe
        return [
            'user_id' => $userId,
            'rappels_email' => 1,
            'rappels_app' => 1,
            'recapitulatif_hebdo' => 1,
            'delai_rappel' => 24
        ];
    }
}