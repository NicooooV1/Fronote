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
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'examens.php',          'icon' => 'fas fa-graduation-cap', 'label' => 'Examens'],
    ['href' => 'mes_convocations.php', 'icon' => 'fas fa-file-alt',       'label' => 'Mes convocations', 'visible' => isEleve()],
    ['href' => 'creer.php',            'icon' => 'fas fa-plus-circle',    'label' => 'Créer',            'visible' => $_exManage],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
