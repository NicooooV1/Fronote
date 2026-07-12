/**
 * Topbar navigation — dropdowns, search modal, mobile panel, theme toggle.
 * ES5 compatible.
 */
(function () {
    'use strict';

    // ── Dropdown toggle ─────────────────────────────────────────
    // Le menu utilise position:fixed pour échapper au clipping de
    // .topbar-categories { overflow-x: auto }. On positionne donc top/left
    // dynamiquement à partir du bounding rect du trigger.
    function positionMenu(dropdown) {
        var trigger = dropdown.querySelector('.topbar-dropdown__trigger, .topbar-user-avatar');
        var menu = dropdown.querySelector('.topbar-dropdown__menu');
        if (!trigger || !menu) return;
        var rect = trigger.getBoundingClientRect();
        var alignRight = menu.classList.contains('topbar-dropdown__menu--right');
        var menuWidth = menu.offsetWidth || 220;
        var top = rect.bottom + 4;
        var left;
        if (alignRight) {
            left = Math.max(8, rect.right - menuWidth);
        } else {
            left = rect.left;
            // Si le menu déborderait à droite, le recoller
            var vw = window.innerWidth || document.documentElement.clientWidth;
            if (left + menuWidth + 8 > vw) {
                left = Math.max(8, vw - menuWidth - 8);
            }
        }
        menu.style.top = top + 'px';
        menu.style.left = left + 'px';
    }

    function closeAllDropdowns() {
        var dds = document.querySelectorAll('.topbar-dropdown.open');
        for (var i = 0; i < dds.length; i++) {
            dds[i].classList.remove('open');
            var t = dds[i].querySelector('.topbar-dropdown__trigger, .topbar-user-avatar');
            if (t) t.setAttribute('aria-expanded', 'false');
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.topbar-dropdown__trigger, .topbar-user-avatar');

        if (trigger) {
            var dropdown = trigger.closest('.topbar-dropdown');
            if (!dropdown) return;
            var wasOpen = dropdown.classList.contains('open');
            closeAllDropdowns();
            if (!wasOpen) {
                dropdown.classList.add('open');
                trigger.setAttribute('aria-expanded', 'true');
                // Position après l'ajout de .open (le menu doit être display:block pour offsetWidth correct)
                positionMenu(dropdown);
            }
            e.stopPropagation();
            return;
        }

        // Click hors d'un menu ouvert ferme tout
        if (!e.target.closest('.topbar-dropdown__menu')) {
            closeAllDropdowns();
        }
    });

    // Repositionner les dropdowns ouverts au scroll/resize
    function repositionOpen() {
        var open = document.querySelectorAll('.topbar-dropdown.open');
        for (var i = 0; i < open.length; i++) {
            positionMenu(open[i]);
        }
    }
    window.addEventListener('resize', repositionOpen);
    window.addEventListener('scroll', repositionOpen, true);

    // Esc ferme tout
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    // ── Search modal (Ctrl+K) ───────────────────────────────────
    var searchModal = document.getElementById('search-modal');
    var searchInput = document.getElementById('search-modal-input');
    var searchResults = document.getElementById('search-modal-results');
    var searchBtn = document.getElementById('topbar-search-btn');
    var searchSelected = -1; // index du résultat surligné (navigation clavier)

    // Normalise pour une recherche insensible aux accents/casse (élève -> eleve)
    function normalize(s) {
        s = (s || '').toLowerCase();
        if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
        return s;
    }

    // Accessibilité des boîtes de dialogue : éléments focusables + piège de focus (Tab bouclé) +
    // restauration du focus sur le déclencheur à la fermeture. Partagé modale de recherche / panneau mobile.
    function focusablesIn(container) {
        return container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    }
    function trapTab(container, e) {
        if (e.key !== 'Tab') return;
        var f = focusablesIn(container);
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    var lastFocusBeforeSearch = null;
    function openSearch() {
        if (!searchModal) return;
        lastFocusBeforeSearch = document.activeElement;
        searchModal.classList.add('open');
        searchModal.setAttribute('aria-hidden', 'false');
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        if (searchResults) searchResults.innerHTML = '';
    }

    function closeSearch() {
        if (!searchModal) return;
        searchModal.classList.remove('open');
        searchModal.setAttribute('aria-hidden', 'true');
        // Restaure le focus sur le déclencheur (dialog accessible : pas de focus perdu sur <body>).
        var back = lastFocusBeforeSearch || searchBtn;
        if (back && back.focus) back.focus();
        lastFocusBeforeSearch = null;
    }

    if (searchBtn) {
        searchBtn.addEventListener('click', openSearch);
    }

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openSearch();
        }
        if (e.key === 'Escape' && searchModal && searchModal.classList.contains('open')) {
            closeSearch();
        }
    });

    if (searchModal) {
        var backdrop = searchModal.querySelector('.search-modal__backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closeSearch);
        }
        // Piège de focus tant que la modale est ouverte (Tab ne sort plus vers l'arrière-plan).
        searchModal.addEventListener('keydown', function (e) {
            if (searchModal.classList.contains('open')) trapTab(searchModal, e);
        });
    }

    // Met à jour le surlignage du résultat sélectionné au clavier
    function updateSearchSelection() {
        if (!searchResults) return;
        var results = searchResults.querySelectorAll('.search-result-item');
        for (var i = 0; i < results.length; i++) {
            if (i === searchSelected) {
                results[i].classList.add('highlighted');
                results[i].scrollIntoView({ block: 'nearest' });
            } else {
                results[i].classList.remove('highlighted');
            }
        }
    }

    // Search: filter modules from the topbar (insensible aux accents)
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var query = normalize(this.value.trim());
            if (!searchResults) return;

            searchSelected = -1;

            if (!query) {
                searchResults.innerHTML = '';
                return;
            }

            var items = document.querySelectorAll('.topbar-dropdown__item');
            var matches = [];

            for (var i = 0; i < items.length; i++) {
                if (normalize(items[i].textContent.trim()).indexOf(query) !== -1) {
                    matches.push(items[i]);
                }
            }

            // Also search mobile links
            var mobileLinks = document.querySelectorAll('.topbar-mobile-link');
            for (var m = 0; m < mobileLinks.length; m++) {
                if (normalize(mobileLinks[m].textContent.trim()).indexOf(query) !== -1) {
                    var isDup = false;
                    var href = mobileLinks[m].getAttribute('href');
                    for (var d = 0; d < matches.length; d++) {
                        if (matches[d].getAttribute('href') === href) { isDup = true; break; }
                    }
                    if (!isDup) matches.push(mobileLinks[m]);
                }
            }

            var html = '';
            for (var r = 0; r < Math.min(matches.length, 10); r++) {
                var el = matches[r];
                var icon = el.querySelector('i');
                var iconClass = icon ? icon.className : 'fas fa-circle';
                var label = el.textContent.trim();
                var link = el.getAttribute('href') || '#';
                html += '<a class="search-result-item" href="' + link + '">'
                      + '<i class="' + iconClass + '"></i>'
                      + '<span>' + label + '</span></a>';
            }

            searchResults.innerHTML = html || '<div style="padding:1rem;color:#a0aec0;text-align:center;">Aucun resultat</div>';
        });

        // Navigation clavier dans les résultats : flèches + Entrée
        searchInput.addEventListener('keydown', function (e) {
            if (!searchResults) return;
            var results = searchResults.querySelectorAll('.search-result-item');
            if (!results.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                searchSelected = (searchSelected + 1) % results.length;
                updateSearchSelection();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                searchSelected = (searchSelected - 1 + results.length) % results.length;
                updateSearchSelection();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var target = searchSelected >= 0 ? results[searchSelected] : results[0];
                if (target) window.location.href = target.getAttribute('href');
            }
        });
    }

    // ── Mobile panel ──────────────────────────────────────────��─
    var hamburger = document.getElementById('topbar-hamburger');
    var mobilePanel = document.getElementById('topbar-mobile-panel');
    var mobileClose = document.getElementById('topbar-mobile-close');

    var lastFocusBeforeMobile = null;
    function openMobilePanel() {
        if (!mobilePanel) return;
        lastFocusBeforeMobile = document.activeElement;
        mobilePanel.classList.add('open');
        if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
        var f = focusablesIn(mobilePanel);
        if (f.length) f[0].focus();
    }
    function closeMobilePanel() {
        if (!mobilePanel || !mobilePanel.classList.contains('open')) return;
        mobilePanel.classList.remove('open');
        if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
        var back = lastFocusBeforeMobile || hamburger;
        if (back && back.focus) back.focus();
        lastFocusBeforeMobile = null;
    }

    if (hamburger && mobilePanel) {
        hamburger.addEventListener('click', function (e) { e.stopPropagation(); openMobilePanel(); });
    }

    if (mobileClose && mobilePanel) {
        mobileClose.addEventListener('click', closeMobilePanel);
    }

    // Esc ferme le panneau + piège de focus (dialog mobile accessible)
    if (mobilePanel) {
        mobilePanel.addEventListener('keydown', function (e) {
            if (!mobilePanel.classList.contains('open')) return;
            if (e.key === 'Escape') { closeMobilePanel(); return; }
            trapTab(mobilePanel, e);
        });
    }

    // Close mobile panel on outside click
    document.addEventListener('click', function (e) {
        if (mobilePanel && mobilePanel.classList.contains('open')) {
            if (!mobilePanel.contains(e.target) && e.target !== hamburger && !hamburger.contains(e.target)) {
                closeMobilePanel();
            }
        }
    });

    // ── Theme toggle ────────────────────────────────────────────
    var themeToggle = document.getElementById('topbar-theme-toggle');
    var iconLight = document.getElementById('theme-icon-light');
    var iconDark = document.getElementById('theme-icon-dark');

    function updateThemeIcons() {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (iconLight) iconLight.style.display = isDark ? 'none' : '';
        if (iconDark) iconDark.style.display = isDark ? '' : 'none';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('fronote_dark_mode', next); } catch (e) {}
            updateThemeIcons();
        });
    }

    updateThemeIcons();

    // Watch for external theme changes (e.g., from sidebar toggle)
    var observer = new MutationObserver(updateThemeIcons);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    // ── Favoris (épingler des modules) ──────────────────────────
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function favEndpoint() {
        var base = window.FRONOTE_BASE_URL || './';
        return base + 'API/endpoints/favorites.php';
    }

    // Reflète l'état favori sur tous les boutons d'une même clé module
    function syncFavButtons(moduleKey, isFav) {
        var btns = document.querySelectorAll('.topbar-fav-toggle[data-module-key="' + moduleKey + '"]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.toggle('is-favorite', isFav);
            btns[i].setAttribute('aria-pressed', isFav ? 'true' : 'false');
            var icon = btns[i].querySelector('i');
            if (icon) {
                icon.classList.toggle('fas', isFav);
                icon.classList.toggle('far', !isFav);
            }
        }
    }

    // Reconstruit le menu Favoris depuis la liste renvoyée par l'API
    function rebuildFavoritesMenu(favorites) {
        var menu = document.getElementById('topbar-favorites-menu');
        if (!menu) return;
        var base = window.FRONOTE_BASE_URL || './';

        if (!favorites || !favorites.length) {
            menu.innerHTML = '<div class="topbar-dropdown__empty">Aucun favori. Cliquez ★ sur un module.</div>';
            return;
        }

        var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        var html = '';
        for (var i = 0; i < favorites.length; i++) {
            var f = favorites[i];
            var isPage = f.type === 'page';
            var href = isPage ? esc(f.route) : (base + esc(f.route));
            // CSP stricte : pas d'on*= inline. On génère le même markup délégué que le rendu
            // serveur (shared_topbar_nav.php) — data-fr-click résolu par csp-actions.js.
            var removeBtn = isPage
                ? '<button class="topbar-fav-toggle is-favorite" type="button" title="Retirer des favoris"'
                  + ' data-fr-click="fronoteFavRemove" data-fr-args=\'["' + esc(f.module_key) + '"]\'><i class="fas fa-star"></i></button>'
                : '<button class="topbar-fav-toggle is-favorite" type="button" data-module-key="' + esc(f.module_key) + '"'
                  + ' title="Retirer des favoris" aria-pressed="true"><i class="fas fa-star"></i></button>';
            html += '<div class="topbar-dropdown__item-wrap">'
                  + '<a href="' + href + '" class="topbar-dropdown__item">'
                  + '<i class="' + esc(f.icon || 'fas fa-circle') + '"></i>'
                  + '<span>' + esc(f.label) + '</span></a>'
                  + removeBtn
                  + '</div>';
        }
        menu.innerHTML = html;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.topbar-fav-toggle');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();

        var moduleKey = btn.getAttribute('data-module-key');
        if (!moduleKey || btn.disabled) return;
        btn.disabled = true;

        fetch(favEndpoint(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'toggle', module_key: moduleKey, csrf_token: csrfToken() })
        })
        .then(function (r) {
            var next = r.headers.get('X-Csrf-Token-Next');
            if (next) { var m = document.querySelector('meta[name="csrf-token"]'); if (m) m.setAttribute('content', next); }
            return r.json();
        })
        .then(function (data) {
            if (data && data.success) {
                syncFavButtons(moduleKey, !!data.favorite);
                rebuildFavoritesMenu(data.favorites);
            }
        })
        .catch(function () { /* silencieux : favori non bloquant */ })
        .then(function () { btn.disabled = false; });
    });
})();
