<?php
/**
 * Classe de base pour tous les modèles
 */
abstract class Model
{
    /**
     * Instance de connexion à la base de données
     * @var PDO
     */
    protected $db;
    
    /**
     * Nom de la table en base de données
     * @var string
     */
    protected $table;
    
    /**
     * Clé primaire de la table
     * @var string
     */
    protected $primaryKey = 'id';
    
    /**
     * Liste des champs qui peuvent être remplis en masse
     * @var array
     */
    protected $fillable = [];
    
    /**
     * Règles de validation pour les champs
     * @var array
     */
    protected $validationRules = [];
    
    /**
     * Constructeur
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Récupère un enregistrement par son ID
     * 
     * @param int $id ID de l'enregistrement
     * @return array|null Enregistrement trouvé ou null
     */
    public function find($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Récupère tous les enregistrements
     * 
     * @param string $orderBy Champ pour le tri
     * @param string $order   Ordre du tri (ASC ou DESC)
     * @return array Liste d'enregistrements
     */
    public function all($orderBy = null, $order = 'ASC')
    {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$order}";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Crée un nouvel enregistrement
     * 
     * @param array $data Données à insérer
     * @return int|bool ID du nouvel enregistrement ou false
     */
    public function create(array $data)
    {
        // Filtrer les données pour n'utiliser que les champs autorisés
        $data = $this->filterData($data);
        
        if (empty($data)) {
            return false;
        }
        
        // Construire la requête
        $fields = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->table} ({$fields}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        
        // Exécuter la requête
        if (!$stmt->execute(array_values($data))) {
            return false;
        }
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Met à jour un enregistrement
     * 
     * @param int   $id   ID de l'enregistrement
     * @param array $data Données à mettre à jour
     * @return bool Succès de l'opération
     */
    public function update($id, array $data)
    {
        // Filtrer les données
        $data = $this->filterData($data);
        
        if (empty($data)) {
            return false;
        }
        
        // Construire la requête
        $fields = [];
        foreach (array_keys($data) as $field) {
            $fields[] = "{$field} = ?";
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        
        // Exécuter la requête
        $params = array_merge(array_values($data), [$id]);
        return $stmt->execute($params);
    }
    
    /**
     * Supprime un enregistrement
     * 
     * @param int $id ID de l'enregistrement
     * @return bool Succès de l'opération
     */
    public function delete($id)
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
    
    /**
     * Récupère des enregistrements selon une condition
     * 
     * @param array  $conditions Conditions de filtrage
     * @param string $operator   Opérateur de liaison (AND, OR)
     * @param string $orderBy    Champ pour le tri
     * @param string $order      Ordre du tri (ASC ou DESC)
     * @param int    $limit      Limite de résultats
     * @param int    $offset     Décalage
     * @return array Liste d'enregistrements
     */
    public function where(array $conditions, $operator = 'AND', $orderBy = null, $order = 'ASC', $limit = null, $offset = null)
    {
        // Construire la clause WHERE
        $whereClause = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereClause[] = "{$field} = ?";
            $params[] = $value;
        }
        
        // Construire la requête
        $sql = "SELECT * FROM {$this->table}";
        
        if (!empty($whereClause)) {
            $sql .= " WHERE " . implode(" {$operator} ", $whereClause);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$order}";
        }
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
            
            if ($offset) {
                $sql .= " OFFSET {$offset}";
            }
        }
        
        // Exécuter la requête
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Vérifie si un enregistrement existe
     * 
     * @param array $conditions Conditions de recherche
     * @return bool True si l'enregistrement existe
     */
    public function exists(array $conditions)
    {
        // Construire la clause WHERE
        $whereClause = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereClause[] = "{$field} = ?";
            $params[] = $value;
        }
        
        // Construire la requête
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        
        if (!empty($whereClause)) {
            $sql .= " WHERE " . implode(" AND ", $whereClause);
        }
        
        // Exécuter la requête
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Compte le nombre d'enregistrements selon une condition
     * 
     * @param array $conditions Conditions de filtrage
     * @return int Nombre d'enregistrements
     */
    public function count(array $conditions = [])
    {
        // Construire la clause WHERE
        $whereClause = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereClause[] = "{$field} = ?";
            $params[] = $value;
        }
        
        // Construire la requête
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        
        if (!empty($whereClause)) {
            $sql .= " WHERE " . implode(" AND ", $whereClause);
        }
        
        // Exécuter la requête
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Filtre les données pour ne conserver que les champs autorisés
     * 
     * @param array $data Données à filtrer
     * @return array Données filtrées
     */
    protected function filterData(array $data)
    {
        if (empty($this->fillable)) {
            return $data;
        }
        
        return array_intersect_key($data, array_flip($this->fillable));
    }
    
    /**
     * Valide les données selon les règles définies
     * 
     * @param array $data Données à valider
     * @return array|bool Tableau d'erreurs ou true si tout est valide
     */
    public function validate(array $data)
    {
        if (empty($this->validationRules)) {
            return true;
        }
        
        $validator = new Validator($data);
        
        foreach ($this->validationRules as $field => $rules) {
            foreach ($rules as $rule => $options) {
                $message = is_array($options) && isset($options['message']) ? $options['message'] : null;
                $ruleOptions = is_array($options) && isset($options['value']) ? $options['value'] : $options;
                
                $validator->rule($field, $rule, $ruleOptions, $message);
            }
        }
        
        return $validator->validate() ? true : $validator->getErrors();
    }
}
