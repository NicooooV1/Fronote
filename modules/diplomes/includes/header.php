<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/DiplomeService.php';
$diplService = new DiplomeService($pdo);

$activePage = $activePage ?? 'diplomes';
$extraCss = ['assets/css/diplomes.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();
$isExport = isAdmin() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="diplomes.php" class="sidebar-nav-item <?= $_sub === 'diplomes.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-award"></i></span><span>Diplômes</span></a>
<?php if ($isGestionnaire): ?>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Nouveau diplôme</span></a>
<?php endif; ?>
<?php if ($isExport): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Diplômes & Relevés';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
