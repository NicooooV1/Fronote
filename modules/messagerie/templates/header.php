<?php
declare(strict_types=1);
/**
 * En-tête HTML commun - Messagerie
 * Utilise les templates partagés Fronote
 */

// Constantes du module ($folders) + protection CSRF
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/csrf.php';

// Titre par défaut
$pageTitle = $pageTitle ?? 'Messagerie';

// Obtenir la page courante pour activer le menu correspondant
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

// Récupérer le dossier courant pour les menus
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : 'reception';

// Vérifier si l'utilisateur est défini et s'assurer que son type est défini
if (isset($user)) {
    if (!isset($user['type']) && isset($user['profil'])) {
        $user['type'] = $user['profil'];
    } elseif (!isset($user['type'])) {
        $user['type'] = 'eleve';
    }
    $user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));
    $user_fullname = ($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '');
} else {
    $user_initials = '';
    $user_fullname = '';
}

// Générer le token WebSocket pour l'utilisateur
if (isset($user)) {
    require_once __DIR__ . '/../../../API/Core/WebSocket.php';
    $wsToken = \API\Core\WebSocket::generateToken($user['id'], $user['type']);
    $wsUrl = getenv('WEBSOCKET_CLIENT_URL') ?: 'http://localhost:3000';
}

// Variables pour les templates partagés
$activePage = 'messagerie';
$isAdmin = isset($user) && ($user['type'] ?? '') === 'administrateur';
$rootPrefix = '../../';

// CSS supplémentaires spécifiques à la messagerie (unified styles.css)
$extraCss = [
    'assets/css/styles.css',
    'assets/css/sidebar.css',
];
// Refonte 2026 : la feuille partagée messagerie.css (chargée en dernier via
// asset_bust dans $extraHeadHtml ci-dessous) porte le style du fil et de la liste.
// L'ancienne conversation.css n'est plus incluse (remplacée) pour éviter les conflits.

// Head HTML supplémentaire (CSS refonte messagerie + CSRF, WebSocket)
ob_start();
?>
    <!-- Feuille de style PARTAGÉE de la messagerie (refonte 2026). Émise dans
         $extraHeadHtml → chargée APRÈS responsive.css : autorité sur le module.
         Versionnée via asset_bust (cache-busting ?v=mtime). -->
    <link rel="stylesheet" href="<?= asset_bust($rootPrefix . 'modules/messagerie/assets/css/messagerie.css') ?>">
    <!-- Le meta csrf-token canonique est émis par shared_header.php (source unique) -->
    <!-- Socket.IO client + socket GLOBALE UNIQUE (ws-global.js) chargés par shared_header.php.
         websocket-client.js n'ouvre PLUS de seconde socket : c'est un adaptateur mince
         (window.MsgRealtime + alias window.wsClient) au-dessus de window.wsGlobal.
         `defer` → s'exécute après le parsing, avant DOMContentLoaded (donc avant que
         conversation.js n'appelle MsgRealtime). La résolution de wsGlobal est paresseuse. -->
    <script src="<?= asset_bust($rootPrefix . 'modules/messagerie/assets/js/websocket-client.js') ?>" nonce="<?= csp_nonce() ?>" defer></script>
    <?php if (isset($wsToken)): ?>
    <script nonce="<?= csp_nonce() ?>">
        // Identité courante consommée par conversation.js (filtrage de ses propres events).
        // La connexion WS est établie une seule fois par ws-global.js (window.FRONOTE_WS) :
        // aucun init de socket ici.
        window.currentUserId = <?= js_json($user['id']) ?>;
        window.currentUserType = <?= js_json($user['type']) ?>;
    </script>
    <?php endif; ?>
<?php
$extraHeadHtml = ob_get_clean();

// Actions supplémentaires dans le header : bouton de composition (rendu dans
// .header-actions par shared_topbar.php). L'ancienne sidebar n'étant plus rendue,
// c'est ici que doit vivre le bouton « Nouveau message ».
$_msgUserType = $user['type'] ?? 'eleve';
ob_start();
?>
                <a href="<?= $rootPrefix ?>modules/messagerie/new_message.php" class="btn btn-primary">
                    <i class="fas fa-pen"></i> <span><?= __('messagerie.compose') ?></span>
                </a>
                <?php if ($_msgUserType === 'professeur'): ?>
                <a href="<?= $rootPrefix ?>modules/messagerie/class_message.php" class="btn">
                    <i class="fas fa-graduation-cap"></i> <span>Message à la classe</span>
                </a>
                <?php endif; ?>
                <?php if (in_array($_msgUserType, ['vie_scolaire', 'administrateur'], true)): ?>
                <a href="<?= $rootPrefix ?>modules/messagerie/new_announcement.php" class="btn">
                    <i class="fas fa-bullhorn"></i> <span>Nouvelle annonce</span>
                </a>
                <?php endif; ?>
<?php
$headerExtraActions = ob_get_clean();

// Navigation secondaire du module : dossiers de la messagerie (rendue en bandeau
// par shared_topbar.php). Les 5 liens pointent vers index.php (même basename) :
// l'état actif doit donc être explicite, basé sur le dossier courant.
// Les clés et libellés viennent de $folders (config/constants.php) — source
// unique ; la map locale ci-dessous ne fixe que l'icône et l'ordre d'affichage.
require_once __DIR__ . '/../../../templates/module_subnav.php';
$_msgOnIndex = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';
$_msgFolderIcons = [
    'information' => 'fas fa-info-circle',
    'reception'   => 'fas fa-inbox',
    'envoyes'     => 'fas fa-paper-plane',
    'archives'    => 'fas fa-archive',
    'corbeille'   => 'fas fa-trash',
];
$_msgSubnavItems = [];
foreach ($_msgFolderIcons as $_msgFolderKey => $_msgFolderIcon) {
    if (!isset($folders[$_msgFolderKey])) {
        continue; // dossier retiré de la config → pas de lien
    }
    $_msgSubnavItems[] = [
        'href'   => $rootPrefix . 'modules/messagerie/index.php?folder=' . $_msgFolderKey,
        'icon'   => $_msgFolderIcon,
        'label'  => $folders[$_msgFolderKey],
        'active' => $_msgOnIndex && $currentFolder === $_msgFolderKey,
    ];
}
$sidebarExtraContent = renderModuleSubnav($_msgSubnavItems);

// Custom page title for topbar
if (isset($customTitle)) {
    $pageTitle = $customTitle;
} elseif (isset($currentFolder) && !empty($currentFolder)) {
    $pageTitle = 'Messagerie - ' . ucfirst($currentFolder);
}

// Inclure les templates partagés
include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>

            <div class="content-container">
<?php
// Coquille 2 volets (liste + conversation) — refonte 2026. Ouverte uniquement pour
// les pages concernées ; refermée par la page elle-même avant templates/footer.php.
// (Les autres pages du module — new_message, annonces… — ne sont pas encapsulées.)
if (in_array($currentPage, ['index', 'conversation'], true)):
?>
            <div class="msg-app msg-app--<?= htmlspecialchars($currentPage) ?>">
<?php endif; ?>