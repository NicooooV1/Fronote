<?php
/**
 * M26 – Inscriptions — Header
 */
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/InscriptionService.php';
$inscriptionService = new InscriptionService($pdo);

$activePage = $activePage ?? 'inscriptions';
$extraCss = ['assets/css/inscriptions.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="inscriptions.php" class="sidebar-nav-item <?= $_sub === 'inscriptions.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-list-ul"></i></span><span>Inscriptions</span></a>
<?php if (isParent()): ?>
    <a href="formulaire.php" class="sidebar-nav-item <?= $_sub === 'formulaire.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-user-plus"></i></span><span>Inscrire un enfant</span></a>
<?php endif; ?>
<?php if ($isGestionnaire): ?>
    <a href="export.php" class="sidebar-nav-item"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Inscriptions';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
