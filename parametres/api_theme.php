<?php
/**
 * API endpoint: Save theme preference via AJAX
 * POST /parametres/api_theme.php
 * Body: theme=light|dark|auto  &  csrf_token=...
 */
require_once __DIR__ . '/../API/bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if ($token !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

require_once __DIR__ . '/includes/SettingsService.php';

try {
    // Validation (liste blanche) + upsert + invalidation du cache : centralisés dans SettingsService.
    $theme = (new SettingsService(getPDO()))->setTheme(getUserId(), getUserRole(), $_POST['theme'] ?? 'light');
    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (\Throwable $e) {
    error_log('[api_theme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
