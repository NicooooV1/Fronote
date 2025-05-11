<?php
/**
 * Limitation de taux des requêtes
 */
require_once __DIR__ . '/../config/config.php';

/**
 * Vérifie et applique la limitation de taux
 * @param string $key Clé unique pour cette limite (ex: 'login', 'api_call')
 * @param int $maxAttempts Nombre maximum de tentatives
 * @param int $timeFrame Période en secondes
 * @return bool True si la requête est autorisée, False si limitée
 */
function checkRateLimit($key, $maxAttempts = 10, $timeFrame = 60) {
    global $pdo;
    
    // Identifier l'utilisateur (IP + user agent pour l'anonyme, ID utilisateur si connecté)
    $identifier = isset($_SESSION['user']) 
        ? $_SESSION['user']['id'] . '_' . $_SESSION['user']['type']
        : $_SERVER['REMOTE_ADDR'] . '_' . md5($_SERVER['HTTP_USER_AGENT'] ?? '');
    
    // Clé complète pour cette limite
    $rateLimitKey = "rate_limit:{$key}:{$identifier}";
    
    try {
        // Créer la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_key VARCHAR(255) NOT NULL,
                attempts INT NOT NULL DEFAULT 1,
                reset_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (rate_key)
            )
        ");
        
        // Nettoyer les anciennes entrées
        $pdo->exec("DELETE FROM rate_limits WHERE reset_at < NOW()");
        
        // Vérifier si cette clé existe déjà
        $stmt = $pdo->prepare("SELECT id, attempts FROM rate_limits WHERE rate_key = ?");
        $stmt->execute([$rateLimitKey]);
        $result = $stmt->fetch();
        
        // Si existe, incrémenter le compteur
        if ($result) {
            if ($result['attempts'] >= $maxAttempts) {
                // Limite atteinte
                return false;
            }
            
            // Incrémenter le compteur
            $updateStmt = $pdo->prepare("
                UPDATE rate_limits 
                SET attempts = attempts + 1 
                WHERE rate_key = ?
            ");
            $updateStmt->execute([$rateLimitKey]);
        } else {
            // Créer une nouvelle entrée
            $insertStmt = $pdo->prepare("
                INSERT INTO rate_limits (rate_key, attempts, reset_at) 
                VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ");
            $insertStmt->execute([$rateLimitKey, $timeFrame]);
        }
        
        return true;
        
    } catch (Exception $e) {
        // En cas d'erreur, logger et autoriser la requête
        error_log("Rate limiter error: " . $e->getMessage());
        return true;
    }
}

/**
 * Vérifie la limite et renvoie une réponse 429 si nécessaire
 * @param string $key Clé unique
 * @param int $maxAttempts Nombre maximum de tentatives
 * @param int $timeFrame Période en secondes
 * @param bool $isApi Si c'est une API (pour JSON)
 */
function enforceRateLimit($key, $maxAttempts = 10, $timeFrame = 60, $isApi = false) {
    if (!checkRateLimit($key, $maxAttempts, $timeFrame)) {
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: ' . $timeFrame);
        
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Trop de requêtes. Veuillez réessayer dans quelques instants.'
            ]);
        } else {
            echo '<h1>429 Too Many Requests</h1>';
            echo '<p>Vous avez effectué trop de requêtes. Veuillez réessayer dans quelques instants.</p>';
        }
        exit;
    }
}