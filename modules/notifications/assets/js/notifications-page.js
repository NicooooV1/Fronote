/* Fronote — page « Notifications » (historique complet). */
(function () {
  'use strict';
  var root = document.getElementById('ncp');
  if (!root) return;
  var API = root.dataset.api, BASE = root.dataset.base;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var st = { cat: '', unread: false, items: [], next: null };
  var list = document.getElementById('ncp-list'), more = document.getElementById('ncp-more');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function req(action, params, body) {
    var url = API + '?action=' + action;
    Object.keys(params || {}).forEach(function (k) { if (params[k] !== '' && params[k] != null) url += '&' + k + '=' + encodeURIComponent(params[k]); });
    var o = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) { var fd = new FormData(); fd.append('action', action); Object.keys(body).forEach(function (k) { var v = body[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); }); o.method = 'POST'; o.body = fd; o.headers['X-CSRF-Token'] = csrf; }
    return fetch(url, o).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || r.status); return j; }); });
  }
  function when(s) {
    var d = new Date(String(s).replace(' ', 'T'));
    return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }
  function day(s) { var d = new Date(String(s).replace(' ', 'T')); return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined }); }
  function href(n) { return n.link ? (n.link.charAt(0) === '/' ? BASE.replace(/\/$/, '') + n.link : n.link) : '#'; }
  function render() {
    if (!st.items.length) {
      list.innerHTML = '<li class="nc-empty"><i class="far fa-bell" aria-hidden="true"></i><b>' + (st.unread ? 'Vous êtes à jour' : 'Rien ici pour l\'instant') + '</b><span>Les nouvelles notes, absences, messages et annonces apparaîtront ici.</span></li>';
    } else {
      var h = '', last = '';
      st.items.forEach(function (n) {
        var d = day(n.at); if (d !== last) { h += '<li class="ncp-day">' + esc(d.charAt(0).toUpperCase() + d.slice(1)) + '</li>'; last = d; }
        h += '<li class="ncp-item' + (n.read ? '' : ' is-unread') + '" data-id="' + n.id + '">'
          + '<span class="nc-ico" data-cat="' + esc(n.category) + '"><i class="fas ' + esc(n.icon) + '" aria-hidden="true"></i></span>'
          + '<a class="ncp-main" href="' + esc(href(n)) + '"><span class="ncp-title">' + esc(n.title) + (n.count > 1 && !/^\d/.test(n.title) ? ' <b class="nc-x">×' + n.count + '</b>' : '') + '</span>'
          + (n.body ? '<span class="ncp-body">' + esc(n.body) + '</span>' : '') + '<span class="ncp-time">' + esc(when(n.at)) + '</span></a>'
          + '<span class="ncp-item-act">' + (n.read ? '' : '<button type="button" data-read="' + n.id + '" title="Marquer comme lue"><i class="fas fa-check"></i></button>')
          + '<button type="button" data-del="' + n.id + '" title="Supprimer"><i class="far fa-trash-alt"></i></button></span></li>';
      });
      list.innerHTML = h;
    }
    more.hidden = !st.next;
  }
  function load(append) {
    if (!append) list.innerHTML = '<li class="nc-skel"></li><li class="nc-skel"></li><li class="nc-skel"></li><li class="nc-skel"></li>';
    return req('list', { category: st.cat, unread: st.unread ? 1 : '', before: append ? st.next : '', limit: 30 }).then(function (j) {
      st.items = append ? st.items.concat(j.items) : j.items; st.next = j.next; render();
      if (window.FronoteNotif) window.FronoteNotif.refresh();
    }).catch(function (e) { list.innerHTML = '<li class="nc-empty"><b>Impossible de charger</b><span>' + esc(e.message) + '</span></li>'; });
  }
  root.addEventListener('click', function (e) {
    var chip = e.target.closest('.ncp-chip');
    if (chip) { root.querySelectorAll('.ncp-chip').forEach(function (c) { c.setAttribute('aria-pressed', String(c === chip)); }); st.cat = chip.dataset.cat; load(); return; }
    var r = e.target.closest('[data-read]');
    if (r) { req('read', null, { ids: [r.dataset.read] }).then(function () { st.items.forEach(function (n) { if (n.id === +r.dataset.read) n.read = true; }); render(); if (window.FronoteNotif) window.FronoteNotif.refresh(); }); return; }
    var d = e.target.closest('[data-del]');
    if (d) { req('delete', null, { id: d.dataset.del }).then(function () { st.items = st.items.filter(function (n) { return n.id !== +d.dataset.del; }); render(); if (window.FronoteNotif) window.FronoteNotif.refresh(); }); return; }
    var a = e.target.closest('.ncp-main');
    if (a) { var it = a.closest('.ncp-item'); if (it.classList.contains('is-unread')) { e.preventDefault(); req('read', null, { ids: [it.dataset.id] }).catch(function () {}).then(function () { location.href = a.href; }); } return; }
    var act = e.target.closest('[data-act]');
    if (act && act.dataset.act === 'readall') { req('read', null, { all: 1 }).then(function () { load(); }); }
    if (act && act.dataset.act === 'clean') { req('delete', null, { read: 1 }).then(function () { load(); }); }
  });
  document.getElementById('ncp-unread').addEventListener('change', function (e) { st.unread = e.target.checked; load(); });
  more.addEventListener('click', function () { load(true); });
  document.addEventListener('fronote:notification', function () { setTimeout(load, 300); });
  load();
})();
