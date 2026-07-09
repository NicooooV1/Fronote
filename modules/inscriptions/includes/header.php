<?php
declare(strict_types=1);
/**
 * M26 – Inscriptions — Header
 */
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/InscriptionService.php';
$inscriptionService = new InscriptionService($pdo);

$activePage = $activePage ?? 'inscriptions';
$extraCss = ['assets/css/inscriptions.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'inscriptions.php', 'icon' => 'fas fa-list-ul',      'label' => 'Inscriptions'],
    ['href' => 'formulaire.php',   'icon' => 'fas fa-user-plus',    'label' => 'Inscrire un enfant', 'visible' => isParent()],
    ['href' => 'export.php',       'icon' => 'fas fa-file-export',  'label' => 'Export',             'visible' => $isGestionnaire],
]);

$pageTitle = $pageTitle ?? 'Inscriptions';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
