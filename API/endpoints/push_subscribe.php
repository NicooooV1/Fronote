<?php
/**
 * API endpoint: Push notification subscription management
 * POST /API/endpoints/push_subscribe.php
 * Body (JSON): { action: "subscribe"|"unsubscribe", endpoint, p256dh, auth, csrf_token }
 */
require_once __DIR__ . '/../bootstrap.php';
requireAuth();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Rate limiting : limite les rafales d'abonnement/désabonnement push par
// utilisateur+IP. Fail-open si le limiteur est indisponible.
try {
    $rl = app('rate_limiter');
    $rlKey = 'push_subscribe.' . (int) ($_SESSION['user_id'] ?? 0);
    $rl->setMaxAttempts(30)->setDecayMinutes(1);
    if ($rl->tooManyAttempts($rlKey)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }
    $rl->hit($rlKey);
} catch (\Throwable $e) {
    error_log('push_subscribe rate limiter unavailable: ' . $e->getMessage());
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$token = $input['csrf_token'] ?? '';
if (!app('csrf')->validate($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}
app('csrf')->emitNextToken();

$pushService = new \API\Services\WebPushService(getPDO());
$action = $input['action'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userType = $_SESSION['user_type'] ?? '';

switch ($action) {
    case 'subscribe':
        $endpoint = $input['endpoint'] ?? '';
        $p256dh = $input['p256dh'] ?? '';
        $auth = $input['auth'] ?? '';

        if (empty($endpoint)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing endpoint']);
            break;
        }

        $ok = $pushService->subscribe($userId, $userType, $endpoint, $p256dh, $auth);
        echo json_encode(['success' => $ok]);
        break;

    case 'unsubscribe':
        $endpoint = $input['endpoint'] ?? '';
        $ok = $pushService->unsubscribe($endpoint);
        echo json_encode(['success' => $ok]);
        break;

    case 'vapid_key':
        echo json_encode(['key' => $pushService->getPublicKey()]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
