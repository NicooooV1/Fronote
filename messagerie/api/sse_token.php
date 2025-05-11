<?php
/**
 * Génération de jetons SSE sécurisés
 */
// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';

// Spécifier le type de contenu avant toute sortie
header('Content-Type: application/json');

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifie la limitation de taux
enforceRateLimit('sse_token', 20, 60, true); // 20 tokens par minute max

// Vérifier les paramètres
if (!isset($_GET['conv_id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de conversation manquant']);
    exit;
}

$convId = (int)$_GET['conv_id'];

// Vérifier que l'utilisateur est participant à la conversation
try {
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $user['id'], $user['type']]);
    if (!$checkParticipant->fetch()) {
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
    echo json_encode([
        'success' => true,
        'token' => $token,
        'expires' => $expiry
    ]);
} catch (Exception $e) {
    // Log l'erreur mais ne l'affiche pas
    if (function_exists('logException')) {
        logException($e, ['action' => 'generate_sse_token', 'conv_id' => $convId]);
    }
    echo json_encode(['success' => false, 'error' => 'Erreur lors de la génération du jeton']);
}