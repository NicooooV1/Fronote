<?php
declare(strict_types=1);
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

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
// Source unique de navigation : les gates reprennent les gardes des pages cibles
// (gerer_cours.php / maquette.php / conflits.php : admin + vie scolaire ;
//  export.php : staff + professeur ; export_ical.php : tout rôle authentifié,
//  scopé côté page — seul le feature flag conditionne l'affichage).
$isStaff = isAdmin() || isVieScolaire();
$canExport = $isStaff || (function_exists('isTeacher') ? isTeacher() : false);
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'emploi_du_temps.php', 'icon' => 'fas fa-table',                'label' => 'Emploi du temps'],
    ['href' => 'gerer_cours.php',     'icon' => 'fas fa-pen-to-square',        'label' => 'Gérer les cours', 'visible' => $isStaff],
    ['href' => 'maquette.php',        'icon' => 'fas fa-list-check',           'label' => 'Maquette',        'visible' => $isStaff],
    ['href' => 'conflits.php',        'icon' => 'fas fa-triangle-exclamation', 'label' => 'Conflits',        'visible' => $isStaff],
    ['href' => 'export.php',          'icon' => 'fas fa-file-export',          'label' => 'Export',          'visible' => $canExport],
    ['href' => 'export_ical.php',     'icon' => 'fas fa-calendar-alt',         'label' => 'Export iCal',     'visible' => $ffIcalExport],
]);

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
