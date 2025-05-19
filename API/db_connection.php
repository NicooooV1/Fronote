<?php
/**
 * Gestion centralisée des connexions à la base de données
 * Ce fichier assure une connexion sécurisée et efficace à la base de données
 * avec une gestion des erreurs améliorée.
 */

// Charger la configuration si elle n'est pas déjà chargée
if (!defined('DB_HOST') && file_exists(__DIR__ . '/config/env.php')) {
    require_once __DIR__ . '/config/env.php';
}

/**
 * Classe de gestion des connexions à la base de données
 */
class DBConnection {
    private static $instance = null;
    private $pdo;
    private $inTransaction = false;
    private $reconnectAttempts = 0;
    private $maxReconnectAttempts = 3;
    
    /**
     * Construit une nouvelle instance et établit la connexion
     * @throws PDOException Si la connexion échoue
     */
    private function __construct() {
        // Vérifier que les constantes nécessaires sont définies
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
            throw new Exception("Configuration de base de données manquante");
        }
        
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Configurer le mode strict pour MySQL/MariaDB
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            
            // Activer les clés étrangères pour SQLite
            if (stripos($dsn, 'sqlite') !== false) {
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
            }
        } catch (PDOException $e) {
            // Logger l'erreur sans exposer les informations sensibles
            error_log("Erreur de connexion à la base de données: " . $e->getMessage());
            throw new PDOException("Impossible de se connecter à la base de données");
        }
    }
    
    /**
     * Empêche la duplication d'objet
     */
    private function __clone() {}
    
    /**
     * Empêche la désérialisation
     * @throws Exception Si on tente de désérialiser
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * Récupère l'instance unique de DBConnection (Singleton)
     * @return DBConnection L'instance unique
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Récupère l'objet PDO pour des utilisations spécifiques
     * @return PDO L'objet PDO
     */
    public function getPDO() {
        $this->checkConnection();
        return $this->pdo;
    }
    
    /**
     * Vérifie si la connexion est active et tente de se reconnecter en cas d'échec
     * @throws PDOException Si la reconnexion échoue après plusieurs tentatives
     */
    private function checkConnection() {
        try {
            // Vérifier la connexion avec une requête simple
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            // Si nous avons atteint le nombre maximal de tentatives, abandonner
            if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
                error_log("Échec de la reconnexion après {$this->reconnectAttempts} tentatives");
                throw new PDOException("La connexion à la base de données a été perdue et n'a pas pu être rétablie");
            }
            
            $this->reconnectAttempts++;
            error_log("Tentative de reconnexion à la base de données ({$this->reconnectAttempts}/{$this->maxReconnectAttempts})");
            
            // Recréer la connexion
            $this->__construct();
        }
    }
    
    /**
     * Exécute une requête préparée et retourne les résultats
     * @param string $sql Requête SQL
     * @param array $params Paramètres pour la requête préparée
     * @return array Tableau de résultats
     */
    public function query($sql, $params = []) {
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->handleError($e, $sql, $params);
            return [];
        }
    }
    
    /**
     * Exécute une requête préparée et retourne le premier résultat
     * @param string $sql Requête SQL
     * @param array $params Paramètres pour la requête préparée
     * @return array|null Première ligne de résultat ou null
     */
    public function queryOne($sql, $params = []) {
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            $this->handleError($e, $sql, $params);
            return null;
        }
    }
    
    /**
     * Exécute une requête qui ne retourne pas de résultat (INSERT, UPDATE, DELETE)
     * @param string $sql Requête SQL
     * @param array $params Paramètres pour la requête préparée
     * @return int Nombre de lignes affectées
     */
    public function execute($sql, $params = []) {
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->handleError($e, $sql, $params);
            return 0;
        }
    }
    
    /**
     * Insère une ligne dans une table et retourne l'ID généré
     * @param string $table Nom de la table
     * @param array $data Données à insérer (clé => valeur)
     * @return int ID généré
     */
    public function insert($table, $data) {
        $table = $this->sanitizeTableName($table);
        
        $columns = array_keys($data);
        $sanitizedColumns = array_map([$this, 'sanitizeColumnName'], $columns);
        $placeholders = array_fill(0, count($data), '?');
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $sanitizedColumns),
            implode(', ', $placeholders)
        );
        
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($data));
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->handleError($e, $sql, array_values($data));
            return 0;
        }
    }
    
    /**
     * Met à jour des lignes dans une table
     * @param string $table Nom de la table
     * @param array $data Données à mettre à jour (clé => valeur)
     * @param string $where Condition WHERE
     * @param array $whereParams Paramètres pour la condition WHERE
     * @return int Nombre de lignes affectées
     */
    public function update($table, $data, $where, $whereParams = []) {
        $table = $this->sanitizeTableName($table);
        
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = $this->sanitizeColumnName($column) . " = ?";
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $set),
            $where
        );
        
        $params = array_merge(array_values($data), $whereParams);
        
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->handleError($e, $sql, $params);
            return 0;
        }
    }
    
    /**
     * Supprime des lignes d'une table
     * @param string $table Nom de la table
     * @param string $where Condition WHERE
     * @param array $params Paramètres pour la condition WHERE
     * @return int Nombre de lignes affectées
     */
    public function delete($table, $where, $params = []) {
        $table = $this->sanitizeTableName($table);
        
        $sql = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        
        try {
            $this->checkConnection();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->handleError($e, $sql, $params);
            return 0;
        }
    }
    
    /**
     * Démarre une transaction
     * @return bool True si la transaction a été démarrée
     */
    public function beginTransaction() {
        if ($this->inTransaction) {
            return false;
        }
        
        try {
            $this->checkConnection();
            $this->inTransaction = $this->pdo->beginTransaction();
            return $this->inTransaction;
        } catch (PDOException $e) {
            error_log("Erreur lors du démarrage de la transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Valide une transaction
     * @return bool True si la transaction a été validée
     */
    public function commit() {
        if (!$this->inTransaction) {
            return false;
        }
        
        try {
            $result = $this->pdo->commit();
            $this->inTransaction = false;
            return $result;
        } catch (PDOException $e) {
            error_log("Erreur lors de la validation de la transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Annule une transaction
     * @return bool True si la transaction a été annulée
     */
    public function rollBack() {
        if (!$this->inTransaction) {
            return false;
        }
        
        try {
            $result = $this->pdo->rollBack();
            $this->inTransaction = false;
            return $result;
        } catch (PDOException $e) {
            error_log("Erreur lors de l'annulation de la transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifie si une table existe
     * @param string $tableName Nom de la table à vérifier
     * @return bool True si la table existe
     */
    public function tableExists($tableName) {
        try {
            $this->checkConnection();
            $tableName = $this->sanitizeTableName($tableName);
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->handleError($e, "SHOW TABLES LIKE ?", [$tableName]);
            return false;
        }
    }
    
    /**
     * Vérifie si une colonne existe dans une table
     * @param string $tableName Nom de la table
     * @param string $columnName Nom de la colonne
     * @return bool True si la colonne existe
     */
    public function columnExists($tableName, $columnName) {
        try {
            $this->checkConnection();
            $tableName = $this->sanitizeTableName($tableName);
            $columnName = $this->sanitizeColumnName($columnName);
            
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
            $stmt->execute([$columnName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->handleError($e, "SHOW COLUMNS FROM table LIKE ?", [$columnName]);
            return false;
        }
    }
    
    /**
     * Sanitize les noms de tables pour éviter les injections SQL
     * @param string $tableName Nom de table à nettoyer
     * @return string Nom de table nettoyé
     */
    private function sanitizeTableName($tableName) {
        // Enlever tous les caractères non alphanumériques et underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        
        // Ne pas permettre des noms commençant par un nombre
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 't' . $sanitized;
        }
        
        // Ajouter des backticks pour sécuriser les noms de tables
        return '`' . $sanitized . '`';
    }
    
    /**
     * Sanitize les noms de colonnes pour éviter les injections SQL
     * @param string $columnName Nom de colonne à nettoyer
     * @return string Nom de colonne nettoyé
     */
    private function sanitizeColumnName($columnName) {
        // Enlever tous les caractères non alphanumériques et underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);
        
        // Ne pas permettre des noms commençant par un nombre
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'c' . $sanitized;
        }
        
        // Ajouter des backticks pour sécuriser les noms de colonnes
        return '`' . $sanitized . '`';
    }
    
    /**
     * Gère les erreurs de base de données
     * @param PDOException $e Exception levée
     * @param string $sql Requête SQL
     * @param array $params Paramètres
     * @throws PDOException Exception avec message public sécurisé
     */
    private function handleError(PDOException $e, $sql, $params) {
        // Nettoyer la requête et les paramètres pour la journalisation
        $cleanSql = preg_replace('/\s+/', ' ', trim($sql));
        
        // Masquer les valeurs sensibles
        $maskedParams = array_map(function($param) {
            if (is_string($param)) {
                if (mb_strlen($param) > 20) {
                    return mb_substr($param, 0, 3) . '...' . mb_substr($param, -3);
                }
                // Masquer les données potentiellement sensibles
                if (preg_match('/pass|mot|pwd|mdp|secret|token|key/i', $param)) {
                    return '********';
                }
            }
            return $param;
        }, $params);
        
        // Journaliser l'erreur avec informations techniques pour débogage
        $errorDetails = sprintf(
            "Erreur SQL: %s\nRequête: %s\nCode: %s\nParams: %s",
            $e->getMessage(),
            $cleanSql,
            $e->getCode(),
            json_encode($maskedParams)
        );
        
        error_log($errorDetails);
        
        // Si la connexion a été perdue, tenter de se reconnecter
        if (in_array($e->getCode(), [2006, 2013, 'HY000'])) {
            if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                $this->reconnectAttempts++;
                $this->checkConnection();
                return; // Ne pas lever d'exception si la reconnexion a réussi
            }
        }
        
        // Lever une nouvelle exception avec un message public sécurisé
        if (defined('APP_ENV') && APP_ENV === 'development') {
            throw new PDOException("Erreur de base de données: " . $e->getMessage());
        } else {
            throw new PDOException("Une erreur est survenue lors de l'accès aux données. Veuillez réessayer ultérieurement.");
        }
    }
}

/**
 * Fonction d'aide pour accéder facilement à la base de données
 * @return DBConnection Instance de la connexion
 */
function db() {
    return DBConnection::getInstance();
}
