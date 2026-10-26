/**
 * Fronote — comportements communs des pages de module (refonte 4.6).
 * Listes très longues : au-delà d'un seuil, on n'affiche que le début et un bouton
 * « Afficher plus » (le contenu complet est déjà dans la page : aucune requête en plus,
 * la recherche du navigateur et l'impression voient tout une fois déplié).
 * Exclus : calendriers, emplois du temps, et tout conteneur marqué data-no-paginate.
 */
(function () {
    'use strict';
    var EXCLUDE = /cal|agenda|month|week|jour|day|edt|timetable|slot|creneau|grid-time|planning|chart|tb-|ep-/i;
    var mk = function (tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt) { e.textContent = txt; } return e; };

    function paginate(container, items, step, insertAfter) {
        if (items.length <= step + Math.ceil(step / 3)) { return; }
        var shown = step;
        items.forEach(function (it, i) { if (i >= shown) { it.hidden = true; it.setAttribute('data-mk-hidden', '1'); } });
        var bar = mk('div', 'mk-more');
        var btn = mk('button', 'btn btn-secondary mk-more__btn');
        btn.type = 'button';
        var all = mk('button', 'mk-more__all');
        all.type = 'button'; all.textContent = 'Tout afficher';
        var label = function () {
            var left = items.length - shown;
            btn.textContent = 'Afficher ' + Math.min(step, left) + ' de plus';
            bar.setAttribute('data-count', shown + ' sur ' + items.length);
        };
        var reveal = function (n) {
            for (var i = shown; i < Math.min(items.length, shown + n); i++) { items[i].hidden = false; items[i].removeAttribute('data-mk-hidden'); }
            shown = Math.min(items.length, shown + n);
            if (shown >= items.length) { bar.remove(); } else { label(); }
        };
        btn.addEventListener('click', function () { reveal(step); });
        all.addEventListener('click', function () { reveal(items.length); });
        bar.appendChild(btn); bar.appendChild(all);
        label();
        insertAfter.parentNode.insertBefore(bar, insertAfter.nextSibling);
    }

    function run() {
        var root = document.getElementById('main-content');
        if (!root) { return; }
        // Tableaux
        Array.prototype.forEach.call(root.querySelectorAll('table'), function (t) {
            if (t.closest('[data-no-paginate]') || EXCLUDE.test(t.className)) { return; }
            var body = t.tBodies[0];
            if (!body) { return; }
            var rows = Array.prototype.filter.call(body.rows, function (r) { return !r.hidden; });
            var wrap = t.closest('.table-responsive, .data-table-container') || t;
            paginate(body, rows, 50, wrap);
        });
        // Listes de cartes / lignes répétées
        var tall = window.innerHeight * 2.5;
        Array.prototype.forEach.call(root.querySelectorAll('div, ul, section'), function (c) {
            if (c.children.length < 36 || c.closest('[data-no-paginate], table, .mk-more') || EXCLUDE.test(c.className) || c.id && EXCLUDE.test(c.id)) { return; }
            var kids = Array.prototype.slice.call(c.children).filter(function (k) { return k.tagName !== 'SCRIPT' && k.tagName !== 'TEMPLATE'; });
            var first = kids[0];
            if (!first) { return; }
            var key = first.tagName + '.' + (first.classList[0] || '');
            var same = kids.filter(function (k) { return k.tagName + '.' + (k.classList[0] || '') === key; });
            if (same.length < 36 || same.length < kids.length * 0.8 || c.scrollHeight < tall) { return; }
            paginate(c, same, 24, c);
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
