<?php
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));
$pdo = getPDO();
require_once __DIR__ . '/ParcoursEducatifService.php';
$parcoursService = new ParcoursEducatifService($pdo);

$activePage = $activePage ?? 'parcours';
$extraCss = ['assets/css/parcours.css'];

$isGestionnaire = isAdmin() || isProfesseur();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="parcours.php" class="sidebar-nav-item <?= $_sub === 'parcours.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-route"></i></span><span>Parcours</span></a>
<?php if ($isGestionnaire): ?>
    <a href="ajouter.php" class="sidebar-nav-item <?= $_sub === 'ajouter.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Ajouter</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
