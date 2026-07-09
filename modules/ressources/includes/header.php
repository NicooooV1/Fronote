<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/RessourceService.php';
$resService = new RessourceService($pdo);

$activePage = $activePage ?? 'ressources';
$extraCss = ['assets/css/ressources.css'];
$isAdmin = isAdmin();
$isGestionnaire = isAdmin() || isProfesseur();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'ressources.php', 'icon' => 'fas fa-book-open', 'label' => 'Ressources'],
    ['href' => 'mes_ressources.php', 'icon' => 'fas fa-folder', 'label' => 'Mes ressources', 'visible' => $isGestionnaire],
    ['href' => 'creer.php', 'icon' => 'fas fa-plus-circle', 'label' => 'Créer', 'visible' => $isGestionnaire],
]);

$pageTitle = $pageTitle ?? 'Ressources pédagogiques';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
