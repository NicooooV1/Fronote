<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/RessourceService.php';
$resService = new RessourceService($pdo);

$activePage = $activePage ?? 'ressources';
$extraCss = ['assets/css/ressources.css'];
$isAdmin = isAdmin();
$isGestionnaire = isAdmin() || isProfesseur();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="ressources.php" class="sidebar-nav-item <?= $_sub === 'ressources.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-book-open"></i></span><span>Ressources</span></a>
<?php if ($isGestionnaire): ?>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Créer</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Ressources pédagogiques';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
