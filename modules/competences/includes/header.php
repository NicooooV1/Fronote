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

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
