<?php
/**
 * En-tête standardisé pour le module Absences
 * Utilise les templates partagés Fronote
 */

// S'assurer que l'API est chargée
require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

// S'assurer que les variables nécessaires sont définies
$pageTitle = $pageTitle ?? 'Absences';

// Récupérer les informations utilisateur via l'API
if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

// canManageAbsences() est fourni par l'API (Bridge)

// Variables pour les templates partagés
$activePage = 'absences';
$isAdmin = isAdmin();
$user_fullname = $user_fullname ?? '';
$extraCss = array_merge(['assets/css/absences.css'], $extraCss ?? []);
$extraHeadHtml = ($extraHeadHtml ?? '') . '';

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
// Liste / Retards / Statistiques pointent vers absences.php (même basename) :
// l'état actif de ces trois entrées est donc explicite, calqué sur la logique
// type/view de absences.php (vue stats réservée aux admin/vie scolaire).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$_absScript    = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_absAdminVS   = isAdmin() || isVieScolaire();
$_absOnStats   = $_absScript === 'absences.php' && ($_GET['view'] ?? '') === 'stats' && $_absAdminVS;
$_absOnRetards = $_absScript === 'absences.php' && !$_absOnStats && ($_GET['type'] ?? '') === 'retards';
$_absOnListe   = $_absScript === 'absences.php' && !$_absOnStats && !$_absOnRetards;
// Les pages de détail/traitement/soumission des justificatifs restent rattachées
// à l'onglet Justificatifs (le lien Soumettre, propre aux élèves/parents, reste
// prioritaire pour eux via la détection par basename).
$_absOnJustifs = in_array($_absScript, ['justificatifs.php', 'details_justificatif.php', 'traiter_justificatif.php', 'soumettre_justificatif.php'], true);
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'absences.php',               'icon' => 'fas fa-list',            'label' => 'Liste des absences',        'active' => $_absOnListe],
    ['href' => 'absences.php?type=retards',  'icon' => 'fas fa-clock',           'label' => 'Retards',                   'active' => $_absOnRetards],
    ['href' => 'ajouter_absence.php',        'icon' => 'fas fa-plus',            'label' => 'Signaler une absence',      'visible' => canManageAbsences()],
    ['href' => 'justificatifs.php',          'icon' => 'fas fa-file-alt',        'label' => 'Justificatifs',             'visible' => $_absAdminVS, 'active' => $_absOnJustifs],
    ['href' => 'valider_absence.php',        'icon' => 'fas fa-clipboard-check', 'label' => 'Validation',                'visible' => $_absAdminVS],
    ['href' => 'soumettre_justificatif.php', 'icon' => 'fas fa-file-upload',     'label' => 'Soumettre un justificatif', 'visible' => isStudent() || isParent()],
    ['href' => 'absences.php?view=stats',    'icon' => 'fas fa-chart-pie',       'label' => 'Statistiques',              'visible' => $_absAdminVS, 'active' => $_absOnStats],
]);

// Actions supplémentaires dans le header (sauf si déjà défini)
if (!isset($headerExtraActions)) {
ob_start();
?>
                <?php if (canManageAbsences() && $_absScript !== 'ajouter_absence.php'): ?>
                <a href="ajouter_absence.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Signaler une absence
                </a>
                <?php endif; ?>
<?php
$headerExtraActions = ob_get_clean();
} // fin if (!isset($headerExtraActions))
include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
