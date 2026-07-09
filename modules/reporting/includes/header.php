<?php
declare(strict_types=1);
/**
 * M22 – Reporting — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isAdmin() && !isTeacher() && !isVieScolaire()) {
    redirect('accueil/accueil.php');
}

require_once __DIR__ . '/ReportingService.php';
$reportService = new ReportingService(getPDO());

$activePage = 'reporting';
$pageTitle = $pageTitle ?? 'Reporting';
$extraCss = ['assets/css/reporting.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'reporting.php', 'icon' => 'fas fa-chart-line', 'label' => 'Tableau de bord'],
    ['href' => 'global.php',    'icon' => 'fas fa-globe',      'label' => 'Vue globale'],
    ['href' => 'exporter.php',  'icon' => 'fas fa-file-csv',   'label' => 'Exporter CSV'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
