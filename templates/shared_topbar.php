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
    <div class="main-content" id="main-content" role="main" tabindex="-1">
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
<?php
// Barre de navigation secondaire du module (sous-pages), rendue sous le top-header.
// Les modules alimentent $sidebarExtraContent (liste .sidebar-nav, héritée de l'ancienne
// sidebar) ; on la rend ici en bandeau horizontal. Vide => rien n'est affiché.
$_moduleSubnav = (isset($sidebarExtraContent) && is_string($sidebarExtraContent)) ? trim($sidebarExtraContent) : '';
if ($_moduleSubnav !== ''):
?>
        <nav class="module-subnav" aria-label="Sections du module">
            <?= $sidebarExtraContent ?>
        </nav>
<?php endif; ?>
