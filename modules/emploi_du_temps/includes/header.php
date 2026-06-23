<?php
/**
 * En-tête standardisé pour le module Emploi du Temps (topbar layout)
 */
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Emploi du temps';
$currentPage = $currentPage ?? '';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

$activePage = 'emploi_du_temps';
$isAdmin = isAdmin();
$user_fullname = $user_fullname ?? '';
$extraCss = array_merge(['assets/css/emploi_du_temps.css'], $extraCss ?? []);

// Feature flags
$_edtFeatures = null;
try { $_edtFeatures = app('features'); } catch (\Throwable $e) { error_log('[header.php] ' . $e->getMessage()); }
$ffDragDrop         = $_edtFeatures ? $_edtFeatures->isEnabled('emploi_du_temps.drag_drop_editor') : true;
$ffConflictDetect   = $_edtFeatures ? $_edtFeatures->isEnabled('emploi_du_temps.conflict_detection') : true;
$ffIcalExport       = $_edtFeatures ? $_edtFeatures->isEnabled('emploi_du_temps.ical_export') : true;
$ffReplacements     = $_edtFeatures ? $_edtFeatures->isEnabled('emploi_du_temps.replacements') : true;

if (!isset($headerExtraActions)) {
    ob_start();
    if (isAdmin() && $currentPage !== 'gerer') {
        echo '<a href="gerer_cours.php" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter un cours</a>';
    }
    if ((isAdmin() || isVieScolaire()) && $currentPage !== 'maquette') {
        echo ' <a href="maquette.php" class="btn btn-secondary btn-sm"><i class="fas fa-list-check"></i> Maquette</a>';
    }
    if ($ffIcalExport) {
        echo ' <a href="export_ical.php" class="btn btn-secondary btn-sm"><i class="fas fa-calendar-alt"></i> iCal</a>';
    }
    $headerExtraActions = ob_get_clean();
}

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$isStaff = isAdmin() || isVieScolaire();
$canExport = $isStaff || (function_exists('isTeacher') ? isTeacher() : false);
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="emploi_du_temps.php" class="sidebar-nav-item <?= $_sub === 'emploi_du_temps.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-table"></i></span><span>Emploi du temps</span></a>
<?php if ($isStaff): ?>
    <a href="gerer_cours.php" class="sidebar-nav-item <?= $_sub === 'gerer_cours.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-pen-to-square"></i></span><span>Gérer les cours</span></a>
    <a href="maquette.php" class="sidebar-nav-item <?= $_sub === 'maquette.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-list-check"></i></span><span>Maquette</span></a>
    <a href="conflits.php" class="sidebar-nav-item <?= $_sub === 'conflits.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-triangle-exclamation"></i></span><span>Conflits</span></a>
<?php endif; ?>
<?php if ($canExport): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
