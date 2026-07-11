<?php
declare(strict_types=1);
/**
 * Template: Topbar horizontal navigation.
 * Replaces the old sidebar.
 *
 * Structure:
 * [F FRONOTE] | [Pedagogie v] [Vie scol. v] [Communication v] [...] | [Search] [Notifs] [Theme] [Etab] [Avatar v]
 *
 * Variables expected (from shared_header.php):
 *   $rootPrefix, $activePage, $user_initials, $user_fullname
 */

$rootPrefix = $rootPrefix ?? '../';
$activePage = $activePage ?? '';
$user_initials = $user_initials ?? '';
$user_fullname = $user_fullname ?? '';
$isAdmin = $isAdmin ?? false;

// Modèle de navigation (modules groupés, favoris, badge notifs, établissement, enfants)
// — toute la logique/SQL est extraite dans \API\UI\TopbarNav ; le template n'itère et n'échappe plus.
$__nav = \API\UI\TopbarNav::build();
$_topbar_modules        = $__nav['modules'];
$_topbar_favorites      = $__nav['favorites'];
$_topbar_fav_keys       = $__nav['fav_keys'];
$_topbar_notif_count    = $__nav['notif_count'];
$_topbar_etab_name      = $__nav['etab_name'];
$_topbar_is_parent      = $__nav['is_parent'];
$_topbar_children       = $__nav['children'];
$_topbar_selected_child = $__nav['selected_child'];
$_topbar_role           = $__nav['role'];

?>

<nav class="topbar-nav" role="navigation" aria-label="Navigation principale">
    <!-- Brand -->
    <a href="<?= $rootPrefix ?>accueil/accueil.php" class="topbar-brand">
        <span class="topbar-brand__icon">F</span>
        <span class="topbar-brand__text">FRONOTE</span>
    </a>

    <!-- Category dropdowns (desktop) -->
    <div class="topbar-categories" id="topbar-categories">
        <!-- Épingler la page courante (pas sur l'accueil : rien à épingler) -->
        <?php if (($activePage ?? '') !== 'accueil'): ?>
        <button class="topbar-dropdown__trigger" type="button" id="topbar-pin-page"
                title="<?= __('nav.favorite_add', ['default' => 'Épingler cette page']) ?>"
                data-fr-click="fronotePinCurrentPage">
            <i class="far fa-star"></i>
        </button>
        <?php endif; ?>

        <!-- Favoris (masqué s'il n'y en a aucun) -->
        <?php if (!empty($_topbar_favorites)): ?>
        <div class="topbar-dropdown topbar-dropdown--favorites" data-category="favorites">
            <button class="topbar-dropdown__trigger" type="button" aria-expanded="false" title="<?= __('nav.favorites') ?>">
                <i class="fas fa-star"></i>
                <span><?= __('nav.favorites') ?></span>
                <i class="fas fa-chevron-down topbar-dropdown__arrow"></i>
            </button>
            <div class="topbar-dropdown__menu" id="topbar-favorites-menu">
                <?php foreach ($_topbar_favorites as $fav): ?>
                <?php $_isPage = ($fav['type'] ?? 'module') === 'page'; ?>
                <div class="topbar-dropdown__item-wrap">
                    <a href="<?= $_isPage ? htmlspecialchars($fav['route']) : ($rootPrefix . htmlspecialchars($fav['route'])) ?>"
                       class="topbar-dropdown__item <?= ($activePage === $fav['module_key']) ? 'active' : '' ?>">
                        <i class="<?= htmlspecialchars($fav['icon'] ?? 'fas fa-circle') ?>"></i>
                        <span><?= htmlspecialchars($fav['label'] ?? $fav['module_key']) ?></span>
                    </a>
                    <?php if ($_isPage): ?>
                    <button class="topbar-fav-toggle is-favorite" type="button"
                            title="<?= __('nav.favorite_remove') ?>"
                            data-fr-click="fronoteFavRemove" data-fr-args='["<?= htmlspecialchars($fav['module_key'], ENT_QUOTES) ?>"]'>
                        <i class="fas fa-star"></i>
                    </button>
                    <?php else: ?>
                    <button class="topbar-fav-toggle is-favorite" type="button"
                            data-module-key="<?= htmlspecialchars($fav['module_key']) ?>"
                            title="<?= __('nav.favorite_remove') ?>"
                            aria-pressed="true">
                        <i class="fas fa-star"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <script nonce="<?= csp_nonce() ?>">
        (function () {
            var EP = '<?= $rootPrefix ?>API/endpoints/favorites.php';
            function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
            function post(body) {
                return fetch(EP, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                    body: JSON.stringify(Object.assign({ csrf_token: csrf() }, body))
                }).then(function (r) {
                    var next = r.headers.get('X-Csrf-Token-Next');
                    if (next) { var m = document.querySelector('meta[name="csrf-token"]'); if (m) m.setAttribute('content', next); }
                    return r.json();
                });
            }
            window.fronotePinCurrentPage = function () {
                post({ action: 'add_page', url: window.location.pathname + window.location.search, label: (document.title || '').slice(0, 150) })
                    .then(function () { window.location.reload(); })
                    .catch(function () {});
            };
            window.fronoteFavRemove = function (key) {
                post({ action: 'remove', key: key }).then(function () { window.location.reload(); }).catch(function () {});
            };
        })();
        </script>

        <?php foreach ($_topbar_modules as $catKey => $category): ?>
        <div class="topbar-dropdown" data-category="<?= htmlspecialchars($catKey) ?>">
            <button class="topbar-dropdown__trigger" type="button" aria-expanded="false">
                <i class="<?= htmlspecialchars($category['icon']) ?>"></i>
                <span><?= htmlspecialchars($category['label']) ?></span>
                <i class="fas fa-chevron-down topbar-dropdown__arrow"></i>
            </button>
            <div class="topbar-dropdown__menu">
                <?php foreach ($category['modules'] as $mod):
                    $_mk = $mod['module_key'] ?? '';
                    $_isFav = isset($_topbar_fav_keys[$_mk]);
                ?>
                <div class="topbar-dropdown__item-wrap">
                    <a href="<?= $rootPrefix . htmlspecialchars($mod['route']) ?>"
                       class="topbar-dropdown__item <?= ($activePage === $_mk) ? 'active' : '' ?>">
                        <i class="<?= htmlspecialchars($mod['icon'] ?? 'fas fa-circle') ?>"></i>
                        <span><?= htmlspecialchars($mod['label'] ?? $_mk) ?></span>
                    </a>
                    <button class="topbar-fav-toggle <?= $_isFav ? 'is-favorite' : '' ?>" type="button"
                            data-module-key="<?= htmlspecialchars($_mk) ?>"
                            title="<?= __('nav.favorite_toggle', ['default' => 'Épingler/retirer des favoris']) ?>"
                            aria-pressed="<?= $_isFav ? 'true' : 'false' ?>">
                        <i class="<?= $_isFav ? 'fas' : 'far' ?> fa-star"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Right actions -->
    <div class="topbar-actions">
        <!-- Search (Ctrl+K) -->
        <button class="topbar-action-btn" id="topbar-search-btn" title="Recherche (Ctrl+K)" aria-label="Recherche (Ctrl+K)" type="button">
            <i class="fas fa-search" aria-hidden="true"></i>
        </button>

        <!-- Notifications -->
        <a href="<?= $rootPrefix ?>modules/notifications/notifications.php" class="topbar-action-btn topbar-notif-btn"
           title="<?= __('nav.notifications') ?>" aria-label="<?= __('nav.notifications') ?>">
            <i class="fas fa-bell" aria-hidden="true"></i>
            <?php if ($_topbar_notif_count > 0): ?>
            <span class="topbar-badge"><?= $_topbar_notif_count > 99 ? '99+' : $_topbar_notif_count ?></span>
            <?php endif; ?>
        </a>

        <!-- Theme toggle -->
        <button class="topbar-action-btn" id="topbar-theme-toggle" title="Theme" aria-label="<?= __('nav.theme_toggle', ['default' => 'Changer de thème']) ?>" type="button">
            <i class="fas fa-sun" id="theme-icon-light" aria-hidden="true"></i>
            <i class="fas fa-moon" id="theme-icon-dark" style="display:none" aria-hidden="true"></i>
        </button>

        <!-- Parent child selector -->
        <?php if ($_topbar_is_parent && count($_topbar_children) > 1): ?>
        <div class="topbar-child-selector">
            <select data-fr-change="switchChild" data-fr-pass="value" class="topbar-child-select">
                <?php foreach ($_topbar_children as $_ch): ?>
                <option value="<?= (int)$_ch['id'] ?>" <?= ($_topbar_selected_child && (int)$_ch['id'] === (int)$_topbar_selected_child['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($_ch['prenom'] . ' ' . $_ch['nom']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- Establishment name -->
        <?php if ($_topbar_etab_name): ?>
        <span class="topbar-etab-name" title="<?= htmlspecialchars($_topbar_etab_name) ?>">
            <?= htmlspecialchars(mb_strimwidth($_topbar_etab_name, 0, 20, '...')) ?>
        </span>
        <?php endif; ?>

        <!-- Admin link -->
        <?php if ($isAdmin || isAdmin()): ?>
        <a href="<?= $rootPrefix ?>admin/dashboard.php" class="topbar-action-btn" title="Administration" aria-label="Administration">
            <i class="fas fa-cogs" aria-hidden="true"></i>
        </a>
        <?php endif; ?>

        <!-- User dropdown -->
        <div class="topbar-dropdown topbar-user-dropdown">
            <button class="topbar-user-avatar" type="button" aria-expanded="false" aria-haspopup="menu" aria-label="<?= __('nav.user_menu', ['default' => 'Menu utilisateur']) ?> — <?= htmlspecialchars($user_fullname) ?>" title="<?= htmlspecialchars($user_fullname) ?>">
                <?= htmlspecialchars($user_initials) ?>
            </button>
            <div class="topbar-dropdown__menu topbar-dropdown__menu--right">
                <a href="<?= $rootPrefix ?>modules/profil/index.php" class="topbar-dropdown__item">
                    <i class="fas fa-user"></i> <span><?= __('nav.profile') ?></span>
                </a>
                <a href="<?= $rootPrefix ?>parametres/parametres.php" class="topbar-dropdown__item">
                    <i class="fas fa-cog"></i> <span><?= __('nav.settings') ?></span>
                </a>
                <hr class="topbar-dropdown__divider">
                <a href="<?= $rootPrefix ?>login/logout.php" class="topbar-dropdown__item topbar-dropdown__item--danger">
                    <i class="fas fa-sign-out-alt"></i> <span><?= __('nav.logout') ?></span>
                </a>
            </div>
        </div>

        <!-- Mobile hamburger -->
        <button class="topbar-hamburger" id="topbar-hamburger" type="button" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
</nav>

<!-- Search modal -->
<div class="search-modal" id="search-modal" role="dialog" aria-hidden="true">
    <div class="search-modal__backdrop"></div>
    <div class="search-modal__content">
        <div class="search-modal__input-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="search-modal-input" placeholder="<?= __('nav.search_placeholder', ['default' => 'Rechercher un module, un eleve, une page...']) ?>" autocomplete="off">
            <kbd>Esc</kbd>
        </div>
        <div class="search-modal__results" id="search-modal-results"></div>
    </div>
</div>

<!-- Mobile slide-out panel -->
<div class="topbar-mobile-panel" id="topbar-mobile-panel">
    <div class="topbar-mobile-panel__header">
        <span class="topbar-brand__icon">F</span>
        <span>FRONOTE</span>
        <button class="topbar-mobile-panel__close" id="topbar-mobile-close" type="button">&times;</button>
    </div>
    <div class="topbar-mobile-panel__body">
        <a href="<?= $rootPrefix ?>accueil/accueil.php" class="topbar-mobile-link <?= $activePage === 'accueil' ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Accueil
        </a>
        <?php foreach ($_topbar_modules as $catKey => $category): ?>
        <div class="topbar-mobile-category">
            <div class="topbar-mobile-category__title"><?= htmlspecialchars($category['label']) ?></div>
            <?php foreach ($category['modules'] as $mod): ?>
            <a href="<?= $rootPrefix . htmlspecialchars($mod['route']) ?>"
               class="topbar-mobile-link <?= ($activePage === ($mod['module_key'] ?? '')) ? 'active' : '' ?>">
                <i class="<?= htmlspecialchars($mod['icon'] ?? 'fas fa-circle') ?>"></i>
                <?= htmlspecialchars($mod['label'] ?? $mod['module_key']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($_topbar_is_parent && !empty($_topbar_children)): ?>
<script nonce="<?= $_hdr_nonce ?? '' ?>">
function switchChild(childId) {
    if (!childId) return;
    var url = new URL(window.location.href);
    url.searchParams.set('switch_child', childId);
    window.location.href = url.toString();
}
</script>
<?php endif; ?>
