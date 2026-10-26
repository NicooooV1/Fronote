<?php
declare(strict_types=1);
/**
 * Template : barre de navigation principale (refonte 4.4).
 *
 *   [F FRONOTE] [Accueil] [raccourci] [raccourci] … [▦ Modules ▾]   [Rechercher Ctrl K] [🔔] [Avatar ▾]
 *
 * - Raccourcis = favoris de l'utilisateur, sinon sélection par rôle : un module courant = 1 clic.
 * - « Modules » ouvre UN panneau qui montre tous les modules rangés en familles, avec un champ
 *   de filtre ; il s'ouvre au survol (souris) ou au clic, et ★ épingle un module dans la barre.
 * - Compte (avatar) : profil, paramètres, thème, administration, support, déconnexion.
 * - Mobile : tiroir latéral (raccourcis en tuiles + familles dépliables + filtre).
 * Modèle : \API\UI\TopbarNav ; comportement : assets/js/topbar.js ; styles : assets/css/topbar.css.
 *
 * Variables attendues (shared_header.php) : $rootPrefix, $activePage, $user_initials, $user_fullname
 */

$rootPrefix = $rootPrefix ?? '../';
$activePage = $activePage ?? '';
$user_initials = $user_initials ?? '';
$user_fullname = $user_fullname ?? '';
// Certaines pages de module n'alimentent pas ces variables : repli sur la session.
if ($user_initials === '' && function_exists('getUserInitials')) { $user_initials = (string) getUserInitials(); }
if ($user_fullname === '' && function_exists('getUserFullName')) { $user_fullname = (string) getUserFullName(); }
$isAdmin = $isAdmin ?? false;

$__nav = \API\UI\TopbarNav::build();
$_tb_groups    = $__nav['groups'];
$_tb_modules   = $__nav['modules'];
$_tb_favKeys   = $__nav['fav_keys'];
$_tb_shortcuts = $__nav['shortcuts'];
$_tb_children  = $__nav['children'];
$_tb_child     = $__nav['selected_child'];
$_tb_isAdmin   = $isAdmin || (function_exists('isAdmin') && isAdmin());
// Compat : d'autres gabarits lisent encore ces variables.
$_topbar_notif_count = $__nav['notif_count'];
$_topbar_etab_name   = $__nav['etab_name'];
$_topbar_role        = $__nav['role'];

// Traduction avec repli : le traducteur renvoie la clé quand elle est absente d'une langue.
$_t = static function (string $k, string $d): string { $v = __($k); return ($v === '' || $v === $k) ? $d : $v; };
$_h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_href = static fn(array $l): string => ($l['type'] ?? 'module') === 'page' ? (string) $l['route'] : $rootPrefix . $l['route'];
$_reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$_isActive = static function (array $l) use ($activePage, $_reqPath): bool {
    if (($l['type'] ?? 'module') === 'page') {
        return $_reqPath !== '' && (string) parse_url((string) $l['route'], PHP_URL_PATH) === $_reqPath;
    }
    return $activePage !== '' && $activePage === $l['module_key'];
};

// La page courante est-elle déjà dans la barre ? Sinon on l'y affiche (contexte : « où suis-je »).
$_tb_context = null;
if ($activePage !== '' && isset($_tb_modules[$activePage])) {
    $inBar = false;
    foreach ($_tb_shortcuts as $s) {
        if (($s['type'] ?? 'module') === 'module' && $s['module_key'] === $activePage) { $inBar = true; break; }
    }
    if (!$inBar) { $_tb_context = $_tb_modules[$activePage]; }
}
$_tb_activeGroup = ($activePage !== '' && isset($_tb_modules[$activePage])) ? $_tb_modules[$activePage]['group'] : null;

// Lien de changement d'enfant (parent) qui conserve la page courante.
$_childUrl = static function (int $id): string {
    $q = $_GET;
    unset($q['enfant']);
    $q['switch_child'] = $id;
    return '?' . http_build_query($q);
};
$_showChildSwitch = $__nav['is_parent'] && count($_tb_children) > 1 && $activePage !== 'accueil';
$_initials = static function (array $c): string {
    $f = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    return strtoupper($f((string) $c['prenom'], 0, 1) . $f((string) $c['nom'], 0, 1));
};

$_L = [
    'home'      => $_t('nav.home', 'Accueil'),
    'modules'   => $_t('nav.modules', 'Modules'),
    'filter'    => $_t('nav.filter_modules', 'Filtrer les modules…'),
    'search'    => $_t('nav.search', 'Rechercher'),
    'pin'       => $_t('nav.pin', 'Épingler dans la barre'),
    'unpin'     => $_t('nav.unpin', 'Retirer de la barre'),
    'profile'   => $_t('nav.profile', 'Mon profil'),
    'settings'  => $_t('nav.settings', 'Paramètres'),
    'admin'     => $_t('nav.admin', 'Administration'),
    'support'   => $_t('nav.support', 'Aide & support'),
    'logout'    => $_t('nav.logout', 'Déconnexion'),
    'theme'     => $_t('nav.theme', 'Thème'),
    'notifs'    => $_t('nav.notifications', 'Notifications'),
    'menu'      => $_t('nav.open_menu', 'Ouvrir le menu'),
    'close'     => $_t('common.close', 'Fermer'),
    'shortcuts' => $_t('nav.shortcuts', 'Raccourcis'),
    'none'      => $_t('nav.no_match', 'Aucun module ne correspond.'),
    'child'     => $_t('nav.select_child', 'Choisir un enfant'),
];

// Données pour la recherche (Ctrl+K) et la mise à jour des raccourcis sans rechargement.
$_tb_data = [
    'base'      => $rootPrefix,
    'active'    => $activePage,
    'modules'   => array_values(array_map(static fn($m) => [
        'key' => $m['module_key'], 'label' => $m['label'], 'icon' => $m['icon'], 'route' => $m['route'],
        'group' => $_tb_groups[$m['group']]['label'] ?? '',
    ], $_tb_modules)),
    'defaults'  => $__nav['defaults'],
    'favorites' => $__nav['favorites'],
    'extra'     => array_values(array_filter([
        ['label' => $_L['home'], 'icon' => 'fas fa-house', 'url' => $rootPrefix . 'accueil/accueil.php'],
        ['label' => $_L['notifs'], 'icon' => 'fas fa-bell', 'url' => $rootPrefix . 'modules/notifications/notifications.php'],
        ['label' => $_L['profile'], 'icon' => 'fas fa-user', 'url' => $rootPrefix . 'modules/profil/index.php'],
        ['label' => $_L['settings'], 'icon' => 'fas fa-gear', 'url' => $rootPrefix . 'parametres/parametres.php'],
        $_tb_isAdmin ? ['label' => $_L['admin'], 'icon' => 'fas fa-screwdriver-wrench', 'url' => $rootPrefix . 'admin/dashboard.php'] : null,
        $__nav['support_route'] ? ['label' => $_L['support'], 'icon' => 'fas fa-life-ring', 'url' => $rootPrefix . $__nav['support_route']] : null,
        ['label' => $_L['logout'], 'icon' => 'fas fa-arrow-right-from-bracket', 'url' => $rootPrefix . 'login/logout.php'],
    ])),
];

/** Un lien de la barre (raccourci). */
$_renderLink = static function (array $l, string $extra = '') use ($_h, $_href, $_isActive): string {
    $on = $_isActive($l);
    return '<a href="' . $_h($_href($l)) . '" class="tb-link' . ($on ? ' is-active' : '') . $extra . '"'
        . ($on ? ' aria-current="page"' : '') . ' data-key="' . $_h($l['module_key']) . '">'
        . '<i class="' . $_h($l['icon']) . '" aria-hidden="true"></i><span>' . $_h($l['label']) . '</span></a>';
};
?>

<nav class="topbar-nav tb" id="tb" role="navigation" aria-label="<?= $_h($_t('nav.main', 'Navigation principale')) ?>">
    <a href="<?= $_h($rootPrefix) ?>accueil/accueil.php" class="tb-brand" aria-label="Fronote — <?= $_h($_L['home']) ?>">
        <span class="tb-brand__icon" aria-hidden="true">F</span>
        <span class="tb-brand__text">FRONOTE</span>
    </a>

    <div class="tb-links" id="tb-links">
        <?= $_renderLink(['module_key' => 'accueil', 'label' => $_L['home'], 'icon' => 'fas fa-house', 'route' => 'accueil/accueil.php', 'type' => 'module']) ?>
        <span class="tb-links__set" id="tb-shortcuts">
            <?php foreach ($_tb_shortcuts as $s): ?><?= $_renderLink($s) ?><?php endforeach; ?>
        </span>
        <?php if ($_tb_context): ?><?= $_renderLink($_tb_context, ' tb-link--context') ?><?php endif; ?>
    </div>

    <?php if ($_tb_groups): ?>
    <div class="tb-menu tb-modules" data-tb-menu data-tb-hover>
        <button class="tb-trigger" type="button" aria-expanded="false" aria-controls="tb-mega">
            <i class="fas fa-grip" aria-hidden="true"></i><span><?= $_h($_L['modules']) ?></span>
            <i class="fas fa-chevron-down tb-trigger__chev" aria-hidden="true"></i>
        </button>
        <div class="tb-pop tb-mega" id="tb-mega">
            <label class="tb-filter">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" class="tb-filter__input" placeholder="<?= $_h($_L['filter']) ?>" aria-label="<?= $_h($_L['filter']) ?>" autocomplete="off" data-tb-filter="#tb-mega">
            </label>
            <div class="tb-mega__grid">
                <?php foreach ($_tb_groups as $gk => $group): ?>
                <section class="tb-group<?= $gk === $_tb_activeGroup ? ' is-current' : '' ?>" data-tb-group>
                    <h3 class="tb-group__title"><i class="<?= $_h($group['icon']) ?>" aria-hidden="true"></i><?= $_h($group['label']) ?></h3>
                    <ul class="tb-group__list">
                        <?php foreach ($group['modules'] as $m): $fav = isset($_tb_favKeys[$m['module_key']]); $on = $_isActive($m); ?>
                        <li class="tb-item" data-tb-item data-label="<?= $_h($m['label']) ?>">
                            <a href="<?= $_h($_href($m)) ?>" class="tb-item__link<?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                                <i class="<?= $_h($m['icon']) ?>" aria-hidden="true"></i><span><?= $_h($m['label']) ?></span>
                            </a>
                            <button type="button" class="tb-pin<?= $fav ? ' is-on' : '' ?>" data-tb-pin="<?= $_h($m['module_key']) ?>" aria-pressed="<?= $fav ? 'true' : 'false' ?>"
                                    title="<?= $_h($fav ? $_L['unpin'] : $_L['pin']) ?>" aria-label="<?= $_h(($fav ? $_L['unpin'] : $_L['pin']) . ' : ' . $m['label']) ?>">
                                <i class="<?= $fav ? 'fas' : 'far' ?> fa-star" aria-hidden="true"></i>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endforeach; ?>
            </div>
            <p class="tb-empty" hidden><?= $_h($_L['none']) ?></p>
            <p class="tb-mega__foot">
                <span><i class="fas fa-star" aria-hidden="true"></i> <?= $_h($_t('nav.pin_hint', 'Épinglez vos modules pour les avoir directement dans la barre.')) ?></span>
                <span class="tb-mega__kbd"><kbd>Ctrl</kbd> <kbd>K</kbd> <?= $_h($_t('nav.search_everywhere', 'pour tout rechercher')) ?></span>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="tb-right">
        <?php if ($_showChildSwitch): ?>
        <div class="tb-menu tb-child" data-tb-menu>
            <button class="tb-chip" type="button" aria-expanded="false" aria-controls="tb-child-pop" aria-label="<?= $_h($_L['child']) ?>">
                <span class="tb-chip__av" aria-hidden="true"><?= $_h($_initials($_tb_child)) ?></span>
                <span class="tb-chip__name"><?= $_h($_tb_child['prenom']) ?></span>
                <i class="fas fa-chevron-down tb-trigger__chev" aria-hidden="true"></i>
            </button>
            <div class="tb-pop tb-pop--right tb-list" id="tb-child-pop">
                <?php foreach ($_tb_children as $c): $sel = (int) $c['id'] === (int) $_tb_child['id']; ?>
                <a href="<?= $_h($_childUrl((int) $c['id'])) ?>" class="tb-list__item<?= $sel ? ' is-active' : '' ?>"<?= $sel ? ' aria-current="true"' : '' ?>>
                    <span class="tb-chip__av" aria-hidden="true"><?= $_h($_initials($c)) ?></span>
                    <span class="tb-list__txt"><?= $_h($c['prenom'] . ' ' . $c['nom']) ?><small><?= $_h($c['classe_nom'] ?? '') ?></small></span>
                    <?php if ($sel): ?><i class="fas fa-check" aria-hidden="true"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <button class="tb-search" id="topbar-search-btn" type="button" aria-label="<?= $_h($_L['search']) ?> (Ctrl+K)" aria-haspopup="dialog">
            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
            <span class="tb-search__label"><?= $_h($_L['search']) ?></span>
            <kbd class="tb-search__kbd" data-tb-kbd>Ctrl K</kbd>
        </button>

        <!-- Centre de notifications : lien de repli sans JS ; notif-center.js en fait un panneau -->
        <a href="<?= $_h($rootPrefix) ?>modules/notifications/notifications.php" class="tb-icon topbar-notif-btn" id="nc-bell"
           data-count="<?= (int) $_topbar_notif_count ?>" aria-haspopup="dialog" aria-expanded="false"
           title="<?= $_h($_L['notifs']) ?>" aria-label="<?= $_h($_L['notifs']) ?>">
            <i class="fas fa-bell" aria-hidden="true"></i>
            <span class="topbar-badge" id="nc-badge"<?= $_topbar_notif_count > 0 ? '' : ' hidden' ?>><?= $_topbar_notif_count > 99 ? '99+' : (int) $_topbar_notif_count ?></span>
        </a>

        <div class="tb-menu tb-account" data-tb-menu>
            <button class="tb-avatar" type="button" aria-expanded="false" aria-controls="tb-account-pop"
                    aria-label="<?= $_h($_t('nav.user_menu', 'Menu du compte') . ' — ' . $user_fullname) ?>" title="<?= $_h($user_fullname) ?>">
                <?= $_h($user_initials) ?>
            </button>
            <div class="tb-pop tb-pop--right tb-account__pop" id="tb-account-pop">
                <div class="tb-account__head">
                    <span class="tb-avatar tb-avatar--lg" aria-hidden="true"><?= $_h($user_initials) ?></span>
                    <span class="tb-account__who">
                        <strong><?= $_h($user_fullname) ?></strong>
                        <small><?= $_h($__nav['role_label']) ?><?= $_topbar_etab_name !== '' ? ' · ' . $_h($_topbar_etab_name) : '' ?></small>
                    </span>
                </div>
                <div class="tb-list">
                    <a href="<?= $_h($rootPrefix) ?>modules/profil/index.php" class="tb-list__item"><i class="fas fa-user" aria-hidden="true"></i><span><?= $_h($_L['profile']) ?></span></a>
                    <a href="<?= $_h($rootPrefix) ?>parametres/parametres.php" class="tb-list__item"><i class="fas fa-gear" aria-hidden="true"></i><span><?= $_h($_L['settings']) ?></span></a>
                    <?php if ($_tb_isAdmin): ?>
                    <a href="<?= $_h($rootPrefix) ?>admin/dashboard.php" class="tb-list__item"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i><span><?= $_h($_L['admin']) ?></span></a>
                    <?php endif; ?>
                    <?php if ($__nav['support_route']): ?>
                    <a href="<?= $_h($rootPrefix . $__nav['support_route']) ?>" class="tb-list__item"><i class="fas fa-life-ring" aria-hidden="true"></i><span><?= $_h($_L['support']) ?></span></a>
                    <?php endif; ?>
                </div>
                <div class="tb-theme" role="radiogroup" aria-label="<?= $_h($_L['theme']) ?>">
                    <span class="tb-theme__label"><?= $_h($_L['theme']) ?></span>
                    <span class="tb-seg">
                        <button type="button" role="radio" aria-checked="false" data-theme-set="light" title="<?= $_h($_t('nav.theme_light', 'Clair')) ?>"><i class="fas fa-sun" aria-hidden="true"></i><span><?= $_h($_t('nav.theme_light', 'Clair')) ?></span></button>
                        <button type="button" role="radio" aria-checked="false" data-theme-set="dark" title="<?= $_h($_t('nav.theme_dark', 'Sombre')) ?>"><i class="fas fa-moon" aria-hidden="true"></i><span><?= $_h($_t('nav.theme_dark', 'Sombre')) ?></span></button>
                        <button type="button" role="radio" aria-checked="false" data-theme-set="liquid" title="<?= $_h($_t('nav.theme_liquid', 'Liquide')) ?>"><i class="fas fa-droplet" aria-hidden="true"></i><span><?= $_h($_t('nav.theme_liquid', 'Liquide')) ?></span></button>
                        <button type="button" role="radio" aria-checked="false" data-theme-set="auto" title="<?= $_h($_t('nav.theme_auto', 'Comme l\'appareil')) ?>"><i class="fas fa-circle-half-stroke" aria-hidden="true"></i><span><?= $_h($_t('nav.theme_auto_short', 'Auto')) ?></span></button>
                    </span>
                </div>
                <div class="tb-list tb-list--sep">
                    <a href="<?= $_h($rootPrefix) ?>login/logout.php" class="tb-list__item tb-list__item--danger"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span><?= $_h($_L['logout']) ?></span></a>
                </div>
            </div>
        </div>

        <button class="tb-icon tb-burger" id="topbar-hamburger" type="button" aria-label="<?= $_h($_L['menu']) ?>" aria-haspopup="dialog" aria-controls="tb-drawer" aria-expanded="false">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
    </div>
</nav>

<!-- Recherche globale (Ctrl+K) -->
<div class="tb-palette" id="search-modal" role="dialog" aria-modal="true" aria-label="<?= $_h($_L['search']) ?>" hidden>
    <div class="tb-palette__backdrop" data-tb-close></div>
    <div class="tb-palette__box">
        <div class="tb-palette__field">
            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
            <input type="text" id="search-modal-input" role="combobox" aria-expanded="true" aria-controls="search-modal-results" aria-autocomplete="list"
                   placeholder="<?= $_h($_t('nav.palette_placeholder', 'Aller à un module, une page…')) ?>" aria-label="<?= $_h($_L['search']) ?>" autocomplete="off">
            <kbd>Échap</kbd>
        </div>
        <div class="tb-palette__results" id="search-modal-results" role="listbox"></div>
        <div class="tb-palette__foot"><span><kbd>↑</kbd><kbd>↓</kbd> naviguer</span><span><kbd>Entrée</kbd> ouvrir</span></div>
    </div>
</div>

<!-- Tiroir mobile -->
<div class="tb-drawer" id="tb-drawer" role="dialog" aria-modal="true" aria-label="<?= $_h($_t('nav.mobile_menu', 'Menu de navigation')) ?>" hidden>
    <div class="tb-drawer__backdrop" data-tb-close></div>
    <div class="tb-drawer__panel">
        <div class="tb-drawer__head">
            <span class="tb-avatar" aria-hidden="true"><?= $_h($user_initials) ?></span>
            <span class="tb-account__who">
                <strong><?= $_h($user_fullname) ?></strong>
                <small><?= $_h($__nav['role_label']) ?></small>
            </span>
            <button type="button" class="tb-icon" data-tb-close aria-label="<?= $_h($_L['close']) ?>"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="tb-drawer__body">
            <?php if ($__nav['is_parent'] && count($_tb_children) > 1): ?>
            <div class="tb-kids">
                <?php foreach ($_tb_children as $c): $sel = $_tb_child && (int) $c['id'] === (int) $_tb_child['id']; ?>
                <a href="<?= $_h($_childUrl((int) $c['id'])) ?>" class="tb-kid<?= $sel ? ' is-active' : '' ?>"<?= $sel ? ' aria-current="true"' : '' ?>>
                    <span class="tb-chip__av" aria-hidden="true"><?= $_h($_initials($c)) ?></span><?= $_h($c['prenom']) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <label class="tb-filter">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" class="tb-filter__input" placeholder="<?= $_h($_L['filter']) ?>" aria-label="<?= $_h($_L['filter']) ?>" autocomplete="off" data-tb-filter="#tb-drawer">
            </label>
            <div class="tb-tiles" data-tb-group>
                <?= str_replace('tb-link', 'tb-tile', $_renderLink(['module_key' => 'accueil', 'label' => $_L['home'], 'icon' => 'fas fa-house', 'route' => 'accueil/accueil.php', 'type' => 'module'])) ?>
                <span class="tb-tiles__set" id="tb-tiles">
                    <?php foreach ($_tb_shortcuts as $s): ?><?= str_replace('tb-link', 'tb-tile', $_renderLink($s)) ?><?php endforeach; ?>
                </span>
            </div>
            <?php foreach ($_tb_groups as $gk => $group): ?>
            <details class="tb-acc" data-tb-group<?= $gk === $_tb_activeGroup ? ' open' : '' ?>>
                <summary class="tb-acc__head">
                    <i class="<?= $_h($group['icon']) ?>" aria-hidden="true"></i><span><?= $_h($group['label']) ?></span>
                    <small><?= count($group['modules']) ?></small><i class="fas fa-chevron-down tb-trigger__chev" aria-hidden="true"></i>
                </summary>
                <ul class="tb-acc__list">
                    <?php foreach ($group['modules'] as $m): $on = $_isActive($m); ?>
                    <li data-tb-item data-label="<?= $_h($m['label']) ?>">
                        <a href="<?= $_h($_href($m)) ?>" class="tb-list__item<?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                            <i class="<?= $_h($m['icon']) ?>" aria-hidden="true"></i><span><?= $_h($m['label']) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endforeach; ?>
            <p class="tb-empty" hidden><?= $_h($_L['none']) ?></p>
        </div>
    </div>
</div>

<script type="application/json" id="tb-data"><?= json_encode($_tb_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
