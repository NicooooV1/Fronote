<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/Legacy/Bridge.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

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

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="infirmerie.php" class="sidebar-nav-item <?= $_sub === 'infirmerie.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-house-medical"></i></span><span>Passages</span></a>
<?php if ($isGestionnaire): ?>
    <a href="passage.php" class="sidebar-nav-item <?= $_sub === 'passage.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-notes-medical"></i></span><span>Nouveau passage</span></a>
    <a href="fiches.php" class="sidebar-nav-item <?= $_sub === 'fiches.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-folder-open"></i></span><span>Fiches santé</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

$pageTitle = $pageTitle ?? 'Infirmerie';
require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
?>
            <div class="content-container">
