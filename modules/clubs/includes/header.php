<?php
declare(strict_types=1);
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/ClubService.php';
$clubService = new ClubService($pdo);

$activePage = $activePage ?? 'clubs';
$extraCss = ['assets/css/clubs.css'];

$isGestionnaire = isAdmin() || isPersonnelVS() || isProfesseur();
$isExport = isAdmin() || isVieScolaire();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'clubs.php',     'icon' => 'fas fa-users',       'label' => 'Clubs'],
    ['href' => 'mes_clubs.php', 'icon' => 'fas fa-id-card',     'label' => 'Mes clubs',     'visible' => isEleve()],
    ['href' => 'creer.php',     'icon' => 'fas fa-plus-circle', 'label' => 'Créer un club', 'visible' => $isGestionnaire],
    // 'active' => false : le bloc d'origine ne marquait jamais ce lien actif.
    ['href' => 'export.php',    'icon' => 'fas fa-file-export', 'label' => 'Export',        'visible' => $isExport, 'active' => false],
]);

$pageTitle = $pageTitle ?? 'Clubs';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
