<?php
declare(strict_types=1);
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));
$pdo = getPDO();
require_once __DIR__ . '/ParcoursEducatifService.php';
$parcoursService = new ParcoursEducatifService($pdo);

$activePage = $activePage ?? 'parcours';
$extraCss = ['assets/css/parcours.css'];

$isGestionnaire = isAdmin() || isProfesseur();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'parcours.php', 'icon' => 'fas fa-route',       'label' => 'Parcours'],
    ['href' => 'ajouter.php',  'icon' => 'fas fa-plus-circle', 'label' => 'Ajouter', 'visible' => $isGestionnaire],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
                <div class="content-container">
