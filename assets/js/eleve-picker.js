/**
 * Fronote — sélecteur d'ÉLÈVE unique pour toute l'application.
 *
 * 1. Liste déroulante existante : tout <select> d'élève (name="eleve_id" | "id_eleve" | "eleve",
 *    ou attribut data-eleve-picker) devient un champ « tapez pour chercher ». Le <select> natif
 *    reste la source de vérité (soumission, required, événements change) : rien à changer côté
 *    serveur. Désactiver : data-no-picker.
 * 2. Recherche distante : <input type="hidden" name="eleve_id" data-eleve-search> → interroge
 *    API/endpoints/eleves.php (périmètre selon le rôle). Option data-classe="4A".
 *
 * Recherche insensible aux accents et à la casse, plusieurs mots (« lea mar », « 4a martin »),
 * clavier (↑ ↓ Entrée Échap), lecteurs d'écran (combobox ARIA).
 */
(function () {
    'use strict';
    var NAMES = ['eleve_id', 'id_eleve', 'eleve'];
    var MAX = 60;
    var uid = 0;

    function norm(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function base() {
        return (window.FRONOTE_BASE_URL || '/').replace(/\/?$/, '/');
    }

    /** Construit l'interface commune ; source(query, cb) fournit [{value,label,group}] */
    function build(anchor, opts) {
        var id = 'ep-' + (++uid);
        var wrap = document.createElement('div');
        wrap.className = 'ep';
        wrap.innerHTML =
            '<div class="ep-field"><i class="fas fa-magnifying-glass ep-ico" aria-hidden="true"></i>' +
            '<input type="text" class="ep-input form-control" role="combobox" aria-autocomplete="list" aria-expanded="false" ' +
            'aria-controls="' + id + '-list" autocomplete="off" spellcheck="false">' +
            '<button type="button" class="ep-clear" aria-label="Effacer" hidden><i class="fas fa-xmark" aria-hidden="true"></i></button></div>' +
            '<ul class="ep-list" id="' + id + '-list" role="listbox" hidden></ul>';
        anchor.parentNode.insertBefore(wrap, anchor);
        var input = wrap.querySelector('.ep-input');
        var list = wrap.querySelector('.ep-list');
        var clear = wrap.querySelector('.ep-clear');
        input.placeholder = opts.placeholder || 'Rechercher un élève : nom, prénom ou classe…';
        if (opts.labelledBy) { input.setAttribute('aria-labelledby', opts.labelledBy); }
        if (opts.disabled) { input.disabled = true; }

        var items = [], active = -1, timer = null, seq = 0;

        function open(b) {
            list.hidden = !b;
            input.setAttribute('aria-expanded', b ? 'true' : 'false');
        }
        function render(rows, total, q) {
            items = rows;
            active = rows.length ? 0 : -1;
            if (!rows.length) {
                list.innerHTML = '<li class="ep-empty">' + (q ? 'Aucun élève ne correspond à « ' + esc(q) + ' »' : 'Tapez au moins une lettre') + '</li>';
            } else {
                var html = '', lastGroup = null;
                rows.forEach(function (r, i) {
                    if (r.group && r.group !== lastGroup) { html += '<li class="ep-group" role="presentation">' + esc(r.group) + '</li>'; lastGroup = r.group; }
                    html += '<li class="ep-opt' + (i === 0 ? ' is-active' : '') + '" role="option" id="' + id + '-o' + i + '" data-i="' + i + '">' +
                        '<span class="ep-name">' + esc(r.name || r.label) + '</span>' + (r.classe ? '<span class="ep-cls">' + esc(r.classe) + '</span>' : '') + '</li>';
                });
                if (total > rows.length) { html += '<li class="ep-more">' + (total - rows.length) + ' autres résultats — précisez la recherche</li>'; }
                list.innerHTML = html;
                input.setAttribute('aria-activedescendant', id + '-o0');
            }
            open(true);
        }
        function move(d) {
            if (!items.length) { return; }
            var els = list.querySelectorAll('.ep-opt');
            if (els[active]) { els[active].classList.remove('is-active'); }
            active = (active + d + items.length) % items.length;
            els[active].classList.add('is-active');
            els[active].scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', id + '-o' + active);
        }
        function choose(i) {
            var r = items[i];
            if (!r) { return; }
            input.value = r.label;
            clear.hidden = false;
            open(false);
            opts.onPick(r);
        }
        function query() {
            var q = input.value.trim();
            var my = ++seq;
            opts.source(q, function (rows, total) { if (my === seq) { render(rows, total, q); } });
        }

        input.addEventListener('input', function () {
            clear.hidden = input.value === '';
            if (input.value === '') { opts.onPick(null); }
            clearTimeout(timer);
            timer = setTimeout(query, opts.remote ? 200 : 0);
        });
        input.addEventListener('focus', function () { if (!opts.remote || input.value) { query(); } });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { query(); } else { move(1); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); } }
            else if (e.key === 'Escape') { open(false); }
        });
        list.addEventListener('mousedown', function (e) { e.preventDefault(); }); // garder le focus
        list.addEventListener('click', function (e) {
            var li = e.target.closest('.ep-opt');
            if (li) { choose(+li.getAttribute('data-i')); }
        });
        clear.addEventListener('click', function () {
            input.value = ''; clear.hidden = true; opts.onPick(null); input.focus();
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) { open(false); } });

        return {
            setLabel: function (l) { input.value = l || ''; clear.hidden = !l; },
            input: input
        };
    }

    /** Mode 1 : enrichit un <select> existant (filtrage local, instantané). */
    function enhanceSelect(sel) {
        if (sel.dataset.epDone || sel.hasAttribute('data-no-picker') || sel.multiple) { return; }
        var options = [];
        Array.prototype.forEach.call(sel.options, function (o) {
            if (!o.value) { return; }
            var g = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label : '';
            var text = o.textContent.replace(/\s+/g, ' ').trim();
            var m = text.match(/^(.*?)\s*[(—–-]\s*([0-9]{1,2}\s?[A-Za-zÀ-ÿ0-9]*)\)?\s*$/);
            options.push({ value: o.value, label: text, name: m ? m[1] : text, classe: m ? m[2] : (g || ''), group: g, key: ' ' + norm(text + ' ' + g).replace(/[-'’()]/g, ' ') + ' ' });
        });
        if (options.length < 8 && !sel.hasAttribute('data-eleve-picker')) { return; } // petite liste : le menu natif suffit
        sel.dataset.epDone = '1';
        sel.classList.add('ep-native');
        sel.setAttribute('tabindex', '-1');
        sel.setAttribute('aria-hidden', 'true');
        var lab = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
        if (lab && !lab.id) { lab.id = 'ep-lab-' + (++uid); }
        var ui = build(sel, {
            labelledBy: lab ? lab.id : null,
            disabled: sel.disabled,
            source: function (q, cb) {
                var toks = norm(q).split(' ').filter(Boolean);
                var rows = toks.length ? options.filter(function (o) { /* début de mot */ return toks.every(function (t) { return o.key.indexOf(' ' + t) !== -1; }); }) : options;
                cb(rows.slice(0, MAX), rows.length);
            },
            onPick: function (r) {
                var v = r ? r.value : '';
                if (sel.value === v) { return; }
                sel.value = v;
                sel.dispatchEvent(new Event('input', { bubbles: true }));
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        var cur = sel.options[sel.selectedIndex];
        if (cur && cur.value) { ui.setLabel(cur.textContent.replace(/\s+/g, ' ').trim()); }
        // Champ requis non rempli : renvoyer l'utilisateur vers le champ visible
        sel.addEventListener('invalid', function () { ui.input.focus(); });
    }

    /** Mode 2 : recherche distante (input caché data-eleve-search). */
    function enhanceRemote(hidden) {
        if (hidden.dataset.epDone) { return; }
        hidden.dataset.epDone = '1';
        var cache = {};
        var ui = build(hidden, {
            remote: true,
            placeholder: hidden.getAttribute('data-placeholder'),
            source: function (q, cb) {
                if (!q) { cb([], 0); return; }
                var url = base() + 'API/endpoints/eleves.php?limit=30&q=' + encodeURIComponent(q) +
                    (hidden.dataset.classe ? '&classe=' + encodeURIComponent(hidden.dataset.classe) : '');
                if (cache[url]) { cb(cache[url], cache[url].length); return; }
                fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var rows = (d.eleves || []).map(function (e) {
                            return { value: String(e.id), label: e.label, name: e.prenom + ' ' + e.nom, classe: e.classe };
                        });
                        cache[url] = rows; cb(rows, rows.length);
                    })
                    .catch(function () { cb([], 0); });
            },
            onPick: function (r) {
                hidden.value = r ? r.value : '';
                hidden.dispatchEvent(new CustomEvent('eleve:change', { bubbles: true, detail: r }));
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        if (hidden.dataset.label) { ui.setLabel(hidden.dataset.label); }
        var form = hidden.form;
        if (form && hidden.hasAttribute('data-required')) {
            form.addEventListener('submit', function (e) {
                if (!hidden.value) { e.preventDefault(); ui.input.focus(); ui.input.setCustomValidity('Choisissez un élève dans la liste.'); ui.input.reportValidity(); }
            });
            ui.input.addEventListener('input', function () { ui.input.setCustomValidity(''); });
        }
    }

    function enhance(root) {
        root = root || document;
        var sel = NAMES.map(function (n) { return 'select[name="' + n + '"]'; }).join(',') + ',select[data-eleve-picker]';
        Array.prototype.forEach.call(root.querySelectorAll(sel), enhanceSelect);
        Array.prototype.forEach.call(root.querySelectorAll('input[data-eleve-search]'), enhanceRemote);
    }

    window.FronoteElevePicker = { enhance: enhance };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { enhance(); }); }
    else { enhance(); }
})();
