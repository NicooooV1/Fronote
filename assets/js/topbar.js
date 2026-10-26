/**
 * Barre de navigation (refonte 4.4) — menus, filtre des modules, recherche Ctrl+K, épinglage,
 * tiroir mobile, thème. ES5, sans dépendance. Markup : templates/shared_topbar_nav.php.
 */
(function () {
    'use strict';

    var doc = document, root = doc.documentElement;
    var nav = doc.getElementById('tb');
    var DATA = {};
    try { DATA = JSON.parse((doc.getElementById('tb-data') || {}).textContent || '{}'); } catch (e) { DATA = {}; }
    var BASE = DATA.base || window.FRONOTE_BASE_URL || './';
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var ANIM_MS = reduceMotion ? 0 : 200;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Recherche insensible aux accents et à la casse (élève → eleve).
    function norm(s) {
        s = String(s || '').toLowerCase();
        if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
        return s;
    }
    function focusables(el) {
        return el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), summary, [tabindex]:not([tabindex="-1"])');
    }
    function trapTab(el, e) {
        if (e.key !== 'Tab') return;
        var f = [].filter.call(focusables(el), function (n) { return n.offsetParent !== null; });
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // ── Calques animés (recherche, tiroir) : hidden ⇄ .is-open avec transition ──────────────
    function showLayer(el) {
        if (!el || el.classList.contains('is-open')) return;
        clearTimeout(el._tbHide);
        el.hidden = false;
        void el.offsetWidth; // force le reflow pour que la transition parte de l'état fermé
        el.classList.add('is-open');
        doc.body.classList.add('tb-locked');
    }
    function hideLayer(el) {
        if (!el || !el.classList.contains('is-open')) return;
        el.classList.remove('is-open');
        el._tbHide = setTimeout(function () { el.hidden = true; }, ANIM_MS);
        if (!doc.querySelector('.tb-palette.is-open, .tb-drawer.is-open')) doc.body.classList.remove('tb-locked');
    }

    // ── Menus déroulants (Modules, compte, enfant) ──────────────────────────────────────────
    var menus = nav ? nav.querySelectorAll('[data-tb-menu]') : [];

    function triggerOf(m) { return m.querySelector('[aria-expanded]'); }

    function placeMega(m) {
        var pop = m.querySelector('.tb-mega');
        if (!pop) return;
        pop.style.left = '';
        var vw = root.clientWidth;
        var r = m.getBoundingClientRect();
        var w = pop.offsetWidth;
        // Aligné sur le bouton, recollé au bord si le panneau déborderait.
        var left = 0;
        if (r.left + w > vw - 12) left = Math.min(0, vw - 12 - w - r.left);
        if (r.left + left < 12) left = 12 - r.left;
        pop.style.left = left + 'px';
    }

    function openMenu(m, how) {
        for (var i = 0; i < menus.length; i++) if (menus[i] !== m) closeMenu(menus[i]);
        clearTimeout(m._tbClose);
        if (m.classList.contains('is-open')) { if (how === 'click') m._tbSticky = true; return; }
        m.classList.add('is-open');
        m._tbSticky = how !== 'hover';
        var t = triggerOf(m);
        if (t) t.setAttribute('aria-expanded', 'true');
        placeMega(m);
        if (how === 'key') {
            var target = m.querySelector('.tb-filter__input') || m.querySelector('.tb-pop a, .tb-pop button');
            if (target) setTimeout(function () { target.focus(); }, 30);
        }
    }
    function closeMenu(m, restoreFocus) {
        if (!m.classList.contains('is-open')) return;
        m.classList.remove('is-open');
        m._tbSticky = false;
        var t = triggerOf(m);
        if (t) {
            t.setAttribute('aria-expanded', 'false');
            if (restoreFocus) t.focus();
        }
        var f = m.querySelector('.tb-filter__input');
        if (f && f.value) { f.value = ''; runFilter(f); }
    }
    function closeAllMenus() { for (var i = 0; i < menus.length; i++) closeMenu(menus[i]); }

    [].forEach.call(menus, function (m) {
        var t = triggerOf(m);
        if (!t) return;
        t.addEventListener('click', function (e) {
            e.preventDefault();
            // Ouvert au survol puis cliqué : on le « fige » ouvert au lieu de le refermer.
            if (m.classList.contains('is-open') && !m._tbSticky && m.hasAttribute('data-tb-hover')) { m._tbSticky = true; return; }
            if (m.classList.contains('is-open')) closeMenu(m);
            else openMenu(m, e.detail === 0 ? 'key' : 'click');
        });
        t.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); openMenu(m, 'key'); }
        });
        if (canHover && m.hasAttribute('data-tb-hover')) {
            m.addEventListener('mouseenter', function () {
                clearTimeout(m._tbClose);
                m._tbOpen = setTimeout(function () { openMenu(m, 'hover'); }, 90);
            });
            m.addEventListener('mouseleave', function () {
                clearTimeout(m._tbOpen);
                if (m._tbSticky) return;
                m._tbClose = setTimeout(function () { closeMenu(m); }, 240);
            });
        }
        m.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && m.classList.contains('is-open')) { e.stopPropagation(); closeMenu(m, true); }
        });
        // Le focus quitte le menu (Tab) → on le referme.
        m.addEventListener('focusout', function (e) {
            if (e.relatedTarget && !m.contains(e.relatedTarget)) closeMenu(m);
        });
    });

    doc.addEventListener('click', function (e) {
        for (var i = 0; i < menus.length; i++) {
            if (menus[i].classList.contains('is-open') && !menus[i].contains(e.target)) closeMenu(menus[i]);
        }
    });
    window.addEventListener('resize', function () {
        for (var i = 0; i < menus.length; i++) if (menus[i].classList.contains('is-open')) placeMega(menus[i]);
    });

    // ── Filtre des modules (panneau Modules + tiroir mobile) ────────────────────────────────
    function runFilter(input) {
        var scope = doc.querySelector(input.getAttribute('data-tb-filter'));
        if (!scope) return;
        var q = norm(input.value.trim());
        var any = false;
        [].forEach.call(scope.querySelectorAll('[data-tb-group]'), function (g) {
            var items = g.querySelectorAll('[data-tb-item]');
            if (!items.length) { g.hidden = q !== ''; return; } // tuiles de raccourcis : masquées pendant un filtre
            var n = 0;
            [].forEach.call(items, function (it) {
                var ok = !q || norm(it.getAttribute('data-label')).indexOf(q) !== -1;
                it.hidden = !ok;
                if (ok) n++;
            });
            g.hidden = n === 0;
            if (g.tagName === 'DETAILS') {
                if (q) { if (!g.hasAttribute('data-was')) g.setAttribute('data-was', g.open ? '1' : '0'); g.open = n > 0; }
                else if (g.hasAttribute('data-was')) { g.open = g.getAttribute('data-was') === '1'; g.removeAttribute('data-was'); }
            }
            if (n) any = true;
        });
        var empty = scope.querySelector('.tb-empty');
        if (empty) empty.hidden = any || !q;
    }
    [].forEach.call(doc.querySelectorAll('[data-tb-filter]'), function (input) {
        input.addEventListener('input', function () { runFilter(input); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var scope = doc.querySelector(input.getAttribute('data-tb-filter'));
                var first = scope && [].filter.call(scope.querySelectorAll('[data-tb-item] a'), function (a) { return a.offsetParent !== null; })[0];
                if (first) { e.preventDefault(); window.location.href = first.href; }
            } else if (e.key === 'ArrowDown') {
                var sc = doc.querySelector(input.getAttribute('data-tb-filter'));
                var f = sc && [].filter.call(sc.querySelectorAll('[data-tb-item] a'), function (a) { return a.offsetParent !== null; })[0];
                if (f) { e.preventDefault(); f.focus(); }
            }
        });
    });

    // ── Recherche globale (Ctrl+K) ──────────────────────────────────────────────────────────
    var pal = doc.getElementById('search-modal');
    var palInput = doc.getElementById('search-modal-input');
    var palList = doc.getElementById('search-modal-results');
    var palBtn = doc.getElementById('topbar-search-btn');
    var palSel = 0, palItems = [], palBack = null;

    var isMac = /Mac|iPhone|iPad/.test(navigator.platform || '');
    [].forEach.call(doc.querySelectorAll('[data-tb-kbd]'), function (k) { k.textContent = isMac ? '⌘ K' : 'Ctrl K'; });

    function moduleUrl(route) { return BASE + route; }
    function entries() {
        var out = [], seen = {};
        function add(o) { if (!o.url || seen[o.url]) return; seen[o.url] = 1; out.push(o); }
        (DATA.favorites || []).forEach(function (f) {
            add({ label: f.label, icon: f.icon, url: f.type === 'page' ? f.route : moduleUrl(f.route), hint: 'Épinglé' });
        });
        (DATA.modules || []).forEach(function (m) { add({ label: m.label, icon: m.icon, url: moduleUrl(m.route), hint: m.group }); });
        (DATA.extra || []).forEach(function (x) { add({ label: x.label, icon: x.icon, url: x.url, hint: 'Compte' }); });
        return out;
    }
    function score(label, q) {
        var l = norm(label);
        if (l.indexOf(q) === 0) return 3;
        if ((' ' + l).indexOf(' ' + q) !== -1) return 2;
        if (l.indexOf(q) !== -1) return 1;
        // Initiales / lettres dans l'ordre (« cdt » → Cahier de textes).
        var i = 0;
        for (var c = 0; c < l.length && i < q.length; c++) if (l.charAt(c) === q.charAt(i)) i++;
        return i === q.length && q.length > 1 ? 0.5 : 0;
    }
    function renderPalette() {
        var q = norm(palInput.value.trim());
        var all = entries();
        var title;
        if (!q) {
            var sc = currentShortcuts();
            palItems = sc.map(function (s) {
                return { label: s.label, icon: s.icon, url: s.type === 'page' ? s.route : moduleUrl(s.route), hint: '' };
            });
            title = (DATA.favorites && DATA.favorites.length) ? 'Vos raccourcis' : 'Accès rapides';
        } else {
            palItems = all.map(function (o) { return { o: o, s: score(o.label, q) }; })
                .filter(function (x) { return x.s > 0; })
                .sort(function (a, b) { return b.s - a.s || a.o.label.localeCompare(b.o.label, 'fr'); })
                .slice(0, 12).map(function (x) { return x.o; });
            title = '';
        }
        palSel = 0;
        if (!palItems.length) {
            palList.innerHTML = '<p class="tb-palette__empty">Aucun résultat pour « ' + esc(palInput.value.trim()) + ' »</p>';
            return;
        }
        var html = title ? '<p class="tb-palette__title">' + esc(title) + '</p>' : '';
        palItems.forEach(function (o, i) {
            html += '<a class="tb-palette__item' + (i === 0 ? ' is-sel' : '') + '" role="option" id="tb-opt-' + i + '" aria-selected="' + (i === 0) + '" href="' + esc(o.url) + '" data-i="' + i + '">'
                + '<span class="tb-palette__ic"><i class="' + esc(o.icon || 'fas fa-circle') + '" aria-hidden="true"></i></span>'
                + '<span class="tb-palette__lbl">' + esc(o.label) + '</span>'
                + (o.hint ? '<span class="tb-palette__hint">' + esc(o.hint) + '</span>' : '')
                + '<i class="fas fa-arrow-right tb-palette__go" aria-hidden="true"></i></a>';
        });
        palList.innerHTML = html;
        palInput.setAttribute('aria-activedescendant', 'tb-opt-0');
    }
    function selectPal(i) {
        var els = palList.querySelectorAll('.tb-palette__item');
        if (!els.length) return;
        palSel = (i + els.length) % els.length;
        for (var k = 0; k < els.length; k++) {
            var on = k === palSel;
            els[k].classList.toggle('is-sel', on);
            els[k].setAttribute('aria-selected', on ? 'true' : 'false');
        }
        els[palSel].scrollIntoView({ block: 'nearest' });
        palInput.setAttribute('aria-activedescendant', 'tb-opt-' + palSel);
    }
    function openPalette() {
        if (!pal) return;
        closeAllMenus();
        closeDrawer();
        palBack = doc.activeElement;
        palInput.value = '';
        renderPalette();
        showLayer(pal);
        setTimeout(function () { palInput.focus(); }, 20);
    }
    function closePalette() {
        if (!pal || !pal.classList.contains('is-open')) return;
        hideLayer(pal);
        if (palBack && palBack.focus) palBack.focus();
        palBack = null;
    }
    if (pal) {
        if (palBtn) palBtn.addEventListener('click', openPalette);
        palInput.addEventListener('input', renderPalette);
        palInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); selectPal(palSel + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); selectPal(palSel - 1); }
            else if (e.key === 'Enter') {
                var it = palItems[palSel];
                if (it) { e.preventDefault(); window.location.href = it.url; }
            }
        });
        palList.addEventListener('mousemove', function (e) {
            var a = e.target.closest('.tb-palette__item');
            if (a && +a.getAttribute('data-i') !== palSel) selectPal(+a.getAttribute('data-i'));
        });
        pal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); closePalette(); return; }
            trapTab(pal, e);
        });
        [].forEach.call(pal.querySelectorAll('[data-tb-close]'), function (b) { b.addEventListener('click', closePalette); });
    }
    doc.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (pal && pal.classList.contains('is-open')) closePalette(); else openPalette();
            return;
        }
        // « / » ouvre la recherche (hors champ de saisie).
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target || {}).tagName || '') && !(e.target || {}).isContentEditable) {
            e.preventDefault();
            openPalette();
            return;
        }
        if (e.key === 'Escape') closeAllMenus();
    });

    // ── Tiroir mobile ───────────────────────────────────────────────────────────────────────
    var drawer = doc.getElementById('tb-drawer');
    var burger = doc.getElementById('topbar-hamburger');
    var drawerBack = null;
    function openDrawer() {
        if (!drawer) return;
        closeAllMenus();
        drawerBack = doc.activeElement;
        showLayer(drawer);
        if (burger) burger.setAttribute('aria-expanded', 'true');
        var cur = drawer.querySelector('.is-active') || drawer.querySelector('.tb-filter__input');
        setTimeout(function () {
            if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'center' });
            var close = drawer.querySelector('[data-tb-close].tb-icon');
            if (close) close.focus({ preventScroll: true });
        }, 40);
    }
    function closeDrawer() {
        if (!drawer || !drawer.classList.contains('is-open')) return;
        hideLayer(drawer);
        if (burger) burger.setAttribute('aria-expanded', 'false');
        if (drawerBack && drawerBack.focus) drawerBack.focus();
        drawerBack = null;
    }
    if (drawer) {
        if (burger) burger.addEventListener('click', openDrawer);
        [].forEach.call(drawer.querySelectorAll('[data-tb-close]'), function (b) { b.addEventListener('click', closeDrawer); });
        drawer.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeDrawer(); return; }
            trapTab(drawer, e);
        });
        // Balayage vers la gauche pour refermer.
        var x0 = null;
        drawer.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
        drawer.addEventListener('touchend', function (e) {
            if (x0 !== null && x0 - e.changedTouches[0].clientX > 70) closeDrawer();
            x0 = null;
        }, { passive: true });
        window.addEventListener('resize', function () { if (window.innerWidth > 1023) closeDrawer(); });
    }

    // ── Thème (source de vérité unique, aussi utilisée par les Paramètres) ─────────────────
    var THEMES = ['light', 'dark', 'liquid'];
    function resolveTheme(pref) {
        if (pref === 'auto' || !pref) {
            return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        return THEMES.indexOf(pref) >= 0 ? pref : 'light';
    }
    function storedPref() {
        try { return localStorage.getItem('fronote_dark_mode'); } catch (e) { return null; }
    }
    function syncThemeButtons() {
        var pref = root.getAttribute('data-theme-pref') || storedPref() || root.getAttribute('data-theme') || 'light';
        [].forEach.call(doc.querySelectorAll('[data-theme-set]'), function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === pref ? 'true' : 'false');
        });
    }
    window.FronoteUI = window.FronoteUI || {};
    window.FronoteUI.setTheme = function (pref) {
        root.setAttribute('data-theme', resolveTheme(pref));
        root.setAttribute('data-theme-pref', pref);
        try { localStorage.setItem('fronote_dark_mode', pref); } catch (e) {}
        syncThemeButtons();
    };
    window.FronoteUI.cycleTheme = function () {
        var pref = storedPref() || root.getAttribute('data-theme-pref') || root.getAttribute('data-theme') || 'light';
        var cur = THEMES.indexOf(pref) >= 0 ? pref : resolveTheme(pref);
        window.FronoteUI.setTheme(THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length]);
    };
    [].forEach.call(doc.querySelectorAll('[data-theme-set]'), function (b) {
        b.addEventListener('click', function () {
            var apply = function () { window.FronoteUI.setTheme(b.getAttribute('data-theme-set')); };
            // Fondu doux entre thèmes quand le navigateur sait le faire.
            if (doc.startViewTransition && !reduceMotion) doc.startViewTransition(apply); else apply();
        });
    });
    syncThemeButtons();
    new MutationObserver(syncThemeButtons).observe(root, { attributes: true, attributeFilter: ['data-theme', 'data-theme-pref'] });

    // ── Épinglage : ★ ajoute / retire un module de la barre, sans recharger ────────────────
    function csrf() { var m = doc.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function favPost(body) {
        body.csrf_token = csrf();
        return fetch(BASE + 'API/endpoints/favorites.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            var next = r.headers.get('X-Csrf-Token-Next');
            if (next) { var m = doc.querySelector('meta[name="csrf-token"]'); if (m) m.setAttribute('content', next); }
            return r.json();
        });
    }
    function currentShortcuts() {
        var keys = {};
        (DATA.modules || []).forEach(function (m) { keys[m.key] = 1; });
        var favs = (DATA.favorites || []).filter(function (f) { return f.type === 'page' || keys[f.module_key]; });
        return favs.length ? favs : (DATA.defaults || []);
    }
    function linkHtml(s, cls, isNew) {
        var url = s.type === 'page' ? s.route : moduleUrl(s.route);
        var on = s.type === 'page' ? (url.split('?')[0] === location.pathname) : (s.module_key === DATA.active);
        return '<a href="' + esc(url) + '" class="' + cls + (on ? ' is-active' : '') + (isNew ? ' is-new' : '') + '"' + (on ? ' aria-current="page"' : '')
            + ' data-key="' + esc(s.module_key) + '"><i class="' + esc(s.icon || 'fas fa-circle') + '" aria-hidden="true"></i><span>' + esc(s.label) + '</span></a>';
    }
    function renderShortcuts(newKey) {
        var list = currentShortcuts();
        var bar = doc.getElementById('tb-shortcuts'), tiles = doc.getElementById('tb-tiles');
        if (bar) bar.innerHTML = list.map(function (s) { return linkHtml(s, 'tb-link', s.module_key === newKey); }).join('');
        if (tiles) tiles.innerHTML = list.map(function (s) { return linkHtml(s, 'tb-tile', false); }).join('');
        // Lien « contexte » (page courante hors barre) : inutile si elle vient d'y entrer.
        var ctx = nav && nav.querySelector('.tb-link--context');
        if (ctx) ctx.hidden = list.some(function (s) { return s.module_key === DATA.active && s.type !== 'page'; });
    }
    function syncPins(key, on) {
        [].forEach.call(doc.querySelectorAll('[data-tb-pin="' + key + '"]'), function (b) {
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            var txt = on ? 'Retirer de la barre' : 'Épingler dans la barre';
            b.title = txt;
            var lbl = b.getAttribute('aria-label') || '';
            b.setAttribute('aria-label', txt + (lbl.indexOf(' : ') > -1 ? lbl.slice(lbl.indexOf(' : ')) : ''));
            var i = b.querySelector('i');
            if (i) { i.classList.toggle('fas', on); i.classList.toggle('far', !on); }
            if (on && !reduceMotion) { b.classList.remove('is-pop'); void b.offsetWidth; b.classList.add('is-pop'); }
        });
    }
    doc.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tb-pin]');
        if (!b) return;
        e.preventDefault();
        e.stopPropagation();
        if (b.disabled) return;
        var key = b.getAttribute('data-tb-pin');
        var wasOn = b.classList.contains('is-on');
        syncPins(key, !wasOn); // réponse immédiate, corrigée si le serveur refuse
        b.disabled = true;
        favPost({ action: 'toggle', module_key: key }).then(function (d) {
            if (!d || !d.success) throw new Error('refus');
            DATA.favorites = d.favorites || [];
            syncPins(key, !!d.favorite);
            renderShortcuts(d.favorite ? key : null);
        }).catch(function () {
            syncPins(key, wasOn);
        }).then(function () { b.disabled = false; });
    });

    // Compat : anciennes actions déléguées encore référencées par des pages tierces.
    window.fronoteFavRemove = function (key) {
        favPost({ action: 'remove', key: key }).then(function (d) {
            if (d && d.success) { DATA.favorites = d.favorites || []; renderShortcuts(); }
        }).catch(function () {});
    };
    window.fronotePinCurrentPage = function () {
        favPost({ action: 'add_page', url: location.pathname + location.search, label: (doc.title || '').slice(0, 150) }).then(function (d) {
            if (d && d.success) { DATA.favorites = d.favorites || []; renderShortcuts(); }
        }).catch(function () {});
    };
})();
