<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/BesoinService.php';
$besoinService = new BesoinService($pdo);

$activePage = $activePage ?? 'besoins';
$extraCss = ['assets/css/besoins.css'];

$isGestionnaire = isAdmin() || isPersonnelVS() || isProfesseur();
$isAdmin = isAdmin();
$pageTitle = $pageTitle ?? 'Besoins particuliers';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="besoins.php" class="sidebar-nav-item <?= $_sub === 'besoins.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-hands-helping"></i></span><span>Plans</span></a>
<?php if (isAdmin() || isPersonnelVS()): ?>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Nouveau plan</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
