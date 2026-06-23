<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isAdmin() && !isPersonnelVS() && !isProfesseur()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/SallesMaterielService.php';
$smService = new SallesMaterielService($pdo);

$activePage = $activePage ?? 'reservations';
$extraCss = ['assets/css/salles.css'];

$pageTitle = $pageTitle ?? 'Salles & Matériels';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$isGestionnaire = isAdmin() || isPersonnelVS() || isProfesseur();
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="reservations.php" class="sidebar-nav-item <?= $_sub === 'reservations.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-calendar-check"></i></span><span>Réservations</span></a>
    <a href="materiels.php" class="sidebar-nav-item <?= $_sub === 'materiels.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-boxes-stacked"></i></span><span>Matériels</span></a>
    <a href="prets.php" class="sidebar-nav-item <?= $_sub === 'prets.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-hand-holding-box"></i></span><span>Prêts</span></a>
<?php if ($isGestionnaire): ?>
    <a href="export.php" class="sidebar-nav-item"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
