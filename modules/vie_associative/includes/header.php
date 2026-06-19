<?php
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
$pdo = getPDO();
require_once __DIR__ . '/VieAssociativeService.php';
$vieAssoService = new VieAssociativeService($pdo);

$activePage = $activePage ?? 'associations';
$extraCss = ['assets/css/vie_associative.css'];
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
