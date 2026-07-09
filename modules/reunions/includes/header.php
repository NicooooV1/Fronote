<?php
declare(strict_types=1);
/**
 * M14 – Réunions — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/ReunionService.php';
$reunionService = new ReunionService(getPDO());

$activePage = 'reunions';
$pageTitle = $pageTitle ?? 'Réunions';
$extraCss = ['assets/css/reunions.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$estOrganisateur = isAdmin() || isTeacher() || isVieScolaire();
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'reunions.php',      'icon' => 'fas fa-calendar-alt', 'label' => 'Réunions'],
    ['href' => 'creer.php',         'icon' => 'fas fa-plus',         'label' => 'Planifier',    'visible' => $estOrganisateur],
    ['href' => 'mes_rdv.php',       'icon' => 'fas fa-handshake',    'label' => 'Mes RDV',      'visible' => !$estOrganisateur],
    ['href' => 'convocations.php',  'icon' => 'fas fa-file-invoice', 'label' => 'Convocations'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
