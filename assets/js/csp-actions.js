/**
 * csp-actions.js — Pont d'actions délégué compatible CSP stricte (SANS eval).
 *
 * Permet de supprimer 'unsafe-inline' de script-src : les anciens gestionnaires
 * inline (onclick="foo(1)", onchange="bar(this.value)", onsubmit="return v()"...)
 * sont convertis en attributs data-fr-* et exécutés ici via addEventListener
 * délégué. On n'exécute JAMAIS de chaîne de code : seulement des fonctions nommées
 * (helpers intégrés ci-dessous, ou fonctions globales déjà définies par la page).
 *
 * Conventions de conversion :
 *   onclick="foo(1,'x')"                -> data-fr-click="foo" data-fr-args='[1,"x"]'
 *   onclick="foo(1); return false;"     -> data-fr-click="foo" data-fr-args='[1]' data-fr-prevent="1"
 *   onchange="bar(this.value)"          -> data-fr-change="bar" data-fr-pass="value"
 *   onchange="bar(this)"                -> data-fr-change="bar" data-fr-pass="this"
 *   onsubmit="return validate()"        -> data-fr-submit="validate"
 *   onsubmit="return confirm('Sûr ?')"  -> data-fr-confirm="Sûr ?"        (sur le <form>)
 *   onclick="return confirm('Sûr ?')"   -> data-fr-confirm="Sûr ?"        (sur <a>/<button>)
 *   onclick="document.getElementById('m').style.display='flex'" -> data-fr-click="showFlex" data-fr-args='["m"]'
 *   onclick="location.href='u'"         -> data-fr-click="href" data-fr-args='["u"]'
 *
 * La fonction reçoit : (...args, el, event). data-fr-pass="value|checked|this"
 * injecte respectivement el.value / el.checked / el en TÊTE des arguments.
 */
(function () {
    'use strict';
    var FR = (window.FR = window.FR || {});
    FR.actions = FR.actions || {};
    FR.register = function (name, fn) { FR.actions[name] = fn; };

    var byId = function (id) { return document.getElementById(id); };

    // Helpers intégrés (remplacent les expressions inline courantes).
    var A = FR.actions;
    A.show      = function (id) { var e = byId(id); if (e) e.style.display = ''; };
    A.showBlock = function (id) { var e = byId(id); if (e) e.style.display = 'block'; };
    A.showFlex  = function (id) { var e = byId(id); if (e) e.style.display = 'flex'; };
    A.showInline = function (id) { var e = byId(id); if (e) e.style.display = 'inline-block'; };
    A.hide      = function (id) { var e = byId(id); if (e) e.style.display = 'none'; };
    A.toggle    = function (id) { var e = byId(id); if (e) e.style.display = (e.style.display === 'none' ? '' : 'none'); };
    A.toggleFlex = function (id) { var e = byId(id); if (e) e.style.display = (e.style.display === 'none' || !e.style.display ? 'flex' : 'none'); };
    A.toggleBlock = function (id) { var e = byId(id); if (e) e.style.display = (e.style.display === 'none' || !e.style.display ? 'block' : 'none'); };
    A.toggleClass = function (sel, cls) { var e = typeof sel === 'string' ? (byId(sel) || document.querySelector(sel)) : sel; if (e) e.classList.toggle(cls || 'active'); };
    A.addClass  = function (sel, cls) { var e = typeof sel === 'string' ? (byId(sel) || document.querySelector(sel)) : sel; if (e) e.classList.add(cls); };
    A.removeClass = function (sel, cls) { var e = typeof sel === 'string' ? (byId(sel) || document.querySelector(sel)) : sel; if (e) e.classList.remove(cls); };
    A.href      = function (url) { window.location.href = url; };
    A.openBlank = function (url) { window.open(url, '_blank', 'noopener'); };
    A.reload    = function () { window.location.reload(); };
    A.back      = function () { window.history.back(); };
    A.print     = function () { window.print(); };
    A.submitForm = function (id) { var f = byId(id); if (f) f.submit(); };
    A.setValue  = function (id, val) { var e = byId(id); if (e) e.value = val; };
    A.setText   = function (id, text) { var e = byId(id); if (e) e.textContent = text; };
    A.focus     = function (id) { var e = byId(id); if (e) e.focus(); };
    A.check     = function (id) { var e = byId(id); if (e) e.checked = true; };
    A.uncheck   = function (id) { var e = byId(id); if (e) e.checked = false; };
    A.scrollTop = function () { window.scrollTo(0, 0); };
    A.noop      = function () {};
    // Soumet le formulaire contenant l'élément déclencheur (reçu en avant-dernier argument).
    A.submitOwn = function () {
        var el = arguments[arguments.length - 2];
        var f = el && el.form ? el.form : (el && el.closest ? el.closest('form') : null);
        if (f) f.submit();
    };
    // Coche/décoche toutes les cases d'un conteneur (id) selon l'état de la case déclencheur.
    A.checkAll = function (containerId) {
        var el = arguments[arguments.length - 2];
        var c = byId(containerId); if (!c) return;
        var boxes = c.querySelectorAll('input[type=checkbox]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = el.checked; }
    };

    function resolve(name) {
        if (Object.prototype.hasOwnProperty.call(A, name)) return A[name];
        if (typeof window[name] === 'function') return window[name];
        return null;
    }

    function dispatch(el, kind, ev) {
        var name = el.getAttribute('data-fr-' + kind);
        if (!name) return;
        var fn = resolve(name);
        if (!fn) { if (window.console) console.warn('[csp-actions] action inconnue:', name); return; }

        var args = [];
        var raw = el.getAttribute('data-fr-args');
        if (raw) {
            try { args = JSON.parse(raw); if (!Array.isArray(args)) args = [args]; }
            catch (e) { args = [raw]; }
        }
        // Injection de valeur dynamique (remplace this.value / this.checked / this).
        var pass = el.getAttribute('data-fr-pass');
        if (pass === 'value')   args.unshift(el.value);
        else if (pass === 'checked') args.unshift(el.checked);
        else if (pass === 'this')    args.unshift(el);

        if (el.getAttribute('data-fr-prevent') === '1') ev.preventDefault();
        var ret;
        try { ret = fn.apply(el, args.concat([el, ev])); }
        catch (e) { if (window.console) console.error('[csp-actions] erreur action ' + name + ':', e); return; }
        if (ret === false) ev.preventDefault();
    }

    // ── Garde de confirmation universelle (remplace on*="return confirm('…')") ──
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form && form.hasAttribute && form.hasAttribute('data-fr-confirm')) {
            if (!window.confirm(form.getAttribute('data-fr-confirm'))) { ev.preventDefault(); return; }
        }
        var el = ev.target.closest ? ev.target.closest('[data-fr-submit]') : null;
        if (el) dispatch(el, 'submit', ev);
    }, true);

    document.addEventListener('click', function (ev) {
        var c = ev.target.closest('[data-fr-confirm]');
        if (c) {
            // Un bouton submit dont le formulaire porte déjà data-fr-confirm est géré par
            // le listener submit → ne pas re-demander confirmation au clic (double dialogue).
            var isFormSubmit = (c.type === 'submit' || c.type === 'image') && c.form && c.form.hasAttribute('data-fr-confirm');
            if (!isFormSubmit && !window.confirm(c.getAttribute('data-fr-confirm'))) {
                ev.preventDefault();
                if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
                return;
            }
        }
        var el = ev.target.closest('[data-fr-click]');
        if (el) dispatch(el, 'click', ev);
    });

    document.addEventListener('change', function (ev) { var el = ev.target.closest('[data-fr-change]'); if (el) dispatch(el, 'change', ev); });
    document.addEventListener('input',  function (ev) { var el = ev.target.closest('[data-fr-input]');  if (el) dispatch(el, 'input', ev); });
})();
