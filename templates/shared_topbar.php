<?php
/**
 * Template: Topbar + main content opening.
 * Replaces the old sidebar + topbar combo.
 * Include after shared_header.php.
 *
 * Variables (from shared_header.php):
 *   $pageTitle, $pageSubtitle, $user_initials, $user_fullname,
 *   $headerExtraActions, $rootPrefix, $activePage, $isAdmin
 */

$pageTitle = $pageTitle ?? 'FRONOTE';
$pageSubtitle = $pageSubtitle ?? '';
$user_initials = $user_initials ?? '';
$user_fullname = $user_fullname ?? '';
$headerExtraActions = $headerExtraActions ?? '';
$rootPrefix = $rootPrefix ?? '../';
$activePage = $activePage ?? '';
$isAdmin = $isAdmin ?? false;

// $pageBack : bouton de retour optionnel rendu dans le top-header.
// Une page le definit pour offrir un retour vers sa page parente/liste.
// Formes acceptees :
//   - string  : chemin RELATIF a la racine du projet ($rootPrefix est prepende),
//               ex. 'admin/modules/index.php'  -> /Pronote/admin/modules/index.php
//   - array   : ['url' => ..., 'label' => ..., 'raw' => bool]
//               'raw' => true => 'url' utilise tel quel (deja resolu / externe),
//               sinon $rootPrefix est prepende comme pour la forme string.
// NB : ne JAMAIS passer un chemin commencant par '/' sans 'raw' — sous le
// sous-dossier de deploiement (/Pronote) il resoudrait a la racine du domaine.
$pageBack = $pageBack ?? null;
$_pb = null;
if (is_string($pageBack) && $pageBack !== '') {
    $_pb = ['url' => $rootPrefix . ltrim($pageBack, '/'), 'label' => __('nav.back', ['default' => 'Retour'])];
} elseif (is_array($pageBack) && !empty($pageBack['url'])) {
    $_pb = [
        'url'   => !empty($pageBack['raw']) ? $pageBack['url'] : $rootPrefix . ltrim((string) $pageBack['url'], '/'),
        'label' => $pageBack['label'] ?? __('nav.back', ['default' => 'Retour']),
    ];
}

// Include the topbar navigation
require __DIR__ . '/shared_topbar_nav.php';
?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="page-title">
                <?php if ($_pb): ?>
                <a href="<?= htmlspecialchars($_pb['url']) ?>" class="page-back" title="<?= htmlspecialchars($_pb['label']) ?>">
                    <i class="fas fa-arrow-left"></i><span><?= htmlspecialchars($_pb['label']) ?></span>
                </a>
                <?php endif; ?>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                <p class="subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>

            <div class="header-actions">
                <?= $headerExtraActions ?>
            </div>
        </div>
