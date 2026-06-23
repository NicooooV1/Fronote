<?php
/**
 * M38 – Compétences — Header (topbar layout)
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/CompetenceService.php';
$compService = new CompetenceService(getPDO());

$activePage = 'competences';
$pageTitle = $pageTitle ?? 'Comp��tences';
$extraCss = ['assets/css/competences.css'];

// Feature flags
$_compFeatures = null;
try { $_compFeatures = app('features'); } catch (\Throwable $e) { error_log('[header.php] ' . $e->getMessage()); }
$ffRadarGraph  = $_compFeatures ? $_compFeatures->isEnabled('competences.radar_graph') : true;
$ffLsuExport   = $_compFeatures ? $_compFeatures->isEnabled('competences.lsu_export') : true;
$ffLinkGrades  = $_compFeatures ? $_compFeatures->isEnabled('competences.link_to_grades') : true;

if ($ffRadarGraph) {
    $extraHeadHtml = ($extraHeadHtml ?? '') . '<script src="' . $rootPrefix . 'modules/competences/assets/js/competences-radar.js" defer></script>';
}

$isTeacher = isAdmin() || isTeacher();
$isStaff   = isAdmin() || isTeacher() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="competences.php" class="sidebar-nav-item <?= $_sub === 'competences.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-clipboard-list"></i></span><span>Référentiel</span></a>
    <a href="bilan.php" class="sidebar-nav-item <?= $_sub === 'bilan.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-award"></i></span><span>Bilan</span></a>
<?php if ($isTeacher): ?>
    <a href="evaluer.php" class="sidebar-nav-item <?= $_sub === 'evaluer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-check-double"></i></span><span>Évaluer</span></a>
<?php endif; ?>
<?php if ($isStaff): ?>
    <a href="stats_classe.php" class="sidebar-nav-item <?= $_sub === 'stats_classe.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-chart-pie"></i></span><span>Statistiques</span></a>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
<?php if (isAdmin()): ?>
    <a href="referentiel_admin.php" class="sidebar-nav-item <?= $_sub === 'referentiel_admin.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-cogs"></i></span><span>Gestion</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
