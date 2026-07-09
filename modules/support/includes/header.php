<?php
/**
 * M34 – Support & Aide — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/SupportService.php';
$supportService = new SupportService(getPDO());

$activePage = 'support';
$pageTitle = $pageTitle ?? 'Support & Aide';
$extraCss = ['assets/css/support.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'aide.php',           'icon' => 'fas fa-question-circle', 'label' => 'FAQ'],
    ['href' => 'tickets.php',        'icon' => 'fas fa-ticket-alt',      'label' => 'Mes tickets'],
    ['href' => 'nouveau_ticket.php', 'icon' => 'fas fa-plus',            'label' => 'Nouveau ticket'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
