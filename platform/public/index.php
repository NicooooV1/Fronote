<?php
declare(strict_types=1);
/**
 * Fronote PLATFORM — contrôleur frontal.
 *   /api/instance/*  → API des instances (jeton d'installation) — public via Caddy
 *   tout le reste    → console opérateur (SSO + LAN/VPN, filtré par Caddy ET vérifié ici)
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (str_starts_with($path, '/api/instance/')) {
    require __DIR__ . '/../src/api.php';
    exit;
}

header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');
$op = pf_require_operator();

$routes = [
    '#^/$#'                               => 'dashboard',
    '#^/etablissements$#'                 => 'etablissements',
    '#^/etablissements/nouveau$#'         => 'etablissement_new',
    '#^/etablissements/(\d+)$#'           => 'etablissement',
    '#^/distribution$#'                   => 'distribution',
    '#^/demo$#'                           => 'demo',
    '#^/support$#'                        => 'support',
    '#^/support/(\d+)$#'                  => 'support_ticket',
    '#^/journal$#'                        => 'journal',
];
foreach ($routes as $re => $page) {
    if (preg_match($re, $path, $m)) {
        $ID = isset($m[1]) ? (int) $m[1] : 0;
        require __DIR__ . '/../src/pages/' . $page . '.php';
        exit;
    }
}
http_response_code(404);
pf_header('', 'Page introuvable');
echo '<div class="pf-empty">Cette page n\'existe pas. <a href="/">Retour au tableau de bord</a></div>';
pf_footer();
