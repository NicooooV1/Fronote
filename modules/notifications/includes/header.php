<?php
declare(strict_types=1);
/**
 * M12 – Notifications — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/NotificationService.php';
$notifService = new NotificationService(getPDO());

$activePage = 'notifications';
$pageTitle = $pageTitle ?? 'Notifications';
$extraCss = ['assets/css/notifications.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'notifications.php', 'icon' => 'fas fa-bell',      'label' => 'Toutes'],
    ['href' => 'preferences.php',   'icon' => 'fas fa-sliders-h', 'label' => 'Préférences'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
