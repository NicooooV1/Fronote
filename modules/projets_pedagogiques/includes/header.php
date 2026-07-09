<?php
// Charger l'API (bootstrap.php) AVANT tout démarrage de session : le bloc gardé de
// bootstrap.php démarre la session durcie (HttpOnly + Secure(https) + SameSite=Lax
// + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));
$pdo = getPDO();
require_once __DIR__ . '/ProjetPedagogiqueService.php';
$projetService = new ProjetPedagogiqueService($pdo);

$activePage = $activePage ?? 'projets';
$extraCss = ['assets/css/projets.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$isProjetManager = isAdmin() || isProfesseur();
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'projets.php', 'icon' => 'fas fa-project-diagram', 'label' => 'Projets'],
    ['href' => 'creer.php', 'icon' => 'fas fa-plus', 'label' => 'Créer', 'visible' => $isProjetManager],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
