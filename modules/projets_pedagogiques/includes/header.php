<?php
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
$pdo = getPDO();
require_once __DIR__ . '/ProjetPedagogiqueService.php';
$projetService = new ProjetPedagogiqueService($pdo);

$activePage = $activePage ?? 'projets';
$extraCss = ['assets/css/projets.css'];
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
