<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/BesoinService.php';
$besoinService = new BesoinService($pdo);

$activePage = $activePage ?? 'besoins';
$extraCss = ['assets/css/besoins.css'];

$isGestionnaire = isAdmin() || isPersonnelVS() || isProfesseur();
$isAdmin = isAdmin();
$pageTitle = $pageTitle ?? 'Besoins particuliers';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'besoins.php', 'icon' => 'fas fa-hands-helping', 'label' => 'Plans'],
    ['href' => 'creer.php',   'icon' => 'fas fa-plus-circle',   'label' => 'Nouveau plan', 'visible' => isAdmin() || isPersonnelVS()],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
