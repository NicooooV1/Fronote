<?php
declare(strict_types=1);
/**
 * Consentement cookies — enregistre le choix du visiteur (avant même la connexion).
 * POST choice=accept_all|reject_all|custom|withdraw [analytics=0|1]
 * Anti-CSRF : requête de MÊME ORIGINE obligatoire (Origin/Referer = hôte courant, sans port).
 */
require_once __DIR__ . '/../bootstrap.php';

use API\Services\Privacy\ConsentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false])); }

$host = strtolower((string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$src = '';
foreach ([$_SERVER['HTTP_ORIGIN'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $h) {
    if ($h !== '') { $src = strtolower((string) parse_url($h, PHP_URL_HOST)); break; }
}
if ($src === '' || $host === '' || $src !== $host) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'cross_origin']));
}

$choice = (string) ($_POST['choice'] ?? '');
if ($choice === '') { $choice = (($_POST['level'] ?? '') === 'all') ? 'accept_all' : 'reject_all'; } // ancien format
$pdo = null;
try { $pdo = getPDO(); } catch (\Throwable $e) {}
$res = (new ConsentService($pdo))->record($choice, !empty($_POST['analytics']));
echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
