<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/PeriscolaireService.php';
$periService = new PeriscolaireService($pdo);

$activePage = $activePage ?? 'services';
$extraCss = ['assets/css/periscolaire.css'];
$isAdmin = isAdmin();
$isGestionnaire = isAdmin() || isPersonnelVS();
$isExport = isAdmin() || isVieScolaire();
$isParentEleve = isParent() || isEleve();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="services.php" class="sidebar-nav-item <?= $_sub === 'services.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-list"></i></span><span>Services</span></a>
<?php if ($isParentEleve): ?>
    <a href="mes_inscriptions.php" class="sidebar-nav-item <?= $_sub === 'mes_inscriptions.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-clipboard-list"></i></span><span>Mes inscriptions</span></a>
<?php endif; ?>
<?php if ($isGestionnaire): ?>
    <a href="presences.php" class="sidebar-nav-item <?= $_sub === 'presences.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-clipboard-check"></i></span><span>Présences</span></a>
<?php endif; ?>
<?php if ($isExport): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Périscolaire';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
