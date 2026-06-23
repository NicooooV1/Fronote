<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/BibliothequeService.php';
$biblioService = new BibliothequeService($pdo);

$activePage = $activePage ?? 'catalogue';
$extraCss = ['assets/css/bibliotheque.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="catalogue.php" class="sidebar-nav-item <?= $_sub === 'catalogue.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-book"></i></span><span>Catalogue</span></a>
    <a href="emprunts.php" class="sidebar-nav-item <?= $_sub === 'emprunts.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-book-reader"></i></span><span>Emprunts</span></a>
<?php if ($isGestionnaire): ?>
    <a href="ajouter.php" class="sidebar-nav-item <?= $_sub === 'ajouter.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus"></i></span><span>Ajouter</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Bibliothèque';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
