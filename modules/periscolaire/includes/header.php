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
require_once __DIR__ . '/PeriscolaireService.php';
$periService = new PeriscolaireService($pdo);

$activePage = $activePage ?? 'services';
$extraCss = ['assets/css/periscolaire.css'];
$isAdmin = isAdmin();
$isGestionnaire = isAdmin() || isVieScolaire();
$isParentEleve = isParent() || isEleve();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'services.php',         'icon' => 'fas fa-list',            'label' => 'Services'],
    ['href' => 'mes_inscriptions.php', 'icon' => 'fas fa-clipboard-list',  'label' => 'Mes inscriptions', 'visible' => $isParentEleve],
    ['href' => 'presences.php',        'icon' => 'fas fa-clipboard-check', 'label' => 'Présences',        'visible' => $isGestionnaire],
    ['href' => 'export.php',           'icon' => 'fas fa-file-export',     'label' => 'Export',           'visible' => $isGestionnaire],
]);

$pageTitle = $pageTitle ?? 'Périscolaire';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
