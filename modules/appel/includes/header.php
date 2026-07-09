<?php
/**
 * En-tête standardisé pour le module Appel / Présence (M04)
 * Utilise les templates partagés Fronote
 */

require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Appel';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

$activePage = 'appel';
$isAdmin = isAdmin();
$user_fullname = $user_fullname ?? '';
$extraCss = array_merge(['assets/css/appel.css'], $extraCss ?? []);

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'appel.php',      'icon' => 'fas fa-clipboard-check', 'label' => "Faire l'appel"],
    ['href' => 'historique.php', 'icon' => 'fas fa-history',         'label' => 'Historique', 'visible' => isAdmin() || isVieScolaire()],
]);

if (!isset($headerExtraActions)) {
ob_start();
?>
                <?php if (isTeacher()): ?>
                <a href="appel.php" class="btn btn-primary">
                    <i class="fas fa-clipboard-check"></i> Nouvel appel
                </a>
                <?php endif; ?>
<?php
$headerExtraActions = ob_get_clean();
}

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
