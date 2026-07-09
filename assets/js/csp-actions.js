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
 *   onsubmit="return validate()"        -> data-fr-submit="validate"
 *   onclick="document.getElementById('m').style.display='flex'" -> data-fr-click="showFlex" data-fr-args='["m"]'
 *
 * La fonction reçoit : (...args, el, event). data-fr-pass="value|checked|this"
 * injecte respectivement el.value / el.checked / el en TÊTE des arguments.
 */
(function () {
    'use strict';
    var FR = (window.FR = window.FR || {});
    FR.actions = FR.actions || {};
    FR.register = function (name, fn) { FR.actions[name] = fn; };

    // Helpers intégrés (remplacent les expressions inline courantes).
    var A = FR.actions;
    A.show      = function (id) { var e = document.getElementById(id); if (e) e.style.display = ''; };
    A.showBlock = function (id) { var e = document.getElementById(id); if (e) e.style.display = 'block'; };
    A.showFlex  = function (id) { var e = document.getElementById(id); if (e) e.style.display = 'flex'; };
    A.hide      = function (id) { var e = document.getElementById(id); if (e) e.style.display = 'none'; };
    A.toggle    = function (id) { var e = document.getElementById(id); if (e) e.style.display = (e.style.display === 'none' ? '' : 'none'); };
    A.toggleClass = function (sel, cls) { var e = typeof sel === 'string' ? document.querySelector(sel) : sel; if (e) e.classList.toggle(cls); };
    A.href      = function (url) { window.location.href = url; };
    A.reload    = function () { window.location.reload(); };
    A.back      = function () { window.history.back(); };
    A.print     = function () { window.print(); };
    A.submitForm = function (id) { var f = document.getElementById(id); if (f) f.submit(); };
    A.setValue  = function (id, val) { var e = document.getElementById(id); if (e) e.value = val; };
    A.noop      = function () {};

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

    document.addEventListener('click',  function (ev) { var el = ev.target.closest('[data-fr-click]');  if (el) dispatch(el, 'click', ev); });
    document.addEventListener('change', function (ev) { var el = ev.target.closest('[data-fr-change]'); if (el) dispatch(el, 'change', ev); });
    document.addEventListener('input',  function (ev) { var el = ev.target.closest('[data-fr-input]');  if (el) dispatch(el, 'input', ev); });
    document.addEventListener('submit', function (ev) { var el = ev.target.closest('[data-fr-submit]'); if (el) dispatch(el, 'submit', ev); }, true);
})();
