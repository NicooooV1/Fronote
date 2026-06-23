<?php
/**
 * En-tête standardisé pour le module Examens (topbar layout)
 */
// Charger l'API (core.php -> bootstrap.php) AVANT tout démarrage de session :
// le bloc gardé de bootstrap.php démarre la session durcie (HttpOnly + Secure(https)
// + SameSite=Lax + nom/path par instance). Ne PAS faire de session_start() nu ici.
require_once __DIR__ . '/../../../API/core.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

$pdo = getPDO();
require_once __DIR__ . '/ExamenService.php';
$examenService = new ExamenService($pdo);

$activePage = $activePage ?? 'examens';
$extraCss = ['assets/css/examens.css'];
$isGestionnaire = isAdmin() || isVieScolaire();

// Feature flags
$_exFeatures = null;
try { $_exFeatures = app('features'); } catch (\Throwable $e) { error_log('[header.php] ' . $e->getMessage()); }
$ffAutoRoom       = $_exFeatures ? $_exFeatures->isEnabled('examens.auto_room_assignment') : true;
$ffPdfConvoc      = $_exFeatures ? $_exFeatures->isEnabled('examens.pdf_convocations') : true;
$ffSurveillance   = $_exFeatures ? $_exFeatures->isEnabled('examens.surveillance_planning') : true;

$pageTitle = $pageTitle ?? 'Examens';
$user_initials = $user_initials ?? getUserInitials();
$user_fullname = $user_fullname ?? getUserFullName();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_exManage = isAdmin() || isPersonnelVS();
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="examens.php" class="sidebar-nav-item <?= $_sub === 'examens.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-graduation-cap"></i></span><span>Examens</span></a>
<?php if (isEleve()): ?>
    <a href="mes_convocations.php" class="sidebar-nav-item <?= $_sub === 'mes_convocations.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-alt"></i></span><span>Mes convocations</span></a>
<?php endif; ?>
<?php if ($_exManage): ?>
    <a href="creer.php" class="sidebar-nav-item <?= $_sub === 'creer.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-plus-circle"></i></span><span>Créer</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
