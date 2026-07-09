<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

// Données d'internes (localisation nocturne de mineurs) : réservé aux gestionnaires.
if (!isAdmin() && !isPersonnelVS()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/InternatService.php';
$internatService = new InternatService($pdo);

$activePage = $activePage ?? 'internat';
$extraCss = ['assets/css/internat.css'];
$isGestionnaire = isAdmin() || isPersonnelVS();
$isAdmin = isAdmin();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'affectations.php', 'icon' => 'fas fa-user-check',          'label' => 'Affectations'],
    ['href' => 'chambres.php',     'icon' => 'fas fa-bed',                 'label' => 'Chambres',   'visible' => $isGestionnaire],
    ['href' => 'mouvements.php',   'icon' => 'fas fa-right-left',          'label' => 'Mouvements', 'visible' => $isGestionnaire],
    ['href' => 'incidents.php',    'icon' => 'fas fa-triangle-exclamation', 'label' => 'Incidents', 'visible' => $isGestionnaire],
    // Comportement historique préservé : ce lien n'avait pas de test d'état actif.
    ['href' => 'export.php',       'icon' => 'fas fa-file-export',         'label' => 'Export',     'visible' => $isGestionnaire, 'active' => false],
]);

$pageTitle = $pageTitle ?? 'Internat';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
