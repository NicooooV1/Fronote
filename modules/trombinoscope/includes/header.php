<?php
/**
 * M15 – Trombinoscope — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Photos/identités de mineurs : réservé au personnel (admin, professeurs, vie scolaire).
// Empêche un élève/parent de moissonner les photos de tous les autres élèves.
if (!isAdmin() && !isTeacher() && !isVieScolaire()) { redirect('/accueil/accueil.php'); }

require_once __DIR__ . '/TrombinoscopeService.php';
$trombiService = new TrombinoscopeService(getPDO());

$activePage = 'trombinoscope';
$pageTitle = $pageTitle ?? 'Trombinoscope';
$extraCss = ['assets/css/trombinoscope.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
$canExport = isAdmin() || isVieScolaire() || isProfesseur();
ob_start(); ?>
<div class="sidebar-nav">
    <a href="trombinoscope.php" class="sidebar-nav-item <?= $_sub === 'trombinoscope.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-th"></i></span><span>Trombinoscope</span></a>
<?php if ($canExport): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
