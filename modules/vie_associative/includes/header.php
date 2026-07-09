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
require_once __DIR__ . '/VieAssociativeService.php';
$vieAssoService = new VieAssociativeService($pdo);

$activePage = $activePage ?? 'associations';
$extraCss = ['assets/css/vie_associative.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'associations.php', 'icon' => 'fas fa-hands-helping', 'label' => 'Associations'],
    ['href' => 'creer.php',        'icon' => 'fas fa-plus',          'label' => 'Nouvelle association', 'visible' => isAdmin()],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
