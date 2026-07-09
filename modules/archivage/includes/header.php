<?php
/**
 * M35 – Archivage annuel — Header
 */
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Admin uniquement
if (!isAdmin()) {
    redirect('/accueil/accueil.php');
}

$pdo = getPDO();
require_once __DIR__ . '/ArchiveService.php';
$archiveService = new ArchiveService($pdo);

$activePage = $activePage ?? 'archivage';
$isAdmin = true;
$extraCss = ['assets/css/archivage.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'archivage.php', 'icon' => 'fas fa-archive', 'label' => 'Archives'],
    ['href' => 'creer.php',     'icon' => 'fas fa-plus',    'label' => 'Nouvelle archive'],
]);

$pageTitle = $pageTitle ?? 'Archivage';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
