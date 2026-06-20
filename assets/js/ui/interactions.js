/* ============================================================================
 * Fronote — UI interactions (design system)
 * Moteur unique : thèmes (clair/sombre/liquide/auto), réduction mouvement/transparence,
 * topbar hide/show au scroll, bottom-bar, retour-haut, switches, dropdowns, modales,
 * toasts, tabs. Sans dépendance, idempotent, chargé en defer.
 * API publique : window.FronoteUI
 * ========================================================================== */
(function () {
  'use strict';
  if (window.FronoteUI && window.FronoteUI.__ready) return;

  var root = document.documentElement;
  var LS = {
    theme: 'fronote_dark_mode',            // light | dark | liquid | auto  (réutilise la clé existante)
    motion: 'fronote_reduce_motion',       // 'true' | 'false'
    transparency: 'fronote_reduce_transparency',
  };
  var THEMES = ['light', 'dark', 'liquid'];
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  // ── Thèmes ────────────────────────────────────────────────────────────────
  function resolve(pref) {
    if (pref === 'auto' || !pref) {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return THEMES.indexOf(pref) >= 0 ? pref : 'light';
  }
  function applyTheme() {
    var pref = get(LS.theme) || root.getAttribute('data-theme-pref') || 'light';
    root.setAttribute('data-theme', resolve(pref));
    root.setAttribute('data-theme-pref', pref);
  }
  function setTheme(pref) { set(LS.theme, pref); applyTheme(); document.dispatchEvent(new CustomEvent('fronote:themechange', { detail: { pref: pref } })); }

  function applyA11y() {
    var rm = get(LS.motion) === 'true';
    var rt = get(LS.transparency) === 'true';
    root.setAttribute('data-reduce-motion', rm ? 'true' : 'false');
    root.setAttribute('data-reduce-transparency', rt ? 'true' : 'false');
  }
  function setReducedMotion(on) { set(LS.motion, on ? 'true' : 'false'); applyA11y(); }
  function setReducedTransparency(on) { set(LS.transparency, on ? 'true' : 'false'); applyA11y(); }

  // ── Scroll : topbar hide/show + bottom-bar + retour-haut ───────────────────
  function initScroll() {
    // On ne pilote QUE la topbar du design system (.ds-topbar) — opt-in — pour ne pas
    // entrer en conflit avec le comportement de l'ancienne topbar (assets/js/topbar.js).
    var topbar = document.querySelector('.ds-topbar');
    var bottom = document.querySelector('.ds-bottom-bar');
    if (bottom) document.body.classList.add('has-bottom-bar'); // pour le padding bas du contenu (mobile)
    var legacyTop = document.querySelector('.topbar-nav'); // topbar historique : auto-masquage mobile (classe isolée)
    var toTop = document.querySelector('.ds-back-to-top');
    var lastY = window.scrollY, ticking = false;
    function onScroll() {
      var y = window.scrollY;
      if (topbar) {
        topbar.classList.toggle('is-scrolled', y > 4);
        if (y > 80 && y > lastY) topbar.classList.add('is-hidden');
        else topbar.classList.remove('is-hidden');
        if (y < 40) topbar.classList.remove('is-hidden');
      }
      if (legacyTop) {
        if (window.innerWidth < 1024) {
          legacyTop.classList.toggle('is-scrolled-mobile', y > 4);
          if (y > 80 && y > lastY) legacyTop.classList.add('is-hidden-mobile');
          else legacyTop.classList.remove('is-hidden-mobile');
          if (y < 40) legacyTop.classList.remove('is-hidden-mobile');
        } else {
          legacyTop.classList.remove('is-hidden-mobile', 'is-scrolled-mobile');
        }
      }
      if (bottom) bottom.classList.toggle('is-visible', y > 80 || window.innerWidth < 1024);
      if (toTop) toTop.classList.toggle('is-visible', y > 600);
      lastY = y; ticking = false;
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { window.requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });
    if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    if (bottom && window.innerWidth < 1024) bottom.classList.add('is-visible');
  }

  // ── Switches (.ds-switch) ──────────────────────────────────────────────────
  function toggleSwitch(sw) {
    if (!sw || sw.classList.contains('is-disabled') || sw.hasAttribute('disabled') || sw.classList.contains('is-loading')) return;
    var on = sw.classList.toggle('is-on');
    sw.setAttribute('aria-checked', on ? 'true' : 'false');
    var cb = sw.querySelector('input[type=checkbox]');
    if (cb) cb.checked = on;
    sw.dispatchEvent(new CustomEvent('ds:toggle', { bubbles: true, detail: { on: on } }));
  }
  function initSwitches() {
    document.addEventListener('click', function (e) {
      var sw = e.target.closest('.ds-switch'); if (sw) toggleSwitch(sw);
    });
    // Clavier : Espace/Entrée. (Sur un <button class="ds-switch"> le natif déclenche déjà
    // un click ; on ne gère ici que les éléments non-bouton avec role="switch".)
    document.addEventListener('keydown', function (e) {
      if (e.key !== ' ' && e.key !== 'Enter' && e.key !== 'Spacebar') return;
      var sw = e.target.closest('.ds-switch');
      if (sw && sw.tagName !== 'BUTTON') { e.preventDefault(); toggleSwitch(sw); }
    });
  }

  // ── Dropdowns (.ds-dropdown > [data-ds-dropdown-toggle] + .ds-dropdown-menu) ─
  function initDropdowns() {
    document.addEventListener('click', function (e) {
      var toggle = e.target.closest('[data-ds-dropdown-toggle], .ds-dropdown-toggle');
      if (toggle) {
        var dd = toggle.closest('.ds-dropdown');
        if (dd) {
          var open = dd.classList.toggle('is-open');
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          document.querySelectorAll('.ds-dropdown.is-open').forEach(function (o) { if (o !== dd) o.classList.remove('is-open'); });
          e.stopPropagation();
          return;
        }
      }
      if (!e.target.closest('.ds-dropdown-menu')) {
        document.querySelectorAll('.ds-dropdown.is-open').forEach(function (o) { o.classList.remove('is-open'); });
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') document.querySelectorAll('.ds-dropdown.is-open').forEach(function (o) { o.classList.remove('is-open'); });
    });
  }

  // ── Tabs (.ds-tabs [data-ds-tab=KEY] / .ds-tabpanel[data-ds-panel=KEY]) ─────
  function initTabs() {
    document.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-ds-tab]');
      if (!tab) return;
      var key = tab.getAttribute('data-ds-tab');
      var scope = tab.closest('.ds-tabs-scope') || document;
      scope.querySelectorAll('[data-ds-tab]').forEach(function (t) { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
      scope.querySelectorAll('[data-ds-panel]').forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-ds-panel') === key); });
    });
  }

  // ── Modales ([data-ds-modal-open=ID] / .ds-modal-overlay#ID / [data-ds-modal-close]) ─
  function openModal(id) {
    var ov = document.getElementById(id);
    if (!ov) return;
    ov.classList.add('is-open');
    root.style.overflow = 'hidden';
    var f = ov.querySelector('input,button,select,textarea,[tabindex]'); if (f) try { f.focus(); } catch (e) {}
  }
  function closeModal(ov) {
    if (!ov) return;
    ov.classList.add('is-closing');
    setTimeout(function () { ov.classList.remove('is-open', 'is-closing'); root.style.overflow = ''; }, 200);
  }
  function initModals() {
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-ds-modal-open]');
      if (opener) { openModal(opener.getAttribute('data-ds-modal-open')); return; }
      var closer = e.target.closest('[data-ds-modal-close]');
      if (closer) { closeModal(closer.closest('.ds-modal-overlay')); return; }
      if (e.target.classList && e.target.classList.contains('ds-modal-overlay')) closeModal(e.target);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { var o = document.querySelector('.ds-modal-overlay.is-open'); if (o) closeModal(o); }
    });
  }

  // ── Toasts ─────────────────────────────────────────────────────────────────
  function ensureToastContainer() {
    var c = document.querySelector('.ds-toast-container');
    if (!c) { c = document.createElement('div'); c.className = 'ds-toast-container'; document.body.appendChild(c); }
    return c;
  }
  var ICONS = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info', loading: 'fa-spinner fa-spin' };
  function toast(message, type, opts) {
    type = type || 'info'; opts = opts || {};
    var c = ensureToastContainer();
    var t = document.createElement('div');
    t.className = 'ds-toast ds-toast--' + type;
    t.setAttribute('role', 'status');
    t.innerHTML = '<span class="ds-toast__icon"><i class="fas ' + (ICONS[type] || ICONS.info) + '"></i></span>' +
      '<div class="ds-toast__content"><div class="ds-toast__message"></div></div>' +
      '<button class="ds-toast__close" aria-label="Fermer">&times;</button>' +
      '<span class="ds-toast__progress"></span>';
    t.querySelector('.ds-toast__message').textContent = message;
    c.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-visible'); });
    var dur = opts.duration != null ? opts.duration : (type === 'loading' ? 0 : 4000);
    function dismiss() { t.classList.add('is-closing'); setTimeout(function () { t.remove(); }, 220); }
    t.querySelector('.ds-toast__close').addEventListener('click', dismiss);
    if (dur > 0) {
      var p = t.querySelector('.ds-toast__progress');
      if (p) { p.style.transition = 'transform ' + dur + 'ms linear'; requestAnimationFrame(function () { p.style.transform = 'scaleX(0)'; }); }
      setTimeout(dismiss, dur);
    }
    return { dismiss: dismiss, el: t };
  }

  // ── Barre de progression de navigation (feedback de chargement perçu) ───────
  function initNavProgress() {
    if (root.getAttribute('data-reduce-motion') === 'true') return;
    try { if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; } catch (e) {}
    var bar = document.createElement('div');
    bar.className = 'ds-nav-progress';
    bar.innerHTML = '<span></span>';
    var fill = bar.firstChild, timer = null;
    document.body.appendChild(bar);
    function start() {
      bar.classList.add('is-active'); fill.style.width = '0';
      requestAnimationFrame(function () { fill.style.width = '75%'; });
      clearTimeout(timer); timer = setTimeout(function () { fill.style.width = '92%'; }, 700);
    }
    function done() { clearTimeout(timer); fill.style.width = '100%'; setTimeout(function () { bar.classList.remove('is-active'); fill.style.width = '0'; }, 250); }
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
      if (a.target && a.target !== '_self') return;
      if (a.hasAttribute('download')) return;
      if (a.origin && a.origin !== window.location.origin) return;
      start();
    }, true);
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f && (!f.method || f.method.toLowerCase() === 'post') && f.target !== '_blank') start();
    }, true);
    window.addEventListener('pageshow', done);
  }

  // ── Interrupteurs d'accessibilité (.ds-switch[data-a11y-toggle=motion|transparency]) ──
  // Câblage unique réutilisé par les paramètres ET la vitrine (ON = NON réduit).
  function initA11ySwitches() {
    var map = { motion: setReducedMotion, transparency: setReducedTransparency };
    document.querySelectorAll('.ds-switch[data-a11y-toggle]').forEach(function (sw) {
      var key = sw.getAttribute('data-a11y-toggle');
      if (!map[key]) return;
      var attr = key === 'transparency' ? 'data-reduce-transparency' : 'data-reduce-motion';
      var on = root.getAttribute(attr) !== 'true';
      sw.classList.toggle('is-on', on);
      sw.setAttribute('aria-checked', on ? 'true' : 'false');
      sw.addEventListener('ds:toggle', function (e) { if (e.detail) map[key](!e.detail.on); });
    });
  }

  // ── Squelettes de chargement (utilitaire AJAX) ──────────────────────────────
  function skeleton(target, rows) {
    var el = typeof target === 'string' ? document.querySelector(target) : target;
    if (!el) return;
    rows = rows || 3;
    var html = '';
    for (var i = 0; i < rows; i++) {
      html += '<div class="ds-skeleton ds-skeleton--text" style="margin-bottom:10px;width:' + (95 - (i % 3) * 12) + '%"></div>';
    }
    el.innerHTML = html;
  }

  // ── Init ────────────────────────────────────────────────────────────────────
  function init() {
    applyTheme(); applyA11y();
    initScroll(); initSwitches(); initDropdowns(); initTabs(); initModals(); initNavProgress(); initA11ySwitches();
    // suivre le changement de préférence système si 'auto'
    try { window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () { if ((get(LS.theme) || 'light') === 'auto') applyTheme(); }); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.FronoteUI = {
    __ready: true,
    setTheme: setTheme, applyTheme: applyTheme, getTheme: function () { return get(LS.theme) || 'light'; },
    setReducedMotion: setReducedMotion, setReducedTransparency: setReducedTransparency,
    toast: toast, openModal: openModal, skeleton: skeleton,
    closeModal: function (id) { closeModal(typeof id === 'string' ? document.getElementById(id) : id); },
  };
})();
