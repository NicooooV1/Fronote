<?php
declare(strict_types=1);
/**
 * Mesure d'audience interne — réception d'une page vue (navigator.sendBeacon).
 * Ignorée (204) sans consentement « mesure d'audience ». Aucune réponse exploitable.
 * POST u=<url de la page> vw=<largeur d'écran> d=<durée en s, facultatif>
 */
require_once __DIR__ . '/../bootstrap.php';

use API\Services\Privacy\AudienceService;
use API\Services\Privacy\ConsentService;

http_response_code(204);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ConsentService::allows('audience')) { exit; }

// même origine (le beacon envoie Origin) — sinon on ignore
$host = strtolower((string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$src = strtolower((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
if ($src !== $host) { exit; }

// plafond par session (anti-abus) : 400 pages vues / jour
if (session_status() === PHP_SESSION_ACTIVE) {
    $k = '__aud_' . date('Ymd');
    $_SESSION[$k] = (int) ($_SESSION[$k] ?? 0) + 1;
    if ($_SESSION[$k] > 400) { exit; }
}
try {
    $consent = ConsentService::current();
    $role = function_exists('isLoggedIn') && isLoggedIn() ? (string) getUserRole() : 'anonyme';
    $d = isset($_POST['d']) && $_POST['d'] !== '' ? (int) $_POST['d'] : null;
    (new AudienceService(getPDO()))->record((string) ($_POST['u'] ?? '/'), (int) ($_POST['vw'] ?? 0), $d, $role, (string) ($consent['id'] ?? session_id()));
} catch (\Throwable $e) {
    error_log('[audience] ' . $e->getMessage());
}
