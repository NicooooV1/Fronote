<?php
declare(strict_types=1);
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
    $extraHeadHtml = ($extraHeadHtml ?? '') . '<script src="' . $rootPrefix . 'modules/competences/assets/js/competences-radar.js" defer nonce="' . csp_nonce() . '"></script>';
}

$isTeacher = isAdmin() || isTeacher();
$isStaff   = isAdmin() || isTeacher() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'competences.php',       'icon' => 'fas fa-clipboard-list', 'label' => 'Référentiel'],
    ['href' => 'bilan.php',             'icon' => 'fas fa-award',          'label' => 'Bilan'],
    ['href' => 'evaluer.php',           'icon' => 'fas fa-check-double',   'label' => 'Évaluer',      'visible' => $isTeacher],
    ['href' => 'stats_classe.php',      'icon' => 'fas fa-chart-pie',      'label' => 'Statistiques', 'visible' => $isStaff],
    ['href' => 'export.php',            'icon' => 'fas fa-file-export',    'label' => 'Export',       'visible' => $isStaff],
    ['href' => 'referentiel_admin.php', 'icon' => 'fas fa-cogs',           'label' => 'Gestion',      'visible' => isAdmin()],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
