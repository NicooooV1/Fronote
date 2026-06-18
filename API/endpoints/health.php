<?php
/**
 * Health Check Endpoint
 * GET /API/endpoints/health.php
 *
 * Returns JSON with all subsystem statuses.
 * No authentication required (for monitoring tools).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Token bearer pour obtenir le DÉTAIL. Sans token valide en production, on ne renvoie
// qu'un statut binaire (anti-profilage d'infrastructure). Jamais de message d'exception au client.
$healthToken = getenv('HEALTH_TOKEN');
$isProd      = (getenv('APP_ENV') ?: 'production') === 'production';
$authorized  = false;

if ($healthToken !== false && $healthToken !== '') {
    $authHeader    = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $providedToken = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';
    $authorized    = hash_equals($healthToken, $providedToken);
    if (!$authorized) {
        http_response_code(401);
        echo json_encode(['healthy' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$detailed = $authorized || !$isProd; // détails seulement si authentifié ou hors production

try {
    $pdo    = app('db')->getConnection();
    $health = new \API\Services\HealthCheckService($pdo, BASE_PATH);
    $result = $health->runAll();

    http_response_code($result['healthy'] ? 200 : 503);
    echo $detailed
        ? json_encode($result, JSON_PRETTY_PRINT)
        : json_encode(['healthy' => (bool) $result['healthy']]); // statut binaire uniquement
} catch (\Throwable $e) {
    error_log('[health] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode($detailed
        ? ['healthy' => false, 'error' => 'Health check failed']
        : ['healthy' => false]);
}
