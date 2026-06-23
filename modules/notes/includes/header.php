<?php
/**
 * En-tête standardisé pour le module Notes
 * Utilise les templates partagés Fronote (topbar layout)
 */

// S'assurer que l'API est chargée
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

// S'assurer que les variables nécessaires sont définies
$pageTitle = $pageTitle ?? 'Notes';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}
if (!isset($user_role)) {
    $user_role = getUserRole();
}

$user_fullname = $user_fullname ?? '';
$user_initials = $user_initials ?? '';

// Variables pour les templates partagés
$activePage = 'notes';
$isAdmin = ($user_role ?? '') === 'administrateur';
$extraCss = $extraCss ?? ['assets/css/notes.css'];

// Rôles pour la navigation secondaire.
$isStaffNotes = isAdmin() || isVieScolaire() || isTeacher();

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
$_sub = basename($_SERVER['PHP_SELF'] ?? '');
ob_start(); ?>
<div class="sidebar-nav">
    <a href="notes.php" class="sidebar-nav-item <?= $_sub === 'notes.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-list-ol"></i></span><span>Notes</span></a>
<?php if ($isStaffNotes): ?>
    <a href="export.php" class="sidebar-nav-item <?= $_sub === 'export.php' ? 'active' : '' ?>"><span class="sidebar-nav-icon"><i class="fas fa-file-export"></i></span><span>Export</span></a>
<?php endif; ?>
</div>
<?php $sidebarExtraContent = ob_get_clean();

// Inclure les templates partagés
include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
