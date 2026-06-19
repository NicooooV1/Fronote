<?php
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

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="main-content">
    <nav class="cdt-tabs" style="display:flex;gap:8px;margin-bottom:16px;border-bottom:1px solid var(--border,#e2e8f0)">
        <a href="cahierdetextes.php" style="padding:10px 16px;text-decoration:none;color:var(--text-muted,#64748b);font-weight:600">Cahier de textes</a>
        <a href="mes_devoirs.php" style="padding:10px 16px;text-decoration:none;color:var(--primary,#0f4c81);font-weight:700;border-bottom:2px solid var(--primary,#0f4c81)">Devoirs &amp; rendus</a>
    </nav>
