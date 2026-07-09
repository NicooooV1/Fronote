<?php
/**
 * M16 – Documents — Header
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/DocumentService.php';
$docService = new DocumentService(getPDO());

$activePage = 'documents';
$pageTitle = $pageTitle ?? 'Documents';
$extraCss = ['assets/css/documents.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php),
// visible uniquement pour admin/prof/vie scolaire.
$isGestionnaireDocs = isAdmin() || isTeacher() || isVieScolaire();
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'documents.php', 'icon' => 'fas fa-folder-open', 'label' => 'Tous les documents',  'visible' => $isGestionnaireDocs],
    ['href' => 'ajouter.php',   'icon' => 'fas fa-upload',      'label' => 'Ajouter un document', 'visible' => $isGestionnaireDocs],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
