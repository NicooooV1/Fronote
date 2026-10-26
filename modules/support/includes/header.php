<?php
declare(strict_types=1);
/**
 * M34 – Support & Aide — Header
 *
 * Deux circuits distincts (4.5) :
 *  - demandes INTERNES : tout utilisateur écrit à son établissement ; l'équipe (administration +
 *    vie scolaire) les traite. Rien ne sort de l'instance.
 *  - demandes à la PLATEFORME Fronote : seule la direction transmet (après tri) un problème que
 *    l'établissement ne peut pas résoudre lui-même — voir includes/PlatformLink.php.
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
// Gate d'autorisation par module (défense en profondeur).
enforceModuleAccess(basename(dirname(__DIR__)));

require_once __DIR__ . '/SupportService.php';
require_once __DIR__ . '/PlatformLink.php';
$supportService = new SupportService(getPDO());
$estEquipe = SupportService::estEquipe();
$peutEscalader = SupportService::peutEscalader();

$activePage = 'support';
$pageTitle = $pageTitle ?? 'Aide & support';
$extraCss = ['assets/css/support.css'];

// Navigation secondaire du module (rendue en bandeau par shared_topbar.php).
require_once __DIR__ . '/../../../templates/module_subnav.php';
$sidebarExtraContent = renderModuleSubnav([
    ['href' => 'aide.php',           'icon' => 'fas fa-circle-question', 'label' => 'Aide'],
    ['href' => 'tickets.php',        'icon' => 'fas fa-inbox',           'label' => $estEquipe ? 'Demandes reçues' : 'Mes demandes', 'active' => in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['tickets.php', 'voir_ticket.php'], true)],
    ['href' => 'nouveau_ticket.php', 'icon' => 'fas fa-pen-to-square',   'label' => 'Nouvelle demande'],
    ['href' => 'plateforme.php',     'icon' => 'fas fa-headset',         'label' => 'Plateforme Fronote', 'visible' => $peutEscalader, 'active' => in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['plateforme.php', 'plateforme_demande.php'], true)],
]);

require_once __DIR__ . '/../../../templates/shared_header.php';
require_once __DIR__ . '/../../../templates/shared_topbar.php';
