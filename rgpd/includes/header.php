<?php
// Charger l'API (Bridge -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../API/Legacy/Bridge.php';
requireAuth();

$pdo = getPDO();
require_once __DIR__ . '/AuditRgpdService.php';
$rgpdService = new AuditRgpdService($pdo);

$activePage = $activePage ?? 'audit';
// Chemin RELATIF au dossier de la page courante (toutes les pages RGPD sont à
// la profondeur 1, dans /rgpd/), comme le font les modules (qui passent
// 'assets/css/x.css' depuis /modules/x/). shared_header.php émet $extraCss sans
// préfixer $rootPrefix : on doit donc donner un chemin résolvable depuis /rgpd/.
$extraCss = ['assets/css/rgpd.css'];

$pageTitle = $pageTitle ?? 'RGPD & Audit';
require_once __DIR__ . '/../../templates/shared_header.php';
require_once __DIR__ . '/../../templates/shared_topbar.php';
?>
            <div class="content-container">
