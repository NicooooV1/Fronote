<?php
/**
 * M28 – Orientation — Header
 */
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/OrientationService.php';
$orientationService = new OrientationService($pdo);

$activePage = $activePage ?? 'orientation';
$extraCss = ['assets/css/orientation.css'];

$isStaff = isAdmin() || isProfesseur() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'orientation.php', 'icon' => 'fas fa-compass',     'label' => 'Orientation'],
    ['href' => 'fiche.php',       'icon' => 'fas fa-id-card',     'label' => 'Ma fiche', 'visible' => isEleve()],
    ['href' => 'export.php',      'icon' => 'fas fa-file-export', 'label' => 'Export',   'visible' => $isStaff],
]);

$pageTitle = $pageTitle ?? 'Orientation';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
