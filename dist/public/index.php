<?php
declare(strict_types=1);
/**
 * Front controller du HUB Fronote (CT122). Aiguille chaque requête :
 *   1. API de distribution  (?action=… ou chemins redeem/download/module/health/…) → server.php
 *   2. Console de gestion    (/console…)                                            → ../console/router.php
 *   3. Site vitrine public   (tout le reste)                                        → vitrine.html
 * Caddy proxifie tout dist.nicov1.fr → ce contrôleur ; le tri se fait ici, en un seul endroit.
 */
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

// 1) API de distribution (le trafic client/bot passe toujours par ?action= ou un chemin connu)
if ($action !== '' || preg_match('#^(redeem|download|module|health|pubkey|manifest|phone-home|proposal|admin/)#', $path)) {
    require dirname(__DIR__) . '/server.php';
    return;
}

// 2) Console de gestion (privée)
if ($path === 'console' || str_starts_with($path, 'console/')) {
    $router = dirname(__DIR__) . '/console/router.php';
    if (is_file($router)) { require $router; return; }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Console de gestion en cours de mise en place.";
    return;
}

// 3) Vitrine publique
$vitrine = __DIR__ . '/vitrine.html';
if (is_file($vitrine)) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile($vitrine);
    return;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Page introuvable.";
