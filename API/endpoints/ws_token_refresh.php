<?php
/**
 * WebSocket Token Refresh Endpoint
 * GET /API/endpoints/ws_token_refresh.php
 *
 * Returns a fresh JWT for the authenticated user.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Rate limiting : ce endpoint émet des JWT ; on plafonne les rafales par
// utilisateur+IP. Fail-open si le limiteur est indisponible (cohérent avec le
// reste de l'app) pour ne jamais bloquer un refresh légitime sur incident infra.
try {
    $rl = app('rate_limiter');
    $rlKey = 'ws_token_refresh.' . (int) $_SESSION['user_id'];
    $rl->setMaxAttempts(30)->setDecayMinutes(1);
    if ($rl->tooManyAttempts($rlKey)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }
    $rl->hit($rlKey);
} catch (\Throwable $e) {
    error_log('ws_token_refresh rate limiter unavailable: ' . $e->getMessage());
}

try {
    $token = \API\Core\WebSocket::generateToken(
        (int) $_SESSION['user_id'],
        $_SESSION['user_type'] ?? $_SESSION['role'] ?? ''
    );

    if (!$token) {
        http_response_code(500);
        echo json_encode(['error' => 'Token generation failed']);
        exit;
    }

    echo json_encode(['token' => $token]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
