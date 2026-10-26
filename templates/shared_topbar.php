<?php
declare(strict_types=1);
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

// Bandeau PERMANENT d'impersonation Support (refonte 3-mondes) — visible sur toutes les
// pages établissement tant qu'une session d'impersonation est active.
if (!empty($_SESSION['impersonation'])) {
    $_imp = $_SESSION['impersonation'];
    $_impBase = defined('BASE_URL') ? BASE_URL : '';
    $_impName = htmlspecialchars((string) ($_imp['target_name'] ?? 'utilisateur'));
    $_impSid = (int) ($_imp['support_session_id'] ?? 0);
    echo '<div role="alert" style="position:sticky;top:0;z-index:9999;background:#b91c1c;color:#fff;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-family:system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.25)">'
       . '<span><strong>&#9888; Mode impersonation Support</strong> &mdash; vous agissez en tant que <strong>' . $_impName . '</strong> (intervention #' . $_impSid . '). Toutes vos actions sont journalis&eacute;es.</span>'
       . '<form method="post" action="' . htmlspecialchars($_impBase) . '/impersonation/stop.php" style="margin:0">'
       . (function_exists('csrfField') ? csrfField() : '')
       . '<button type="submit" style="background:#fff;color:#b91c1c;padding:6px 12px;border:0;border-radius:6px;font-weight:700;white-space:nowrap;cursor:pointer">Terminer l\'impersonation</button>'
       . '</form>'
       . '</div>';
}
?>

    <!-- Main Content -->
<?php
// En-tête de module UNIQUE (refonte 4.6) : icône + famille du module, titre, sous-titre, actions,
// puis les onglets du module. Les doublons d'en-tête dans le contenu des pages (h1, .content-header,
// .page-header, .welcome-banner) sont retirés et leurs actions remontées ici par
// \API\UI\PageHeaderFilter (filtre de sortie). Une page peut s'en exclure : $keepPageHeading = true.
$_mh_key = $activePage;
if (($_mh_key === '' || !isset($_tb_modules[$_mh_key])) && preg_match('#/modules/([a-z0-9_]+)/#', (string) ($_SERVER['SCRIPT_NAME'] ?? ''), $_mm)) { $_mh_key = $_mm[1]; }
$_mh_mod = ($_mh_key !== '' && isset($_tb_modules[$_mh_key])) ? $_tb_modules[$_mh_key] : \API\UI\TopbarNav::moduleMeta($_mh_key);
$_mh_group = $_mh_mod ? ($_tb_groups[$_mh_mod['group']] ?? ['label' => \API\UI\TopbarNav::groupLabel((string) $_mh_mod['group'])]) : null;
$_mh_eyebrow = '';
if ($_mh_mod) {
    $_mh_eyebrow = (string) ($_mh_group['label'] ?? '');
    if (mb_strtolower(trim((string) $_mh_mod['label'])) !== mb_strtolower(trim((string) $pageTitle))) {
        $_mh_eyebrow .= ($_mh_eyebrow !== '' ? ' · ' : '') . $_mh_mod['label'];
    }
}
if (empty($keepPageHeading)) {
    ob_start(static fn(string $buf): string => \API\UI\PageHeaderFilter::apply($buf));
}
?>
    <div class="main-content" id="main-content" role="main" tabindex="-1">
        <div class="top-header mh<?= $_mh_mod ? '' : ' mh--plain' ?>"<?= $_mh_mod ? ' data-group="' . htmlspecialchars((string) $_mh_mod['group'], ENT_QUOTES) . '"' : '' ?>>
            <?php if ($_mh_mod): ?>
            <span class="mh-icon" aria-hidden="true"><i class="<?= htmlspecialchars((string) $_mh_mod['icon'], ENT_QUOTES) ?>"></i></span>
            <?php endif; ?>
            <div class="page-title">
                <?php if ($_pb): ?>
                <a href="<?= htmlspecialchars($_pb['url']) ?>" class="page-back" title="<?= htmlspecialchars($_pb['label']) ?>">
                    <i class="fas fa-arrow-left"></i><span><?= htmlspecialchars($_pb['label']) ?></span>
                </a>
                <?php elseif ($_mh_eyebrow !== ''): ?>
                <p class="mh-eyebrow"><?= htmlspecialchars($_mh_eyebrow) ?></p>
                <?php endif; ?>
                <h1><?= htmlspecialchars($pageTitle) ?><?php
                    // Épingler le module courant dans la barre (même action que ★ du menu Modules).
                    if (!empty($_tb_modules) && $activePage !== '' && isset($_tb_modules[$activePage])):
                        $_pinOn = isset($_tb_favKeys[$activePage]);
                        $_pinTxt = $_pinOn ? (($v = __('nav.unpin')) !== 'nav.unpin' ? $v : 'Retirer de la barre') : (($v = __('nav.pin')) !== 'nav.pin' ? $v : 'Épingler dans la barre');
                ?><button type="button" class="tb-pin tb-pin--page<?= $_pinOn ? ' is-on' : '' ?>" data-tb-pin="<?= htmlspecialchars($activePage, ENT_QUOTES) ?>"
                        aria-pressed="<?= $_pinOn ? 'true' : 'false' ?>" title="<?= htmlspecialchars($_pinTxt, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($_pinTxt, ENT_QUOTES) ?>"><i class="<?= $_pinOn ? 'fas' : 'far' ?> fa-star" aria-hidden="true"></i></button><?php endif; ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                <p class="subtitle mh-sub"><?= htmlspecialchars($pageSubtitle) ?></p>
                <?php else: ?><?= \API\UI\PageHeaderFilter::SUBTITLE_MARK ?><?php endif; ?>
            </div>

            <div class="header-actions mh-actions">
                <?= $headerExtraActions ?><?= \API\UI\PageHeaderFilter::ACTIONS_MARK ?>
            </div>
        </div>
<?php
// Onglets du module (sous-pages), rendus sous l'en-tête.
// Les modules alimentent $sidebarExtraContent (liste .sidebar-nav, héritée de l'ancienne
// sidebar) ; on la rend ici en bandeau horizontal. Vide => rien n'est affiché.
$_moduleSubnav = (isset($sidebarExtraContent) && is_string($sidebarExtraContent)) ? trim($sidebarExtraContent) : '';
if ($_moduleSubnav !== ''):
?>
        <nav class="module-subnav" aria-label="Sections du module">
            <?= $sidebarExtraContent ?>
        </nav>
<?php endif; ?>
<?= empty($keepPageHeading) ? \API\UI\PageHeaderFilter::CONTENT_MARK : '' ?>
