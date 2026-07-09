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

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'agenda.php',            'icon' => 'fas fa-calendar-alt', 'label' => 'Calendrier'],
    ['href' => 'ajouter_evenement.php', 'icon' => 'fas fa-plus-circle',  'label' => 'Ajouter',     'visible' => canManageAgendaEvents()],
    ['href' => 'export_ical.php',       'icon' => 'fas fa-file-export',  'label' => 'Export iCal', 'visible' => $ffIcalExport],
]);

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
