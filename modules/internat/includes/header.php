<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Données d'internes (localisation nocturne de mineurs) : réservé aux gestionnaires.
if (!isAdmin() && !isPersonnelVS()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/InternatService.php';
$internatService = new InternatService($pdo);

$activePage = $activePage ?? 'internat';
$extraCss = ['assets/css/internat.css'];
$isGestionnaire = isAdmin() || isPersonnelVS();
$isAdmin = isAdmin();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="affectations.php" class="sidebar-nav-item <?= $_sub === 'affectations.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-user-check"></i></span><span>Affectations</span></a>
<?php if ($isGestionnaire): ?>
    <a href="chambres.php" class="sidebar-nav-item <?= $_sub === 'chambres.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-bed"></i></span><span>Chambres</span></a>
    <a href="mouvements.php" class="sidebar-nav-item <?= $_sub === 'mouvements.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-right-left"></i></span><span>Mouvements</span></a>
    <a href="incidents.php" class="sidebar-nav-item <?= $_sub === 'incidents.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-triangle-exclamation"></i></span><span>Incidents</span></a>
    <a href="export.php" class="sidebar-nav-item"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Internat';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
