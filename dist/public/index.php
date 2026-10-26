<?php
declare(strict_types=1);
/**
 * Front controller du HUB Fronote (CT122). Aiguille chaque requête :
 *   1. API de distribution  (?action=… ou chemins redeem/download/module/health/…) → server.php
 *   2. Site vitrine public   (tout le reste)                                        → vitrine.html
 * La gestion (licences, parc, démo, support) se fait sur la plateforme (platform.nicov1.fr, CT129) ;
 * l'ancienne console /console a été retirée (2026-10-26) : /console répond désormais 404.
 * Caddy proxifie tout dist.nicov1.fr → ce contrôleur ; le tri se fait ici, en un seul endroit.
 */
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

// 1) API de distribution (le trafic client/bot passe toujours par ?action= ou un chemin connu)
if ($action !== '' || preg_match('#^(redeem|download|module|health|pubkey|manifest|phone-home|proposal|admin/)#', $path)) {
    require dirname(__DIR__) . '/server.php';
    return;
}

// Ancienne console de gestion : retirée → 404 explicite (ne pas servir la vitrine sur ces URL)
if ($path === 'console' || str_starts_with($path, 'console/')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Page introuvable.";
    return;
}

// 2) Vitrine publique
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
