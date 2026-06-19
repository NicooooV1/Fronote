<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

if (!isAdmin() && !isPersonnelVS() && !isProfesseur()) { redirect('/accueil/accueil.php'); }

$pdo = getPDO();
require_once __DIR__ . '/SallesMaterielService.php';
$smService = new SallesMaterielService($pdo);

$activePage = $activePage ?? 'reservations';
$extraCss = ['assets/css/salles.css'];

$pageTitle = $pageTitle ?? 'Salles & Matériels';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
