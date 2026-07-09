<?php
declare(strict_types=1);
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isAdmin() && !isPersonnelVS()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/PersonnelService.php';
$personnelService = new PersonnelService($pdo);

$activePage = $activePage ?? 'absences';
$extraCss = ['assets/css/personnel.css'];

$pageTitle = $pageTitle ?? 'Gestion personnel';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'absences.php', 'icon' => 'fas fa-user-clock', 'label' => 'Absences'],
    ['href' => 'remplacements.php', 'icon' => 'fas fa-people-arrows', 'label' => 'Remplacements'],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
