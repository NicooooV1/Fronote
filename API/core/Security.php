<?php
/**
 * Fonctions de sécurité pour l'application Pronote
 */
namespace Pronote\Security;

/**
 * Nettoie une chaîne pour éviter les attaques XSS
 * @param string $data Données à nettoyer
 * @return string Données nettoyées
 */
function xss_clean($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Génère un jeton CSRF
 * @return string Jeton CSRF
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie un jeton CSRF
 * @param string $token Jeton à vérifier
 * @return bool True si le jeton est valide
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Prépare une requête SQL de manière sécurisée
 * @param object $pdo Instance PDO
 * @param string $sql Requête SQL avec placeholders
 * @param array $params Paramètres pour la requête
 * @param string $fetchMode Mode de récupération (fetch, fetchAll, rowCount, none)
 * @param int $pdoFetchMode Constante PDO pour le mode de récupération
 * @return mixed Résultat de la requête ou false en cas d'erreur
 */
function db_query($pdo, $sql, $params = [], $fetchMode = 'fetchAll', $pdoFetchMode = \PDO::FETCH_ASSOC) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        switch ($fetchMode) {
            case 'fetch':
                return $stmt->fetch($pdoFetchMode);
            case 'fetchAll':
                return $stmt->fetchAll($pdoFetchMode);
            case 'rowCount':
                return $stmt->rowCount();
            case 'none':
            default:
                return true;
        }
    } catch (\PDOException $e) {
        error_log('Erreur SQL: ' . $e->getMessage() . ' - Requête: ' . $sql);
        return false;
    }
}

/**
 * Valide une entrée utilisateur
 * @param string $type Type de validation (email, text, numeric, date, etc.)
 * @param mixed $value Valeur à valider
 * @param array $options Options de validation
 * @return mixed Valeur validée ou null si invalide
 */
function validate_input($type, $value, $options = []) {
    if ($value === null || $value === '') {
        return $options['required'] ?? false ? false : ($options['default'] ?? null);
    }
    
    switch ($type) {
        case 'email':
            $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
            return $filtered !== false ? $filtered : null;
            
        case 'text':
            $min = $options['min'] ?? 0;
            $max = $options['max'] ?? 255;
            $val = trim(strip_tags($value));
            return (strlen($val) >= $min && strlen($val) <= $max) ? $val : null;
            
        case 'numeric':
            $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
            return $filtered !== false ? $filtered : null;
            
        case 'integer':
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            return $filtered !== false ? $filtered : null;
            
        case 'date':
            $format = $options['format'] ?? 'Y-m-d';
            $date = \DateTime::createFromFormat($format, $value);
            return ($date && $date->format($format) === $value) ? $date : null;
            
        case 'datetime':
            $format = $options['format'] ?? 'Y-m-d H:i:s';
            $date = \DateTime::createFromFormat($format, $value);
            return ($date && $date->format($format) === $value) ? $date : null;
            
        default:
            return null;
    }
}

/**
 * Hache un mot de passe de manière sécurisée
 * @param string $password Mot de passe en clair
 * @return string Mot de passe haché
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, // 64 MB
        'time_cost'   => 4,     // 4 iterations
        'threads'     => 3      // 3 threads
    ]);
}

/**
 * Vérifie un mot de passe
 * @param string $password Mot de passe en clair
 * @param string $hash Hash stocké
 * @return bool True si le mot de passe correspond
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}
