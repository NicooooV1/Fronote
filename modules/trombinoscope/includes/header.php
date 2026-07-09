<?php
/**
 * M15 – Trombinoscope — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Photos/identités de mineurs : réservé au personnel (admin, professeurs, vie scolaire).
// Empêche un élève/parent de moissonner les photos de tous les autres élèves.
if (!isAdmin() && !isTeacher() && !isVieScolaire()) { redirect('/accueil/accueil.php'); }

require_once __DIR__ . '/TrombinoscopeService.php';
$trombiService = new TrombinoscopeService(getPDO());

$activePage = 'trombinoscope';
$pageTitle = $pageTitle ?? 'Trombinoscope';
$extraCss = ['assets/css/trombinoscope.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$canExport = isAdmin() || isVieScolaire() || isProfesseur();
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'trombinoscope.php', 'icon' => 'fas fa-th',          'label' => 'Trombinoscope'],
    ['href' => 'export.php',        'icon' => 'fas fa-file-export', 'label' => 'Export', 'visible' => $canExport],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
