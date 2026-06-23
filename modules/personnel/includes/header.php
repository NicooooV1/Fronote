<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isAdmin() && !isPersonnelVS()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/PersonnelService.php';
$personnelService = new PersonnelService($pdo);

$activePage = $activePage ?? 'absences';
$extraCss = ['assets/css/personnel.css'];

$pageTitle = $pageTitle ?? 'Gestion personnel';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="absences.php" class="sidebar-nav-item <?= $_sub === 'absences.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-user-clock"></i></span><span>Absences</span></a>
    <a href="remplacements.php" class="sidebar-nav-item <?= $_sub === 'remplacements.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-people-arrows"></i></span><span>Remplacements</span></a>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
