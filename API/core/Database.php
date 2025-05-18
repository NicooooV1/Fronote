<?php
/**
 * Classe de gestion de la connexion à la base de données
 */
class Database
{
    private static $instance = null;
    private $connection;
    private $queryCount = 0;
    private $queryLog = [];
    
    /**
     * Constructeur privé pour éviter l'instanciation directe
     */
    private function __construct()
    {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            Logger::error('Database connection error: ' . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Empêche le clonage de l'instance (singleton)
     */
    private function __clone() {}
    
    /**
     * Récupère l'instance unique de la base de données (singleton)
     * 
     * @return PDO Instance PDO
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance->getConnection();
    }
    
    /**
     * Récupère la connexion PDO
     * 
     * @return PDO Instance PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }
    
    /**
     * Exécute une requête SQL et retourne un statement
     * 
     * @param string $sql       Requête SQL
     * @param array  $params    Paramètres de la requête
     * @param bool   $fetchMode Mode de récupération des données
     * @return PDOStatement|bool Statement ou false en cas d'erreur
     */
    public function query($sql, $params = [], $fetchMode = PDO::FETCH_ASSOC)
    {
        try {
            $start = microtime(true);
            
            $stmt = $this->connection->prepare($sql);
            $stmt->setFetchMode($fetchMode);
            $stmt->execute($params);
            
            $this->logQuery($sql, $params, microtime(true) - $start);
            
            return $stmt;
        } catch (PDOException $e) {
            Logger::error('Database query error: ' . $e->getMessage(), [
                'sql' => $sql,
                'params' => $params
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Exécute une requête et retourne un seul résultat
     * 
     * @param string $sql    Requête SQL
     * @param array  $params Paramètres de la requête
     * @return mixed Un seul résultat ou false
     */
    public function queryOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Exécute une requête et retourne tous les résultats
     * 
     * @param string $sql    Requête SQL
     * @param array  $params Paramètres de la requête
     * @return array Résultats de la requête
     */
    public function queryAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Exécute une requête et retourne la première colonne du premier résultat
     * 
     * @param string $sql    Requête SQL
     * @param array  $params Paramètres de la requête
     * @return mixed Valeur de la première colonne ou false
     */
    public function queryScalar($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Commence une transaction
     * 
     * @return bool Succès de l'opération
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit une transaction
     * 
     * @return bool Succès de l'opération
     */
    public function commit()
    {
        return $this->connection->commit();
    }
    
    /**
     * Annule une transaction
     * 
     * @return bool Succès de l'opération
     */
    public function rollBack()
    {
        return $this->connection->rollBack();
    }
    
    /**
     * Retourne l'ID de la dernière insertion
     * 
     * @return string Dernier ID inséré
     */
    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Retourne le nombre de requêtes exécutées
     * 
     * @return int Nombre de requêtes
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }
    
    /**
     * Retourne le log des requêtes
     * 
     * @return array Log des requêtes
     */
    public function getQueryLog()
    {
        return $this->queryLog;
    }
    
    /**
     * Ajoute une requête au log
     * 
     * @param string $sql      Requête SQL
     * @param array  $params   Paramètres de la requête
     * @param float  $duration Durée d'exécution en secondes
     */
    private function logQuery($sql, $params, $duration)
    {
        $this->queryCount++;
        
        if (APP_ENV === 'development') {
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'duration' => $duration
            ];
        }
    }
}
