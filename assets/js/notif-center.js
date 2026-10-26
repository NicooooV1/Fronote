/* Fronote — centre de notifications (cloche de la barre du haut). Chargé sur toutes les pages.
 * Données : API/endpoints/notifications.php. Temps réel : « fronote:notification » (ws-global.js),
 * repli : lecture légère (empreinte) toutes les 45 s quand le temps réel est indisponible. */
(function () {
  'use strict';
  var bell = document.getElementById('nc-bell');
  if (!bell) return;
  var BASE = window.FRONOTE_BASE_URL || bell.getAttribute('data-base') || '/';
  var API = BASE + 'API/endpoints/notifications.php';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var badge = document.getElementById('nc-badge');
  var st = { count: parseInt(bell.getAttribute('data-count') || '0', 10) || 0, fp: '', items: [], next: null, unread: false, open: false, loading: false };
  var panel = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function req(action, params, body) {
    var url = API + '?action=' + action;
    if (params) Object.keys(params).forEach(function (k) { if (params[k] !== '' && params[k] != null) url += '&' + k + '=' + encodeURIComponent(params[k]); });
    var o = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } };
    if (body) {
      var fd = new FormData(); fd.append('action', action);
      Object.keys(body).forEach(function (k) { var v = body[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); });
      o.method = 'POST'; o.body = fd; o.headers['X-CSRF-Token'] = csrf;
    }
    return fetch(url, o).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || r.status); return j; }); });
  }
  function setCount(n) {
    st.count = Math.max(0, n | 0);
    if (badge) { badge.textContent = st.count > 99 ? '99+' : String(st.count); badge.hidden = st.count === 0; }
    bell.setAttribute('aria-label', st.count ? 'Notifications — ' + st.count + ' non lue' + (st.count > 1 ? 's' : '') : 'Notifications');
    var base = document.title.replace(/^\(\d+\+?\)\s*/, '');
    document.title = st.count ? '(' + (st.count > 99 ? '99+' : st.count) + ') ' + base : base;
  }
  function rel(s) {
    var d = new Date(String(s).replace(' ', 'T')), diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'à l\'instant';
    if (diff < 3600) return 'il y a ' + Math.floor(diff / 60) + ' min';
    if (diff < 86400) return 'il y a ' + Math.floor(diff / 3600) + ' h';
    if (diff < 172800) return 'hier à ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
  }
  function bucket(s) {
    var d = new Date(String(s).replace(' ', 'T')), n = new Date(), t0 = new Date(n.getFullYear(), n.getMonth(), n.getDate()).getTime();
    if (d.getTime() >= t0) return 'Aujourd\'hui';
    if (d.getTime() >= t0 - 864e5) return 'Hier';
    if (d.getTime() >= t0 - 6 * 864e5) return 'Cette semaine';
    return 'Plus ancien';
  }
  function itemHtml(n) {
    return '<li><a class="nc-item' + (n.read ? '' : ' is-unread') + (n.importance === 'urgente' || n.importance === 'haute' ? ' is-high' : '') + '" href="' + esc(n.link ? (n.link.charAt(0) === '/' ? BASE.replace(/\/$/, '') + n.link : n.link) : BASE + 'modules/notifications/notifications.php') + '" data-id="' + n.id + '">'
      + '<span class="nc-ico" data-cat="' + esc(n.category) + '"><i class="fas ' + esc(n.icon) + '" aria-hidden="true"></i></span>'
      + '<span class="nc-txt"><span class="nc-title">' + esc(n.title) + (n.count > 1 && !/^\d/.test(n.title) ? ' <b class="nc-x">×' + n.count + '</b>' : '') + '</span>'
      + (n.body ? '<span class="nc-body">' + esc(n.body) + '</span>' : '') + '<span class="nc-time">' + esc(rel(n.at)) + '</span></span>'
      + (n.read ? '' : '<button type="button" class="nc-mark" data-mark="' + n.id + '" title="Marquer comme lue" aria-label="Marquer comme lue"><i class="fas fa-check"></i></button>')
      + '</a></li>';
  }
  function render() {
    if (!panel) return;
    var list = panel.querySelector('.nc-list');
    panel.querySelectorAll('.nc-seg button').forEach(function (b) { b.setAttribute('aria-pressed', String((b.dataset.f === 'unread') === st.unread)); });
    panel.querySelector('.nc-readall').disabled = st.count === 0;
    if (!st.items.length) {
      list.innerHTML = '<li class="nc-empty"><i class="far fa-bell" aria-hidden="true"></i><b>' + (st.unread ? 'Vous êtes à jour' : 'Aucune notification') + '</b>'
        + '<span>' + (st.unread ? 'Aucune notification non lue.' : 'Les nouvelles notes, absences, messages et annonces apparaîtront ici.') + '</span></li>';
      return;
    }
    var h = '', last = '';
    st.items.forEach(function (n) { var b = bucket(n.at); if (b !== last) { h += '<li class="nc-bucket">' + b + '</li>'; last = b; } h += itemHtml(n); });
    if (st.next) h += '<li><button type="button" class="nc-more">Plus anciennes</button></li>';
    list.innerHTML = h;
  }
  function load(more) {
    if (st.loading) return Promise.resolve();
    st.loading = true;
    if (!more && panel && !st.items.length) panel.querySelector('.nc-list').innerHTML = '<li class="nc-skel"></li><li class="nc-skel"></li><li class="nc-skel"></li>';
    return req('list', { unread: st.unread ? 1 : '', before: more ? st.next : '', limit: 15 }).then(function (j) {
      st.items = more ? st.items.concat(j.items) : j.items; st.next = j.next; st.fp = j.fp; setCount(j.count); render();
    }).catch(function () { if (panel) panel.querySelector('.nc-list').innerHTML = '<li class="nc-empty"><b>Impossible de charger</b><span>Vérifiez votre connexion.</span></li>'; })
      .then(function () { st.loading = false; });
  }
  function build() {
    panel = document.createElement('div');
    panel.className = 'nc-panel'; panel.id = 'nc-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Notifications'); panel.hidden = true;
    var canAsk = 'Notification' in window && Notification.permission === 'default' && window.isSecureContext;
    panel.innerHTML = '<div class="nc-head"><h2>Notifications</h2><div class="nc-head-act">'
      + '<button type="button" class="nc-readall" title="Tout marquer comme lu"><i class="fas fa-check-double" aria-hidden="true"></i><span>Tout lu</span></button>'
      + '<a class="nc-gear" href="' + BASE + 'modules/notifications/preferences.php" title="Préférences de notification" aria-label="Préférences"><i class="fas fa-sliders-h"></i></a></div></div>'
      + '<div class="nc-seg" role="group" aria-label="Filtre"><button type="button" data-f="all" aria-pressed="true">Toutes</button><button type="button" data-f="unread" aria-pressed="false">Non lues</button></div>'
      + (canAsk ? '<div class="nc-ask"><i class="fas fa-desktop" aria-hidden="true"></i><span>Être alerté sur cet appareil quand Fronote est en arrière-plan.</span><button type="button" class="nc-ask-btn">Activer</button></div>' : '')
      + '<ul class="nc-list"></ul><a class="nc-all" href="' + BASE + 'modules/notifications/notifications.php">Voir toutes les notifications</a>';
    document.body.appendChild(panel);
    panel.addEventListener('click', function (e) {
      var mark = e.target.closest('[data-mark]');
      if (mark) {
        e.preventDefault(); e.stopPropagation();
        var id = +mark.dataset.mark;
        req('read', null, { ids: [id] }).then(function (j) { setCount(j.count); st.items.forEach(function (n) { if (n.id === id) n.read = true; }); if (st.unread) st.items = st.items.filter(function (n) { return !n.read; }); render(); });
        return;
      }
      var it = e.target.closest('.nc-item');
      if (it) {
        var n = st.items.filter(function (x) { return x.id === +it.dataset.id; })[0];
        if (n && !n.read) { e.preventDefault(); req('read', null, { ids: [n.id] }).catch(function () {}).then(function () { location.href = it.href; }); }
        return;
      }
      var seg = e.target.closest('.nc-seg button');
      if (seg) { st.unread = seg.dataset.f === 'unread'; st.items = []; load(); return; }
      if (e.target.closest('.nc-readall')) { req('read', null, { all: 1 }).then(function (j) { setCount(j.count); st.items.forEach(function (n) { n.read = true; }); if (st.unread) st.items = []; render(); }); return; }
      if (e.target.closest('.nc-more')) { load(true); return; }
      if (e.target.closest('.nc-ask-btn')) {
        Notification.requestPermission().then(function () { var a = panel.querySelector('.nc-ask'); if (a) a.remove(); });
      }
    });
  }
  function position() {
    var r = bell.getBoundingClientRect();
    if (window.innerWidth <= 640) { panel.style.top = (r.bottom + 6) + 'px'; panel.style.right = ''; return; }
    panel.style.top = (r.bottom + 8) + 'px';
    panel.style.right = Math.max(12, window.innerWidth - r.right - 12) + 'px';
  }
  function toggle(force) {
    if (!panel) build();
    st.open = typeof force === 'boolean' ? force : !st.open;
    panel.hidden = !st.open; bell.setAttribute('aria-expanded', String(st.open));
    if (st.open) { position(); load(); }
  }
  bell.addEventListener('click', function (e) { e.preventDefault(); toggle(); });
  document.addEventListener('click', function (e) { if (st.open && !e.target.closest('#nc-panel') && !e.target.closest('#nc-bell')) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && st.open) { toggle(false); bell.focus(); } });
  window.addEventListener('resize', function () { if (st.open) position(); });

  // ── alertes en direct ──
  var toastBox = null;
  function toast(n) {
    if (!toastBox) { toastBox = document.createElement('div'); toastBox.className = 'nc-toasts'; toastBox.setAttribute('aria-live', 'polite'); document.body.appendChild(toastBox); }
    var a = document.createElement('a');
    a.className = 'nc-toast'; a.href = n.link ? (n.link.charAt(0) === '/' ? BASE.replace(/\/$/, '') + n.link : n.link) : BASE + 'modules/notifications/notifications.php';
    a.innerHTML = '<span class="nc-ico" data-cat="' + esc(n.category || 'systeme') + '"><i class="fas ' + esc(n.icon || 'fa-bell') + '"></i></span><span class="nc-txt"><span class="nc-title">' + esc(n.title || 'Nouvelle notification') + '</span>'
      + (n.body ? '<span class="nc-body">' + esc(n.body) + '</span>' : '') + '</span><button type="button" class="nc-toast-x" aria-label="Fermer">×</button>';
    a.querySelector('.nc-toast-x').addEventListener('click', function (e) { e.preventDefault(); a.remove(); });
    toastBox.appendChild(a);
    while (toastBox.children.length > 3) toastBox.firstChild.remove();
    setTimeout(function () { a.classList.add('is-out'); }, 6000);
    setTimeout(function () { a.remove(); }, 6600);
  }
  function desktop(n) {
    if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
    try {
      var dn = new Notification(n.title || 'Fronote', { body: n.body || '', tag: 'fronote-' + (n.kind || 'n'), icon: BASE + 'favicon.ico' });
      dn.onclick = function () { window.focus(); if (n.link) location.href = n.link.charAt(0) === '/' ? BASE.replace(/\/$/, '') + n.link : n.link; dn.close(); };
    } catch (e) {}
  }
  document.addEventListener('fronote:notification', function (ev) {
    var d = ev.detail || {};
    if (d.refresh) { summary(); return; }
    setCount(st.count + 1);
    // un message de la conversation actuellement ouverte n'est pas une alerte
    var conv = window.__activeConversationId;
    var isOpenConv = d.kind === 'message' && conv && d.link && d.link.indexOf('c=' + conv) !== -1;
    if (!isOpenConv) { if (document.hidden) desktop(d); else toast(d); }
    if (st.open) load();
  });

  // ── repli : lecture légère ──
  function summary() {
    return req('summary', { fp: st.fp }).then(function (j) {
      var grew = j.count > st.count;
      st.fp = j.fp; setCount(j.count);
      if (j.changed && st.open) load();
      if (grew && !st.open) req('list', { unread: 1, limit: 1 }).then(function (r) { if (r.items[0]) { if (document.hidden) desktop(r.items[0]); else toast(r.items[0]); } });
    }).catch(function () {});
  }
  setInterval(function () {
    if (document.hidden) return;
    if (window.wsGlobal && window.wsGlobal.isConnected && window.wsGlobal.isConnected()) return;
    summary();
  }, 45000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) summary(); });
  setCount(st.count);
  window.FronoteNotif = { refresh: summary, toast: toast };
})();
