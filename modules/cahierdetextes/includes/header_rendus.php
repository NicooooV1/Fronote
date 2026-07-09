<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../API/core.php';

$pageTitle = $pageTitle ?? 'Devoirs en ligne';
// Module fusionné : la soumission/correction des devoirs vit désormais sous
// cahierdetextes (le cahier de textes et les rendus sont deux onglets d'un seul module).
$activePage = 'cahierdetextes';
$extraCss = ['assets/css/devoirs.css'];

$user = getCurrentUser();
$user_role = getUserRole();
$user_fullname = getUserFullName();
$user_initials = getUserInitials();

// Feature flags
$_devFeatures = null;
try { $_devFeatures = app('features'); } catch (\Throwable $e) { error_log('[header_rendus.php] ' . $e->getMessage()); }
$ffOnlineSubmission = $_devFeatures ? $_devFeatures->isEnabled('devoirs.online_submission') : true;
$ffAutoReminders    = $_devFeatures ? $_devFeatures->isEnabled('devoirs.auto_reminders') : true;
$ffAnnotation       = $_devFeatures ? $_devFeatures->isEnabled('devoirs.annotation') : true;

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav(require __DIR__ . '/subnav_items.php');

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="main-content">
