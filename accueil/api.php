<?php
declare(strict_types=1);
/**
 * Accueil — API JSON. POST action=prefs (order[], hidden[]) | action=devoir (id, fait) [élève].
 * Jeton CSRF en en-tête (vérifié sans être consommé) + contrôle d'origine.
 */
require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\Home\HomeService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (int $code, array $d): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

if (!isLoggedIn()) { $out(401, ['ok' => false, 'error' => 'Session expirée.']); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $out(405, ['ok' => false]); }
$host = preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
if ($origin === '' || strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) { $out(403, ['ok' => false, 'error' => 'Origine refusée.']); }
if (!app('csrf')->check((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { $out(419, ['ok' => false, 'error' => 'Page expirée — rechargez.']); }

$uid = (int) getUserId();
$role = (string) getUserRole();
$home = new HomeService(getPDO());
$clean = fn($a) => array_values(array_filter(array_map('strval', (array) $a), fn($k) => (bool) preg_match('/^[a-z_]{2,30}$/', $k)));

switch ((string) ($_POST['action'] ?? '')) {
    case 'prefs':
        $home->savePrefs($uid, $role, $clean($_POST['order'] ?? []), $clean($_POST['hidden'] ?? []));
        $out(200, ['ok' => true]);
    case 'devoir':
        if ($role !== 'eleve') { $out(403, ['ok' => false, 'error' => 'Réservé aux élèves.']); }
        $ok = $home->setDevoirFait($uid, (int) ($_POST['id'] ?? 0), !empty($_POST['fait']));
        $out($ok ? 200 : 404, ['ok' => $ok]);
}
$out(400, ['ok' => false, 'error' => 'Action inconnue.']);
