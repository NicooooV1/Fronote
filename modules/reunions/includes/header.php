<?php
declare(strict_types=1);
/**
 * Réunions — en-tête commun (refonte 4.6).
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/ReunionService.php';
require_once __DIR__ . '/ReunionHub.php';
$reunionService = new ReunionService(getPDO());
$hub = new ReunionHub(getPDO());

$activePage = 'reunions';
$pageTitle = $pageTitle ?? 'Réunions';
$pageSubtitle = $pageSubtitle ?? 'Rencontres, conseils, commissions et entretiens : tout ce qui n\'est pas un cours.';
$extraCss = ['assets/css/reunions.css'];
$extraJs = array_merge($extraJs ?? [], ['assets/js/reunions.js']);

$estOrganisateur = ReunionHub::canOrganise();
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'reunions.php',     'icon' => 'fas fa-stream',         'label' => 'Toutes les réunions'],
    ['href' => 'convocations.php', 'icon' => 'fas fa-envelope-open-text', 'label' => 'Mes convocations'],
    ['href' => 'planifier.php',    'icon' => 'fas fa-plus',           'label' => 'Planifier', 'visible' => $estOrganisateur],
]);
$headerExtraActions = $headerExtraActions ?? ($estOrganisateur && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'planifier.php'
    ? '<a href="planifier.php" class="btn btn-primary"><i class="fas fa-plus"></i> Planifier une réunion</a>' : '');

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
