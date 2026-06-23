<?php
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));
$pdo = getPDO();
require_once __DIR__ . '/ProjetPedagogiqueService.php';
$projetService = new ProjetPedagogiqueService($pdo);

$activePage = $activePage ?? 'projets';
$extraCss = ['assets/css/projets.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$isProjetManager = isAdmin() || isProfesseur();
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="projets.php" class="sidebar-nav-item <?= $_sub === 'projets.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-project-diagram"></i></span><span>Projets</span></a>
<?php if ($isProjetManager): ?>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus"></i></span><span>Créer</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
