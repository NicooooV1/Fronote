<?php
declare(strict_types=1);
/**
 * En-tête standardisé pour le module Discipline (M06)
 */

require_once __DIR__ . '/../../../API/core.php';
// Auth + gate d'autorisation par module (avant tout rendu).
requireAuth();
enforceModuleAccess(basename(dirname(__DIR__)));

$pageTitle = $pageTitle ?? 'Discipline';

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

$activePage = 'discipline';
$isAdmin = isAdmin();
$user_fullname = $user_fullname ?? '';
$extraCss = array_merge(['assets/css/discipline.css'], $extraCss ?? []);

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
// Les pages de détail/traitement d'incident et la fiche élève restent rattachées
// à l'onglet Incidents (état actif explicite, elles n'ont pas d'entrée propre).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$_discOnIncidents = in_array(
    basename($_SERVER['SCRIPT_NAME'] ?? ''),
    ['incidents.php', 'detail_incident.php', 'traiter_incident.php', 'fiche_eleve.php'],
    true
);
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'incidents.php', 'icon' => 'fas fa-exclamation-triangle', 'label' => 'Incidents', 'active' => $_discOnIncidents],
    ['href' => 'sanctions.php', 'icon' => 'fas fa-gavel',                'label' => 'Sanctions'],
    ['href' => 'retenues.php',  'icon' => 'fas fa-user-clock',           'label' => 'Retenues'],
    ['href' => 'signaler.php',  'icon' => 'fas fa-plus-circle',          'label' => 'Signaler un incident'],
]);

if (!isset($headerExtraActions)) {
ob_start();
?>
                <a href="signaler.php" class="btn btn-primary">
                    <i class="fas fa-exclamation-triangle"></i> Signaler
                </a>
<?php
$headerExtraActions = ob_get_clean();
}

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
