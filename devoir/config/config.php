<?php
/**
 * Classe Database modifiée pour exposer l'instance PDO
 * Nécessaire pour l'intégration avec le système de login
 */
class Database {
    private static $instance;
    private $pdo;
    
    /**
     * Constructeur privé (pattern Singleton)
     */
    private function __construct() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données: ' . $e->getMessage());
        }
    }
    
    /**
     * Retourne l'instance unique de la classe Database
     * @return Database Instance de Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Retourne l'instance PDO
     * @return PDO Instance PDO
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Exécute une requête SQL et retourne plusieurs lignes
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array Résultats de la requête
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Exécute une requête SQL et retourne une seule ligne
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array|false Résultat de la requête ou false si aucun résultat
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * Insère des données dans une table
     * @param string $table Nom de la table
     * @param array $data Données à insérer
     * @return int|false ID de la ligne insérée ou false en cas d'échec
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        
        $result = $stmt->execute($data);
        return $result ? $this->pdo->lastInsertId() : false;
    }
    
    /**
     * Met à jour des données dans une table
     * @param string $table Nom de la table
     * @param array $data Données à mettre à jour
     * @param string $where Condition WHERE
     * @param array $params Paramètres de la condition WHERE
     * @return bool Succès ou échec de la mise à jour
     */
    public function update($table, $data, $where, $params = []) {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "$column = :$column";
        }
        $setClause = implode(', ', $setParts);
        
        $sql = "UPDATE $table SET $setClause WHERE $where";
        $stmt = $this->pdo->prepare($sql);
        
        $executeParams = array_merge($data, $params);
        return $stmt->execute($executeParams);
    }
    
    /**
     * Supprime des données d'une table
     * @param string $table Nom de la table
     * @param string $where Condition WHERE
     * @param array $params Paramètres de la condition WHERE
     * @return bool Succès ou échec de la suppression
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Exécute une requête SQL
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return PDOStatement Résultat de la requête
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Démarre une transaction
     */
    public function beginTransaction() {
        $this->pdo->beginTransaction();
    }
    
    /**
     * Valide une transaction
     */
    public function commit() {
        $this->pdo->commit();
    }
    
    /**
     * Annule une transaction
     */
    public function rollback() {
        $this->pdo->rollBack();
    }
}