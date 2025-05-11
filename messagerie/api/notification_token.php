<?php
/**
 * Génération de jetons SSE sécurisés pour les notifications
 */
// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';

// Définir le type MIME avant toute sortie
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifie la limitation de taux
enforceRateLimit('notification_token', 20, 60, true); // 20 tokens par minute max

try {
    /**
     * Génère un jeton SSE pour les notifications
     * @param int $userId
     * @param string $userType
     * @return string
     */
    function generateNotificationSSEToken($userId, $userType) {
        $secret = 'Sk*7pM#d3F@vG9tZ!qL*6bR8'; // Changer en production
        $expiry = time() + 3600; // 1 heure
        $data = $userId . '|' . $userType . '|' . $expiry;
        $signature = hash_hmac('sha256', $data, $secret);
        
        return base64_encode($data . '|' . $signature);
    }

    // Générer un jeton SSE pour les notifications
    $token = generateNotificationSSEToken($user['id'], $user['type']);

    // Renvoyer le jeton
    echo json_encode([
        'success' => true,
        'token' => $token,
        'expires' => time() + 3600 // 1 heure
    ]);
} catch (Exception $e) {
    // Log l'erreur mais ne l'affiche pas
    if (function_exists('logException')) {
        logException($e, ['action' => 'generate_notification_token']);
    }
    echo json_encode(['success' => false, 'error' => 'Erreur lors de la génération du jeton']);
}