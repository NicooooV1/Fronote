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
 * @param string $sql Requête SQL avec placeholders
 * @param array $params Paramètres à lier
 * @param PDO $pdo Instance PDO
 * @return PDOStatement Requête préparée
 */
function prepare_and_execute($sql, $params = [], $pdo = null) {
    if (!$pdo) {
        global $pdo;
        if (!$pdo) {
            throw new \Exception("No PDO connection available");
        }
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
