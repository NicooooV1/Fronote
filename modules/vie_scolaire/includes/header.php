<?php
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Vie scolaire';
$activePage = 'vie_scolaire';
$extraCss = ['assets/css/vie_scolaire.css'];

$user = getCurrentUser();
$user_role = getUserRole();
$user_fullname = getUserFullName();
$user_initials = getUserInitials();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'dashboard.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Tableau de bord'],
    ['href' => 'suivi_eleve.php',   'icon' => 'fas fa-user-graduate',  'label' => 'Suivi élève'],
    ['href' => 'stats_classes.php', 'icon' => 'fas fa-chart-bar',      'label' => 'Statistiques classes'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="main-content">
