<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();

$pdo = getPDO();
require_once __DIR__ . '/PeriscolaireService.php';
$periService = new PeriscolaireService($pdo);

$activePage = $activePage ?? 'services';
$extraCss = ['assets/css/periscolaire.css'];
$isAdmin = isAdmin();

$pageTitle = $pageTitle ?? 'Périscolaire';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
