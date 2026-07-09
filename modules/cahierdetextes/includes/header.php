<?php
declare(strict_types=1);
/**
 * En-tête commun pour le module Cahier de Textes (topbar layout)
 */
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
}
$user_fullname = $user_fullname ?? getUserFullName();

$pageTitle  = $pageTitle ?? 'Cahier de Textes';
$activePage = 'cahierdetextes';
$isAdmin    = isAdmin();
$extraCss = $extraCss ?? ['assets/css/cahierdetextes.css'];
$extraJs  = $extraJs  ?? ['assets/js/cahierdetextes.js'];

// Feature flags
$_cdtFeatures = null;
try { $_cdtFeatures = app('features'); } catch (\Throwable $e) { error_log('[header.php] ' . $e->getMessage()); }
$ffRichEditor     = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.rich_editor') : true;
$ffFileAttach     = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.file_attachments') : true;
$ffCopyToClass    = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.copy_to_class') : true;

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav(require __DIR__ . '/subnav_items.php');

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
