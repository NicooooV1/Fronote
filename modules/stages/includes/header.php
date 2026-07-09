<?php
declare(strict_types=1);
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/StageService.php';
$stageService = new StageService($pdo);

$activePage = $activePage ?? 'stages';
$extraCss = ['assets/css/stages.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();
$isExport = $isGestionnaire || isProfesseur();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'stages.php', 'icon' => 'fas fa-briefcase',   'label' => 'Stages'],
    ['href' => 'creer.php',  'icon' => 'fas fa-plus',        'label' => 'Créer',  'visible' => $isGestionnaire],
    ['href' => 'export.php', 'icon' => 'fas fa-file-export', 'label' => 'Export', 'visible' => $isExport],
]);

$pageTitle = $pageTitle ?? 'Stages & Alternance';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
