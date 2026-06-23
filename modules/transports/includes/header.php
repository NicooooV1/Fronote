<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Gating M32 : pages de gestion transports/internat (listes d'élèves d'autrui,
// inventaire des lignes/chambres) réservées au staff. Avant tout rendu HTML.
if (!isAdmin() && !isPersonnelVS()) {
    redirect('/accueil/accueil.php');
}

$pdo = getPDO();
require_once __DIR__ . '/TransportInternatService.php';
$tiService = new TransportInternatService($pdo);

$activePage = $activePage ?? 'lignes';
$extraCss = ['assets/css/transports.css'];

$isExport = isAdmin() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="lignes.php" class="sidebar-nav-item <?= $_sub === 'lignes.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-bus"></i></span><span>Lignes</span></a>
<?php if ($isExport): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Transports & Internat';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
