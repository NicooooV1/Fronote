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
if (in_array($currentPage, ['conversation'])) {
    $extraCss[] = 'assets/css/conversation.css';
}

// Head HTML supplémentaire (CSRF, WebSocket)
ob_start();
?>
    <?= csrf_meta() ?>
    <!-- Socket.IO client chargé par shared_header.php (4.7.5) -->
    <script src="<?= $rootPrefix ?>modules/messagerie/assets/js/websocket-client.js" nonce="<?= csp_nonce() ?>"></script>
    <?php if (isset($wsToken)): ?>
    <script nonce="<?= csp_nonce() ?>">
        window.currentUserId = <?= json_encode($user['id']) ?>;
        window.currentUserType = <?= json_encode($user['type']) ?>;
        document.addEventListener('DOMContentLoaded', () => {
            window.wsClient.init(<?= json_encode($wsUrl) ?>, <?= json_encode($wsToken) ?>);
        });
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