<?php
/**
 * Génération de jetons SSE sécurisés
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
enforceRateLimit('sse_token', 20, 60, true); // 20 tokens par minute max

// Vérifier les paramètres
if (!isset($_GET['conv_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'ID de conversation manquant']);
    exit;
}

$convId = (int)$_GET['conv_id'];

// Vérifier que l'utilisateur est participant à la conversation
$checkParticipant = $pdo->prepare("
    SELECT id FROM conversation_participants 
    WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
");
$checkParticipant->execute([$convId, $user['id'], $user['type']]);
if (!$checkParticipant->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé à cette conversation']);
    exit;
}

// Générer un jeton SSE
$secret = 'BkTW#9f7@L!zP3vQ#Rx*8jN2';
$expiry = time() + 3600; // 1 heure
$data = $convId . '|' . $user['id'] . '|' . $user['type'] . '|' . $expiry;
$signature = hash_hmac('sha256', $data, $secret);
$token = base64_encode($data . '|' . $signature);

// Renvoyer le jeton
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'token' => $token,
    'expires' => $expiry
]);