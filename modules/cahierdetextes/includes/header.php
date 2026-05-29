<?php
/**
 * En-tête commun pour le module Cahier de Textes (topbar layout)
 */
require_once __DIR__ . '/../../../API/core.php';

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
try { $_cdtFeatures = app('features'); } catch (\Throwable $e) {}
$ffRichEditor     = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.rich_editor') : true;
$ffFileAttach     = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.file_attachments') : true;
$ffCopyToClass    = $_cdtFeatures ? $_cdtFeatures->isEnabled('cahierdetextes.copy_to_class') : true;

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
            <nav class="cdt-tabs" style="display:flex;gap:8px;margin-bottom:16px;border-bottom:1px solid var(--border,#e2e8f0)">
                <a href="cahierdetextes.php" style="padding:10px 16px;text-decoration:none;color:var(--primary,#0f4c81);font-weight:700;border-bottom:2px solid var(--primary,#0f4c81)">Cahier de textes</a>
                <a href="mes_devoirs.php" style="padding:10px 16px;text-decoration:none;color:var(--text-muted,#64748b);font-weight:600">Devoirs &amp; rendus</a>
            </nav>
