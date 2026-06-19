<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();

// Accès au module Infirmerie :
//  - personnel (admin + vie scolaire / personnel médical) : gère TOUS les passages ;
//  - élève : consulte SON propre dossier (ses passages à l'infirmerie) ;
//  - parent : consulte le dossier de SES enfants.
// Le périmètre exact (gestionnaire vs élève vs parent) est appliqué dans la page.
$canManageInfirmerie = isAdmin() || isPersonnelVS() || can('medical.view');
if (!isSuperAdmin() && !$canManageInfirmerie && !isEleve() && !isParent()) {
    redirect('/accueil/accueil.php');
}

$pdo = getPDO();
require_once __DIR__ . '/InfirmerieService.php';
$infirmerieService = new InfirmerieService($pdo);

$activePage = $activePage ?? 'infirmerie';
$extraCss = ['assets/css/infirmerie.css'];

$isGestionnaire = isAdmin() || isPersonnelVS();
$isAdmin = isAdmin();

$pageTitle = $pageTitle ?? 'Infirmerie';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
