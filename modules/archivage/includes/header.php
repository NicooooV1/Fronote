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
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="archivage.php" class="sidebar-nav-item <?= $_sub === 'archivage.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-archive"></i></span><span>Archives</span></a>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus"></i></span><span>Nouvelle archive</span></a>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Archivage';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
