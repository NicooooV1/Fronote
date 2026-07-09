<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/GarderieService.php';
$garderieService = new GarderieService($pdo);

$activePage = $activePage ?? 'garderie';
$extraCss = ['assets/css/garderie.css'];
$isGestionnaire = isAdmin() || isPersonnelVS();
$isAdmin = isAdmin();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'creneaux.php',     'icon' => 'fas fa-clock',           'label' => 'Créneaux'],
    ['href' => 'inscriptions.php', 'icon' => 'fas fa-user-plus',       'label' => 'Inscriptions'],
    ['href' => 'presences.php',    'icon' => 'fas fa-clipboard-check', 'label' => 'Présences', 'visible' => $isGestionnaire],
    ['href' => 'export.php',       'icon' => 'fas fa-file-export',     'label' => 'Export',    'visible' => $isGestionnaire],
]);

$pageTitle = $pageTitle ?? 'Garderie';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
