<?php
/**
 * M45 – Anti-harcèlement — Header
 */
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/SignalementService.php';
$signalementService = new SignalementService($pdo);

$activePage = $activePage ?? 'signalements';
$extraCss = ['assets/css/signalements.css'];

$pageTitle = $pageTitle ?? 'Signalements';
$isAdmin = isAdmin();
$isStaff = isAdmin() || isPersonnelVS();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
<?php if ($isStaff): ?>
    <a href="signalements.php" class="sidebar-nav-item <?= $_sub === 'signalements.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-list-ul"></i></span><span>Signalements</span></a>
<?php endif; ?>
    <a href="mes_signalements.php" class="sidebar-nav-item <?= $_sub === 'mes_signalements.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-folder"></i></span><span>Mes signalements</span></a>
    <a href="signaler.php" class="sidebar-nav-item <?= $_sub === 'signaler.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Signaler</span></a>
<?php if ($isStaff): ?>
    <a href="export.php" class="sidebar-nav-item"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
