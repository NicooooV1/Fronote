<?php
/**
 * Génération de jetons SSE sécurisés pour les notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifie la limitation de taux
enforceRateLimit('notification_token', 20, 60, true); // 20 tokens par minute max

// Générer un jeton SSE pour les notifications
$secret = 'Sk*7pM#d3F@vG9tZ!qL*6bR8'; // Clé pour les notifications
$expiry = time() + 3600; // 1 heure
$data = $user['id'] . '|' . $user['type'] . '|' . $expiry;
$signature = hash_hmac('sha256', $data, $secret);
$token = base64_encode($data . '|' . $signature);

// Renvoyer le jeton
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'token' => $token,
    'expires' => $expiry
]);