<?php
/**
 * En-tête standardisé pour le module Annonces / Sondages (M11)
 */

require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Annonces';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

$activePage = 'annonces';
$isAdmin = isAdmin();
$user_fullname = $user_fullname ?? '';
$extraCss = array_merge(['assets/css/annonces.css'], $extraCss ?? []);

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$peutPublier = $isAdmin || isVieScolaire() || isTeacher();
// detail_annonce.php et modifier_annonce.php restent rattachées à l'onglet « Annonces ».
$scriptCourant = basename($_SERVER['SCRIPT_NAME'] ?? '');
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'annonces.php',      'icon' => 'fas fa-bullhorn',    'label' => 'Annonces',
     'active' => in_array($scriptCourant, ['annonces.php', 'detail_annonce.php', 'modifier_annonce.php'], true)],
    ['href' => 'creer_annonce.php', 'icon' => 'fas fa-plus-circle', 'label' => 'Nouvelle annonce', 'visible' => $peutPublier],
    ['href' => 'gestion.php',       'icon' => 'fas fa-cog',         'label' => 'Gestion',          'visible' => $isAdmin],
]);

if (!isset($headerExtraActions)) {
ob_start();
?>
                <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                <a href="creer_annonce.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nouvelle annonce
                </a>
                <?php endif; ?>
<?php
$headerExtraActions = ob_get_clean();
}

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
