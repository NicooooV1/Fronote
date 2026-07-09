<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/CantineService.php';
$cantineService = new CantineService($pdo);

$activePage = $activePage ?? 'cantine';
$extraCss = ['assets/css/cantine.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'menus.php',        'icon' => 'fas fa-utensils',        'label' => 'Menus'],
    ['href' => 'reservations.php', 'icon' => 'fas fa-calendar-check',  'label' => 'Réservations'],
    ['href' => 'pointage.php',     'icon' => 'fas fa-clipboard-check', 'label' => 'Pointage',     'visible' => $isGestionnaire],
    ['href' => 'statistiques.php', 'icon' => 'fas fa-chart-pie',       'label' => 'Statistiques', 'visible' => $isGestionnaire],
    ['href' => 'export.php',       'icon' => 'fas fa-file-export',     'label' => 'Export',       'visible' => $isGestionnaire],
]);

$pageTitle = $pageTitle ?? 'Cantine';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
