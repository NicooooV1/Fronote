<?php
declare(strict_types=1);
/**
 * Demande de RÉINITIALISATION de la démo publique, appelée par la plateforme Fronote
 * (bouton « Réinitialiser la démo »). 404 hors DEMO_MODE ou sans DEMO_RESET_TOKEN.
 *
 *   POST /demo/reset.php   en-tête X-Demo-Reset-Token: <jeton>   → 202 (demande déposée)
 *   GET  /demo/reset.php   même en-tête                           → 200 état (dernier reset, demande en attente)
 *
 * PHP ne fait que déposer storage/demo-reset.request : la remise à zéro elle-même (root) est
 * lancée par l'unité systemd fronote-demo-reset.path de l'hôte de démo (cf. bin/demo-ops/).
 */
// Pas de bootstrap applicatif : la demande doit passer même si la démo est en maintenance.
$root = dirname(__DIR__);
$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) { continue; }
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$truthy = in_array(strtolower($env['DEMO_MODE'] ?? ''), ['1', 'true', 'yes', 'on'], true);
$token = (string) ($env['DEMO_RESET_TOKEN'] ?? '');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$truthy || strlen($token) < 32) { http_response_code(404); echo '{"error":"not_found"}'; exit; }

$given = (string) ($_SERVER['HTTP_X_DEMO_RESET_TOKEN'] ?? '');
if (!hash_equals($token, $given)) {
    usleep(400000); // freine les essais
    http_response_code(403); echo '{"error":"forbidden"}'; exit;
}

$req = $root . '/storage/demo-reset.request';
$statusFile = $root . '/storage/demo-reset.status.json';
$status = is_file($statusFile) ? (json_decode((string) file_get_contents($statusFile), true) ?: []) : [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Une remise à zéro dure ~15 s : une demande à la fois, et pas plus d'une par minute.
    $last = strtotime((string) ($status['last_reset'] ?? '')) ?: 0;
    if (is_file($req) || (time() - $last) < 60) {
        http_response_code(429);
        echo json_encode(['queued' => is_file($req), 'message' => 'Réinitialisation déjà demandée ou faite il y a moins d\'une minute.'] + $status);
        exit;
    }
    file_put_contents($req, date('c') . "\n");
    http_response_code(202);
    echo json_encode(['queued' => true, 'message' => 'Réinitialisation demandée : la démo sera remise à zéro dans quelques secondes.'] + $status);
    exit;
}
echo json_encode(['queued' => is_file($req)] + $status);
