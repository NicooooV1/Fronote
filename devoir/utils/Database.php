<?php
/**
 * Classe de gestion des connexions à la base de données
 */
class Database {
    private static $instance = null;
    private $connection;
    
    /**
     * Constructeur privé pour éviter l'instanciation directe (Singleton)
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log l'erreur et affiche un message générique
            error_log("Erreur de connexion à la base de données: " . $e->getMessage());
            die("Impossible de se connecter à la base de données. Veuillez contacter l'administrateur.");
        }
    }
    
    /**
     * Empêche le clonage de l'instance (Singleton)
     */
    private function __clone() {}
    
    /**
     * Récupère l'instance unique de la classe Database
     * @return Database Instance de la classe
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Récupère la connexion PDO
     * @return PDO Objet PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Exécute une requête SQL avec des paramètres
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return PDOStatement Résultat de la requête
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Erreur SQL: " . $e->getMessage() . " - Requête: " . $sql);
            throw new Exception("Une erreur est survenue lors de l'exécution de la requête.");
        }
    }
    
    /**
     * Récupère une seule ligne de résultat
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array|false Résultat de la requête ou false si aucun résultat
     */
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Récupère toutes les lignes de résultat
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array Résultats de la requête
     */
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Insère des données dans une table
     * @param string $table Nom de la table
     * @param array $data Données à insérer (clé => valeur)
     * @return int|false ID de la dernière insertion ou false en cas d'échec
     */
    public function insert($table, $data) {
        try {
            $fields = array_keys($data);
            $placeholders = array_map(function($field) {
                return ':' . $field;
            }, $fields);
            
            $sql = "INSERT INTO " . $table . " (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $this->connection->prepare($sql);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("Erreur d'insertion: " . $e->getMessage() . " - Table: " . $table);
            return false;
        }
    }
    
    /**
     * Met à jour des données dans une table
     * @param string $table Nom de la table
     * @param array $data Données à mettre à jour (clé => valeur)
     * @param string $where Condition WHERE
     * @param array $whereParams Paramètres pour la condition WHERE
     * @return int Nombre de lignes affectées
     */
    public function update($table, $data, $where, $whereParams = []) {
        try {
            $fields = array_keys($data);
            $set = array_map(function($field) {
                return $field . ' = :' . $field;
            }, $fields);
            
            $sql = "UPDATE " . $table . " SET " . implode(', ', $set) . " WHERE " . $where;
            
            $stmt = $this->connection->prepare($sql);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            foreach ($whereParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Erreur de mise à jour: " . $e->getMessage() . " - Table: " . $table);
            return 0;
        }
    }
    
    /**
     * Supprime des données d'une table
     * @param string $table Nom de la table
     * @param string $where Condition WHERE
     * @param array $params Paramètres pour la condition WHERE
     * @return int Nombre de lignes affectées
     */
    public function delete($table, $where, $params = []) {
        try {
            $sql = "DELETE FROM " . $table . " WHERE " . $where;
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Erreur de suppression: " . $e->getMessage() . " - Table: " . $table);
            return 0;
        }
    }
    
    /**
     * Démarre une transaction
     * @return bool Succès ou échec
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Valide une transaction
     * @return bool Succès ou échec
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Annule une transaction
     * @return bool Succès ou échec
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
}