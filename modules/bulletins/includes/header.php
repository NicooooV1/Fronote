<?php
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Bulletins';
$activePage = 'bulletins';
$extraCss = ['assets/css/bulletins.css'];

$user = getCurrentUser();
$user_role = getUserRole();
$user_fullname = getUserFullName();
$user_initials = getUserInitials();

$isStaff = isAdmin() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="bulletins.php" class="sidebar-nav-item <?= $_sub === 'bulletins.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-alt"></i></span><span>Bulletins</span></a>
<?php if ($isStaff): ?>
    <a href="generer.php" class="sidebar-nav-item <?= $_sub === 'generer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-cogs"></i></span><span>Générer</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="main-content">
