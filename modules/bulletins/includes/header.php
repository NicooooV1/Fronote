<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Bulletins';
$activePage = 'bulletins';
$extraCss = ['assets/css/bulletins.css'];

$user = getCurrentUser();
$user_role = getUserRole();
$user_fullname = getUserFullName();
$user_initials = getUserInitials();

$isStaff = isAdmin() || isVieScolaire();
// Le conseil de classe est aussi ouvert aux professeurs (même garde que conseil.php).
$isConseil = $isStaff || isTeacher();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'bulletins.php', 'icon' => 'fas fa-file-alt', 'label' => 'Bulletins'],
    ['href' => 'generer.php',   'icon' => 'fas fa-cogs',     'label' => 'Générer', 'visible' => $isStaff],
    ['href' => 'conseil.php',   'icon' => 'fas fa-user-tie', 'label' => 'Conseil de classe', 'visible' => $isConseil],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="main-content">
