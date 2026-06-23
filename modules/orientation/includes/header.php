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
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="orientation.php" class="sidebar-nav-item <?= $_sub === 'orientation.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-compass"></i></span><span>Orientation</span></a>
<?php if (isEleve()): ?>
    <a href="fiche.php" class="sidebar-nav-item <?= $_sub === 'fiche.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-id-card"></i></span><span>Ma fiche</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Orientation';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
