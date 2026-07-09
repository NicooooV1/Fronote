<?php
declare(strict_types=1);
/**
 * Endpoint: save cookie consent level.
 * POST /API/endpoints/cookie_consent.php
 * Body: level=all|essential
 */
require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Anti-CSRF : l'endpoint est volontairement non authentifié (le consentement
// précède la session, donc pas de token CSRF disponible). On exige à la place que
// la requête soit de MÊME ORIGINE : l'en-tête Origin/Referer doit correspondre à
// l'hôte courant (les navigateurs envoient Origin sur les POST cross-site).
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$srcHost = '';
foreach ([$_SERVER['HTTP_ORIGIN'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $h) {
    if ($h !== '') { $srcHost = strtolower((string) parse_url($h, PHP_URL_HOST)); break; }
}
if ($srcHost !== '' && $host !== '' && $srcHost !== $host) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => false, 'error' => 'cross_origin']));
}

$level = $_POST['level'] ?? 'essential';
if (!in_array($level, ['all', 'essential'], true)) {
    $level = 'essential';
}

$cc = app('client_cache');
// Store consent for 365 days
$cc->set('cookie_consent', $level, 86400 * 365);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
echo json_encode(['ok' => true, 'level' => $level]);
