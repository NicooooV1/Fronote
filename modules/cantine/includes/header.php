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
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="menus.php" class="sidebar-nav-item <?= $_sub === 'menus.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-utensils"></i></span><span>Menus</span></a>
    <a href="reservations.php" class="sidebar-nav-item <?= $_sub === 'reservations.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-calendar-check"></i></span><span>Réservations</span></a>
<?php if ($isGestionnaire): ?>
    <a href="pointage.php" class="sidebar-nav-item <?= $_sub === 'pointage.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-clipboard-check"></i></span><span>Pointage</span></a>
    <a href="statistiques.php" class="sidebar-nav-item <?= $_sub === 'statistiques.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-chart-pie"></i></span><span>Statistiques</span></a>
    <a href="export.php" class="sidebar-nav-item"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Cantine';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
