<?php
/**
 * En-tête commun pour le module Agenda (topbar layout)
 */
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/EventRepository.php';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
}
$user_fullname = $user_fullname ?? getUserFullName();

if (!isset($repo)) {
    $repo = new EventRepository(getPDO());
}

$pageTitle = $pageTitle ?? 'Agenda';
$activePage = 'agenda';
$isAdmin = isAdmin();
$extraCss = array_merge(['assets/css/agenda.css'], $extraCss ?? []);

// Feature flags
$_agFeatures = null;
try { $_agFeatures = app('features'); } catch (\Throwable $e) { error_log('[header.php] ' . $e->getMessage()); }
$ffRecurrence       = $_agFeatures ? $_agFeatures->isEnabled('agenda.recurrence') : true;
$ffIcalExport       = $_agFeatures ? $_agFeatures->isEnabled('agenda.ical_export') : true;
$ffConflictDetect   = $_agFeatures ? $_agFeatures->isEnabled('agenda.conflict_detection') : true;

// Actions dans le header
ob_start();
?>
                <?php if (canManageAgendaEvents()): ?>
                <a href="ajouter_evenement.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Événement
                </a>
                <?php endif; ?>
                <?php if ($ffIcalExport): ?>
                <a href="export_ical.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-calendar-alt"></i> iCal
                </a>
                <?php endif; ?>
<?php
$headerExtraActions = ob_get_clean();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="agenda.php" class="sidebar-nav-item <?= $_sub === 'agenda.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span><span>Calendrier</span></a>
<?php if (canManageAgendaEvents()): ?>
    <a href="ajouter_evenement.php" class="sidebar-nav-item <?= $_sub === 'ajouter_evenement.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Ajouter</span></a>
<?php endif; ?>
<?php if ($ffIcalExport): ?>
    <a href="export_ical.php" class="sidebar-nav-item <?= $_sub === 'export_ical.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export iCal</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>
      <div class="content-container">
        <?php if (isset($_SESSION['success_message'])): ?>
          <div class="alert-banner alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button class="alert-close">&times;</button>
          </div>
          <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
          <div class="alert-banner alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button class="alert-close">&times;</button>
          </div>
          <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
