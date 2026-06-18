<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();

$pdo = getPDO();
require_once __DIR__ . '/GarderieService.php';
$garderieService = new GarderieService($pdo);

$activePage = $activePage ?? 'garderie';
$extraCss = ['assets/css/garderie.css'];
$isGestionnaire = isAdmin() || isPersonnelVS();
$isAdmin = isAdmin();

$pageTitle = $pageTitle ?? 'Garderie';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
