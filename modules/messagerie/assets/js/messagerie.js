/* Fronote — Messagerie (4.3). Interface d'une seule page, sans dépendance.
 * Données : api.php (JSON). Temps réel : événements DOM émis par ws-global.js
 * (« fronote:message », « fronote:read », « fronote:typing ») + repli en lecture périodique. */
(function () {
  'use strict';
  var cfgEl = document.getElementById('msg-config');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var app = $('#msg-app');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  var state = {
    box: 'inbox', q: '', items: [], next: null, loadingList: false,
    conv: null, convId: 0, messages: [], firstId: 0, lastId: 0, more: false, loadingOlder: false,
    replyFiles: [], composeFiles: [], chips: [], dir: null, typingTimer: null, lastTypingSent: 0
  };

  // ───────────────────────── utilitaires ─────────────────────────
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function linkify(text) {
    var h = esc(text);
    h = h.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>');
    return h.replace(/\n/g, '<br>');
  }
  function api(action, params, body) {
    var url = CFG.api + '?action=' + encodeURIComponent(action);
    if (params) Object.keys(params).forEach(function (k) { if (params[k] !== undefined && params[k] !== null && params[k] !== '') url += '&' + k + '=' + encodeURIComponent(params[k]); });
    var opt = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } };
    if (body) {
      opt.method = 'POST'; opt.headers['X-CSRF-Token'] = csrf;
      if (!(body instanceof FormData)) { var fd = new FormData(); Object.keys(body).forEach(function (k) { var v = body[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); }); body = fd; }
      body.append('action', action); opt.body = body;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Réponse illisible du serveur.' }; }).then(function (j) {
        if (!r.ok || !j.ok) { var e = new Error(j.error || ('Erreur ' + r.status)); e.status = r.status; throw e; }
        return j;
      });
    });
  }
  var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  var MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  function parseAt(s) { return new Date(String(s).replace(' ', 'T')); }
  function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
  function hhmm(d) { return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
  function listTime(s) {
    var d = parseAt(s), n = new Date(), y = new Date(); y.setDate(n.getDate() - 1);
    if (sameDay(d, n)) return hhmm(d);
    if (sameDay(d, y)) return 'Hier';
    if ((n - d) < 6 * 864e5) return DAYS[d.getDay()];
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (d.getFullYear() !== n.getFullYear() ? ' ' + d.getFullYear() : '');
  }
  function dayLabel(d) {
    var n = new Date(), y = new Date(); y.setDate(n.getDate() - 1);
    if (sameDay(d, n)) return 'Aujourd\'hui';
    if (sameDay(d, y)) return 'Hier';
    var s = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][d.getDay()] + ' ' + d.getDate() + ' ' + ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][d.getMonth()];
    return s + (d.getFullYear() !== n.getFullYear() ? ' ' + d.getFullYear() : '');
  }
  function size(b) { return b < 1024 ? b + ' o' : b < 1048576 ? Math.round(b / 1024) + ' Ko' : (b / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
  function fileIcon(mime, name) {
    if (/^image\//.test(mime)) return 'fa-file-image';
    if (/pdf/.test(mime)) return 'fa-file-pdf';
    if (/word|opendocument\.text/.test(mime) || /\.(docx?|odt)$/i.test(name)) return 'fa-file-word';
    if (/sheet|excel|csv/.test(mime) || /\.(xlsx?|ods|csv)$/i.test(name)) return 'fa-file-excel';
    if (/presentation|powerpoint/.test(mime)) return 'fa-file-powerpoint';
    if (/zip/.test(mime)) return 'fa-file-archive';
    return 'fa-file-alt';
  }
  function avatar(p, cls) {
    if (!p) return '<span class="msg-av ' + (cls || '') + '" data-role="x">?</span>';
    return '<span class="msg-av ' + (cls || '') + '" data-role="' + esc(p.type) + '" aria-hidden="true">' + esc(p.initials || '?') + '</span>';
  }
  function toast(msg, kind) {
    var t = document.createElement('div');
    t.className = 'msg-toast' + (kind === 'error' ? ' msg-toast--error' : '');
    t.setAttribute('role', 'status'); t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('is-out'); }, 3200);
    setTimeout(function () { t.remove(); }, 3700);
  }
  function setView(v) { app.setAttribute('data-view', v); }

  // ───────────────────────── liste ─────────────────────────
  function renderList(append) {
    var ul = $('#msg-list');
    if (!state.items.length) {
      ul.innerHTML = '<li class="msg-list-empty">' + (state.q ? 'Aucun résultat pour « ' + esc(state.q) + ' ».' :
        state.box === 'unread' ? 'Tout est lu. 👌' : state.box === 'archived' ? 'Aucune conversation archivée.' : 'Aucune conversation pour le moment.') + '</li>';
      return;
    }
    ul.innerHTML = state.items.map(function (c) {
      var who = c.with.length ? c.with.join(', ') + (c.with_count > c.with.length ? ' +' + (c.with_count - c.with.length) : '') : 'Moi';
      var prev = (c.last.mine ? 'Vous : ' : (c.with_count > 1 && c.last.sender ? c.last.sender + ' : ' : '')) + c.last.preview;
      var badges = (c.pinned ? '<i class="fas fa-thumbtack msg-mini" title="Épinglée"></i>' : '') + (c.muted ? '<i class="fas fa-bell-slash msg-mini" title="En sourdine"></i>' : '')
        + (c.last.status === 'urgent' ? '<span class="msg-flag msg-flag--urgent">Urgent</span>' : c.last.status === 'important' ? '<span class="msg-flag">Important</span>' : '');
      return '<li><button type="button" class="msg-item' + (c.unread ? ' is-unread' : '') + (c.id === state.convId ? ' is-active' : '') + '" data-id="' + c.id + '">'
        + avatar(c.with_count > 1 && c.type !== 'individuelle' ? { type: 'groupe', initials: String(c.with_count + 1) } : c.avatar)
        + '<span class="msg-item-body"><span class="msg-item-top"><span class="msg-item-who">' + esc(who) + '</span><time>' + esc(listTime(c.updated_at)) + '</time></span>'
        + '<span class="msg-item-subject">' + badges + esc(c.subject) + '</span>'
        + '<span class="msg-item-prev"><span>' + esc(prev) + '</span>' + (c.unread ? '<b class="msg-dot">' + (c.unread > 99 ? '99+' : c.unread) + '</b>' : '') + '</span></span></button></li>';
    }).join('') + (state.next ? '<li><button type="button" class="msg-more" data-act="more">Afficher plus</button></li>' : '');
  }
  function loadList(more) {
    if (state.loadingList) return Promise.resolve();
    state.loadingList = true;
    if (!more && !state.items.length) $('#msg-list').innerHTML = skeleton(6);
    return api('conversations', { box: state.box, q: state.q, before: more ? state.next : '' }).then(function (j) {
      state.items = more ? state.items.concat(j.items) : j.items; state.next = j.next;
      updateUnread(j.unread_total); renderList();
    }).catch(function (e) { $('#msg-list').innerHTML = '<li class="msg-list-empty">' + esc(e.message) + '</li>'; })
      .then(function () { state.loadingList = false; });
  }
  function skeleton(n) { var s = ''; for (var i = 0; i < n; i++) s += '<li class="msg-skel"><span></span><span></span></li>'; return s; }
  function updateUnread(n) {
    var b = $('#msg-unread-total');
    if (b) { b.hidden = !n; b.textContent = n > 99 ? '99+' : n; }
    var nav = document.querySelector('[data-badge-module="messagerie"]');
    if (nav) { nav.textContent = n > 99 ? '99+' : String(n); nav.style.display = n ? '' : 'none'; }
  }

  // ───────────────────────── fil ─────────────────────────
  function openConversation(id, pushHistory) {
    id = +id; if (!id) return;
    state.convId = id; state.messages = []; state.firstId = 0; state.lastId = 0;
    $$('.msg-item').forEach(function (b) { b.classList.toggle('is-active', +b.dataset.id === id); });
    $('#msg-placeholder').hidden = true; $('#msg-thread').hidden = false; setView('thread');
    $('#msg-stream').innerHTML = '<div class="msg-loading"><span></span><span></span><span></span></div>';
    $('#msg-thread-subject').textContent = ''; $('#msg-thread-people').textContent = '';
    if (pushHistory !== false) history.replaceState(null, '', '?c=' + id);
    window.__activeConversationId = id;
    if (window.wsGlobal && window.wsGlobal.joinConversation) window.wsGlobal.joinConversation(id);
    Promise.all([api('conversation', { id: id }), api('messages', { id: id })]).then(function (r) {
      if (state.convId !== id) return;
      state.conv = r[0].conversation; state.more = r[1].more;
      renderHead(); state.messages = r[1].items; renderStream(true);
      return api('read', null, { id: id }).then(function (j) {
        updateUnread(j.unread_total);
        state.items.forEach(function (c) { if (c.id === id) c.unread = 0; }); renderList();
      });
    }).catch(function (e) {
      $('#msg-stream').innerHTML = '<div class="msg-stream-empty">' + esc(e.message) + '</div>';
    });
  }
  function renderHead() {
    var c = state.conv;
    $('#msg-thread-subject').textContent = c.subject;
    var others = c.participants.filter(function (p) { return !p.me; });
    var txt;
    if (c.type === 'information' && c.is_author) txt = 'Information envoyée à ' + c.recipient_count + ' destinataire' + (c.recipient_count > 1 ? 's' : '') + ' · réponses privées';
    else if (c.type === 'information') txt = 'De ' + (others[0] ? others[0].name + ' (' + others[0].detail + ')' : '…') + ' · information';
    else txt = others.map(function (p) { return p.name + ' · ' + p.detail; }).join(' — ') || 'Vous seul';
    $('#msg-thread-people').textContent = txt;
    $('#msg-locked').hidden = c.can_reply || !c.private_reply;
    $('#msg-reply').hidden = !c.can_reply;
    var pin = $('[data-act="pin"]'), mute = $('[data-act="mute"]'), arc = $('[data-act="archive"]');
    pin.classList.toggle('is-on', c.pinned); pin.title = c.pinned ? 'Désépingler' : 'Épingler';
    mute.classList.toggle('is-on', c.muted); mute.title = c.muted ? 'Réactiver les notifications' : 'Mettre en sourdine';
    arc.classList.toggle('is-on', c.archived); arc.title = c.archived ? 'Désarchiver' : 'Archiver';
  }
  function msgHtml(m, prev) {
    var d = parseAt(m.at), html = '';
    if (!prev || !sameDay(parseAt(prev.at), d)) html += '<div class="msg-day"><span>' + esc(dayLabel(d)) + '</span></div>';
    var grouped = prev && prev.sender.id === m.sender.id && prev.sender.type === m.sender.type && sameDay(parseAt(prev.at), d) && (d - parseAt(prev.at)) < 10 * 6e4;
    var showName = !m.mine && !grouped && state.conv && (state.conv.participants.length > 2 || state.conv.type !== 'individuelle');
    var cls = 'msg-row' + (m.mine ? ' is-mine' : '') + (grouped ? ' is-grouped' : '') + (m.deleted ? ' is-deleted' : '');
    html += '<div class="' + cls + '" data-mid="' + m.id + '">';
    if (!m.mine) html += grouped ? '<span class="msg-av-space"></span>' : avatar(m.sender, 'msg-av--sm');
    html += '<div class="msg-bubble-wrap">';
    if (showName) html += '<div class="msg-sender">' + esc(m.sender.name) + ' <span>' + esc(m.sender.detail) + '</span></div>';
    var flag = m.status === 'urgent' ? '<span class="msg-flag msg-flag--urgent">Urgent</span>' : m.status === 'important' ? '<span class="msg-flag">Important</span>' : '';
    html += '<div class="msg-bubble">' + flag + (m.deleted ? '<em>Message supprimé</em>' : '<div class="msg-text">' + linkify(m.body) + '</div>');
    if (m.attachments && m.attachments.length) {
      html += '<div class="msg-atts">' + m.attachments.map(function (a) {
        var img = /^image\//.test(a.mime);
        return '<a class="msg-att' + (img ? ' is-img' : '') + '" href="' + CFG.download + '?id=' + a.id + '" target="_blank" rel="noopener">'
          + (img ? '<img src="' + CFG.download + '?id=' + a.id + '&inline=1" alt="" loading="lazy">' : '<i class="far ' + fileIcon(a.mime, a.name) + '" aria-hidden="true"></i>')
          + '<span><b>' + esc(a.name) + '</b><small>' + size(a.size) + '</small></span></a>';
      }).join('') + '</div>';
    }
    html += '</div><div class="msg-meta"><time datetime="' + esc(m.at) + '">' + hhmm(d) + '</time>' + (m.edited ? ' · modifié' : '')
      + (m.mine && !m.deleted ? ' <button type="button" class="msg-del" data-act="delmsg" data-mid="' + m.id + '" title="Supprimer ce message"><i class="far fa-trash-alt"></i></button>' : '')
      + '<span class="msg-seen" data-seen="' + m.id + '"></span></div></div></div>';
    return html;
  }
  function renderStream(scrollBottom) {
    var s = $('#msg-stream');
    if (!state.messages.length) { s.innerHTML = '<div class="msg-stream-empty">Aucun message.</div>'; return; }
    var h = state.more ? '<button type="button" class="msg-older" data-act="older">Messages précédents</button>' : '';
    state.messages.forEach(function (m, i) { h += msgHtml(m, state.messages[i - 1]); });
    s.innerHTML = h;
    state.firstId = state.messages[0].id; state.lastId = state.messages[state.messages.length - 1].id;
    renderSeen();
    if (scrollBottom) s.scrollTop = s.scrollHeight;
  }
  /** « Vu » sous mon dernier message (conversation à deux uniquement). */
  function renderSeen() {
    var c = state.conv; if (!c || c.type !== 'individuelle') return;
    var other = c.participants.filter(function (p) { return !p.me; })[0]; if (!other) return;
    var mineLast = null;
    state.messages.forEach(function (m) { if (m.mine && !m.deleted) mineLast = m; });
    $$('.msg-seen').forEach(function (e) { e.textContent = ''; });
    if (mineLast && other.last_read >= mineLast.id) {
      var e = document.querySelector('[data-seen="' + mineLast.id + '"]'); if (e) e.textContent = ' · Vu';
    }
  }
  function loadOlder() {
    if (state.loadingOlder || !state.more) return;
    state.loadingOlder = true;
    var s = $('#msg-stream'), h0 = s.scrollHeight;
    api('messages', { id: state.convId, before: state.firstId }).then(function (j) {
      state.messages = j.items.concat(state.messages); state.more = j.more; renderStream(false);
      s.scrollTop = s.scrollHeight - h0;
    }).catch(function (e) { toast(e.message, 'error'); }).then(function () { state.loadingOlder = false; });
  }
  var newInFlight = null;
  function fetchNew() {
    if (!state.convId || !state.lastId) return Promise.resolve();
    if (newInFlight) return newInFlight; // une seule lecture à la fois (temps réel + envoi simultanés)
    var id = state.convId;
    newInFlight = api('messages', { id: id, after: state.lastId }).then(function (j) {
      var known = {}; state.messages.forEach(function (m) { known[m.id] = true; });
      j.items = j.items.filter(function (m) { return !known[m.id]; });
      if (state.convId !== id || !j.items.length) return;
      var s = $('#msg-stream'), atBottom = s.scrollHeight - s.scrollTop - s.clientHeight < 120;
      state.messages = state.messages.concat(j.items); renderStream(atBottom);
      if (!atBottom) toast('Nouveau message dans cette conversation');
      if (!document.hidden) api('read', null, { id: id }).then(function (r) { updateUnread(r.unread_total); });
    }).then(function () { newInFlight = null; }, function (e) { newInFlight = null; throw e; });
    return newInFlight;
  }

  // ───────────────────────── réponse ─────────────────────────
  function autosize(t) { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 200) + 'px'; }
  function renderFiles(which) {
    var list = which === 'reply' ? state.replyFiles : state.composeFiles;
    var box = $(which === 'reply' ? '#msg-reply-files' : '#msg-compose-files');
    box.innerHTML = list.map(function (f, i) {
      return '<span class="msg-file"><i class="far ' + fileIcon(f.type, f.name) + '"></i>' + esc(f.name) + ' <small>' + size(f.size) + '</small>'
        + '<button type="button" data-rmfile="' + which + ':' + i + '" aria-label="Retirer">×</button></span>';
    }).join('');
  }
  function addFiles(which, files) {
    var list = which === 'reply' ? state.replyFiles : state.composeFiles;
    Array.prototype.forEach.call(files, function (f) {
      if (list.length >= CFG.maxFiles) { toast(CFG.maxFiles + ' fichiers maximum', 'error'); return; }
      if (f.size > CFG.maxBytes) { toast('« ' + f.name + ' » dépasse 10 Mo', 'error'); return; }
      list.push(f);
    });
    renderFiles(which);
  }
  function sendReply(e) {
    e.preventDefault();
    var t = $('#msg-reply-body'), body = t.value.trim();
    if (!body || !state.convId) return;
    var fd = new FormData(); fd.append('id', state.convId); fd.append('body', body);
    state.replyFiles.forEach(function (f) { fd.append('files[]', f); });
    var btn = $('.msg-send'); btn.disabled = true;
    // affichage immédiat (optimiste)
    var tmp = { id: 0, body: body, mine: true, at: new Date().toISOString().slice(0, 19).replace('T', ' '), status: 'normal', attachments: [], sender: CFG.me, deleted: false, pending: true };
    $('#msg-stream').insertAdjacentHTML('beforeend', msgHtml(tmp, state.messages[state.messages.length - 1]).replace('class="msg-row is-mine', 'class="msg-row is-mine is-pending'));
    $('#msg-stream').scrollTop = $('#msg-stream').scrollHeight;
    t.value = ''; autosize(t);
    api('reply', null, fd).then(function () {
      state.replyFiles = []; renderFiles('reply');
      return fetchNew().then(function () { loadList(); });
    }).catch(function (err) {
      $$('.is-pending').forEach(function (n) { n.remove(); }); t.value = body; autosize(t); toast(err.message, 'error');
    }).then(function () { btn.disabled = false; t.focus(); });
  }

  // ───────────────────────── rédaction ─────────────────────────
  function openCompose(prefill) {
    state.chips = []; state.composeFiles = []; renderChips(); renderFiles('compose');
    $('#msg-subject').value = ''; $('#msg-body').value = ''; $('#msg-compose-error').textContent = ''; $('#msg-to-q').value = '';
    $('#msg-compose').hidden = false; document.body.classList.add('msg-modal-open');
    loadDirectory('').then(function () {
      if (prefill) { var p = findPerson(prefill); if (p) addChip({ key: p.type + ':' + p.id, label: p.name, detail: p.detail, person: p }); }
    });
    setTimeout(function () { $('#msg-to-q').focus(); }, 30);
  }
  function closeCompose() {
    $('#msg-compose').hidden = true; $('#msg-picker').hidden = true; document.body.classList.remove('msg-modal-open');
  }
  var dirTimer = null;
  function loadDirectory(q) {
    return api('directory', { q: q }).then(function (j) { state.dir = j; renderPicker(); });
  }
  function findPerson(key) {
    var out = null; (state.dir && state.dir.groups || []).forEach(function (g) { g.people.forEach(function (p) { if (p.type + ':' + p.id === key) out = p; }); });
    return out;
  }
  function renderPicker() {
    var pk = $('#msg-picker'), d = state.dir; if (!d) return;
    var chosen = {}; state.chips.forEach(function (c) { chosen[c.key] = true; });
    var h = '';
    if (d.broadcast && d.broadcast.length) {
      h += '<div class="msg-pk-group"><div class="msg-pk-label">Envois groupés</div>' + d.broadcast.map(function (g) {
        return '<button type="button" class="msg-pk-item' + (chosen['g:' + g.key] ? ' is-chosen' : '') + '" data-gkey="' + esc(g.key) + '" data-glabel="' + esc(g.label) + '"><span class="msg-av" data-role="groupe"><i class="fas fa-users"></i></span>'
          + '<span><b>' + esc(g.label) + '</b><small>' + g.count + ' destinataire' + (g.count > 1 ? 's' : '') + ' · réponses privées</small></span></button>';
      }).join('') + '</div>';
    }
    d.groups.forEach(function (g) {
      h += '<div class="msg-pk-group"><div class="msg-pk-label">' + esc(g.label) + ' <span>' + g.people.length + '</span></div>' + g.people.map(function (p) {
        var k = p.type + ':' + p.id;
        return '<button type="button" class="msg-pk-item' + (chosen[k] ? ' is-chosen' : '') + '" data-pkey="' + k + '">' + avatar(p) + '<span><b>' + esc(p.name) + '</b><small>' + esc(p.detail) + '</small></span></button>';
      }).join('') + '</div>';
    });
    pk.innerHTML = h || '<div class="msg-pk-empty">Aucune personne trouvée parmi vos contacts autorisés.</div>';
  }
  function addChip(c) {
    if (state.chips.some(function (x) { return x.key === c.key; })) return;
    state.chips.push(c); renderChips(); renderPicker();
  }
  function renderChips() {
    $('#msg-chips').innerHTML = state.chips.map(function (c, i) {
      return '<span class="msg-chip' + (c.group ? ' is-group' : '') + '">' + esc(c.label) + '<button type="button" data-rmchip="' + i + '" aria-label="Retirer ' + esc(c.label) + '">×</button></span>';
    }).join('');
    var fam = state.chips.filter(function (c) { return c.group || (c.person && (c.person.type === 'eleve' || c.person.type === 'parent')); }).length;
    var note = $('#msg-to-note');
    if (state.chips.some(function (c) { return c.group; }) || fam > 1) { note.hidden = false; note.textContent = 'Envoi d\'information : les destinataires ne se verront pas entre eux et vous répondront en privé.'; }
    else if (state.chips.length > 1) { note.hidden = false; note.textContent = 'Discussion de groupe : chacun verra les réponses de tous.'; }
    else note.hidden = true;
  }
  function sendCompose(e) {
    e.preventDefault();
    var err = $('#msg-compose-error'); err.textContent = '';
    if (!state.chips.length) { err.textContent = 'Ajoutez au moins un destinataire.'; return; }
    if (!$('#msg-body').value.trim()) { err.textContent = 'Le message est vide.'; return; }
    var fd = new FormData();
    state.chips.forEach(function (c) { if (c.group) fd.append('groups[]', c.gkey); else fd.append('to[]', c.key); });
    fd.append('subject', $('#msg-subject').value); fd.append('body', $('#msg-body').value);
    if ($('#msg-status')) fd.append('status', $('#msg-status').value);
    state.composeFiles.forEach(function (f) { fd.append('files[]', f); });
    var btn = $('#msg-compose-form [type=submit]'); btn.disabled = true;
    api('send', null, fd).then(function (j) {
      closeCompose(); toast('Message envoyé');
      state.box = 'inbox'; selectBox('inbox');
      openConversation(j.id); return loadList();
    }).catch(function (x) { err.textContent = x.message; }).then(function () { btn.disabled = false; });
  }

  // ───────────────────────── actions ─────────────────────────
  function selectBox(box) {
    state.box = box;
    $$('.msg-box').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.box === box ? 'true' : 'false'); });
  }
  function flag(name) {
    var c = state.conv; if (!c) return;
    var on = name === 'archived' ? !c.archived : name === 'pinned' ? !c.pinned : name === 'muted' ? !c.muted : true;
    api('flag', null, { id: c.id, flag: name, on: on ? 1 : 0 }).then(function () {
      if (name === 'archived') { toast(on ? 'Conversation archivée' : 'Conversation replacée dans la boîte de réception'); }
      if (name === 'deleted') { toast('Conversation retirée de votre boîte'); }
      if (name === 'archived' || name === 'deleted') { closeThread(); loadList(); return; }
      c[name] = on; renderHead(); loadList();
    }).catch(function (e) { toast(e.message, 'error'); });
  }
  function closeThread() {
    state.convId = 0; state.conv = null; window.__activeConversationId = null;
    $('#msg-thread').hidden = true; $('#msg-placeholder').hidden = false; setView('list');
    history.replaceState(null, '', location.pathname);
    $$('.msg-item').forEach(function (b) { b.classList.remove('is-active'); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act],[data-id],[data-box],[data-pkey],[data-gkey],[data-rmchip],[data-rmfile]');
    if (!t) { if (!e.target.closest('#msg-to,#msg-picker')) $('#msg-picker').hidden = true; return; }
    if (t.dataset.id && t.classList.contains('msg-item')) { openConversation(t.dataset.id); return; }
    if (t.dataset.box) { selectBox(t.dataset.box); state.items = []; loadList(); return; }
    if (t.dataset.pkey) { var p = findPerson(t.dataset.pkey); if (p) addChip({ key: t.dataset.pkey, label: p.name, person: p }); var qi = $('#msg-to-q'); if (qi.value) { qi.value = ''; loadDirectory(''); } qi.focus(); $('#msg-picker').hidden = true; return; }
    if (t.dataset.gkey) { addChip({ key: 'g:' + t.dataset.gkey, gkey: t.dataset.gkey, label: t.dataset.glabel, group: true }); $('#msg-to-q').focus(); $('#msg-picker').hidden = true; return; }
    if (t.dataset.rmchip !== undefined) { state.chips.splice(+t.dataset.rmchip, 1); renderChips(); renderPicker(); return; }
    if (t.dataset.rmfile) { var a = t.dataset.rmfile.split(':'); (a[0] === 'reply' ? state.replyFiles : state.composeFiles).splice(+a[1], 1); renderFiles(a[0]); return; }
    switch (t.dataset.act) {
      case 'compose': openCompose(); break;
      case 'close': closeCompose(); break;
      case 'back': closeThread(); break;
      case 'more': loadList(true); break;
      case 'older': loadOlder(); break;
      case 'pin': flag('pinned'); break;
      case 'mute': flag('muted'); break;
      case 'archive': flag('archived'); break;
      case 'leave': if (t.dataset.confirm === '1') { flag('deleted'); } else { t.dataset.confirm = '1'; t.classList.add('is-confirm'); t.title = 'Cliquer encore pour confirmer'; toast('Cliquez à nouveau pour retirer la conversation de votre boîte'); setTimeout(function () { t.dataset.confirm = ''; t.classList.remove('is-confirm'); }, 4000); } break;
      case 'private':
        api('private', null, { id: state.convId }).then(function (j) { loadList(); openConversation(j.id); }).catch(function (x) { toast(x.message, 'error'); });
        break;
      case 'delmsg':
        if (t.dataset.confirm !== '1') { t.dataset.confirm = '1'; t.classList.add('is-confirm'); setTimeout(function () { t.dataset.confirm = ''; t.classList.remove('is-confirm'); }, 3500); toast('Cliquez à nouveau pour supprimer ce message'); break; }
        api('delete_message', null, { message_id: t.dataset.mid }).then(function () {
          state.messages.forEach(function (m) { if (m.id === +t.dataset.mid) { m.deleted = true; m.body = ''; m.attachments = []; } }); renderStream(false); loadList();
        }).catch(function (x) { toast(x.message, 'error'); });
        break;
    }
  });
  $('#msg-to-q').addEventListener('focus', function () { $('#msg-picker').hidden = false; });
  $('#msg-to-q').addEventListener('input', function (e) {
    $('#msg-picker').hidden = false; clearTimeout(dirTimer);
    var q = e.target.value.trim(); dirTimer = setTimeout(function () { loadDirectory(q); }, 220);
  });
  $('#msg-to-q').addEventListener('keydown', function (e) {
    if (e.key === 'Backspace' && !e.target.value && state.chips.length) { state.chips.pop(); renderChips(); renderPicker(); }
    if (e.key === 'Enter') { e.preventDefault(); var first = $('#msg-picker .msg-pk-item:not(.is-chosen)'); if (first) first.click(); }
    if (e.key === 'Escape') { $('#msg-picker').hidden = true; }
  });
  $('#msg-compose-form').addEventListener('submit', sendCompose);
  $('#msg-compose').addEventListener('click', function (e) { if (e.target.id === 'msg-compose') closeCompose(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('#msg-compose').hidden) closeCompose(); });
  $('#msg-reply').addEventListener('submit', sendReply);
  var replyBody = $('#msg-reply-body');
  replyBody.addEventListener('input', function () {
    autosize(replyBody);
    var now = Date.now();
    if (state.convId && window.wsGlobal && now - state.lastTypingSent > 2500) { state.lastTypingSent = now; window.wsGlobal.emit('typing', { conversationId: state.convId }); }
  });
  replyBody.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('#msg-reply').requestSubmit(); } });
  $$('input[type=file][data-files]').forEach(function (inp) { inp.addEventListener('change', function () { addFiles(inp.dataset.files, inp.files); inp.value = ''; }); });
  var qTimer = null;
  $('#msg-q').addEventListener('input', function (e) { clearTimeout(qTimer); qTimer = setTimeout(function () { state.q = e.target.value.trim(); state.items = []; loadList(); }, 250); });
  $('#msg-stream').addEventListener('scroll', function (e) { if (e.target.scrollTop < 60) loadOlder(); });

  // ───────────────────────── temps réel ─────────────────────────
  document.addEventListener('fronote:message', function (ev) {
    var d = ev.detail || {};
    if (+d.conversationId === state.convId) fetchNew(); else loadList();
    if (+d.conversationId === state.convId) loadList();
  });
  document.addEventListener('fronote:read', function (ev) {
    var d = ev.detail || {};
    if (+d.conversationId !== state.convId || !state.conv) return;
    state.conv.participants.forEach(function (p) { if (p.id === +d.userId && p.type === d.userType) p.last_read = +d.lastReadMessageId; });
    renderSeen();
  });
  document.addEventListener('fronote:typing', function (ev) {
    var d = ev.detail || {};
    if (+d.conversationId !== state.convId) return;
    var who = null; (state.conv ? state.conv.participants : []).forEach(function (p) { if (p.id === +d.userId && p.type === d.userType) who = p; });
    if (who && who.me) return;
    var el = $('#msg-typing'); el.hidden = false; el.textContent = (who ? who.first || who.name : 'Quelqu\'un') + ' est en train d\'écrire…';
    clearTimeout(state.typingTimer); state.typingTimer = setTimeout(function () { el.hidden = true; }, 4000);
  });
  document.addEventListener('fronote:message-updated', function (ev) { if (+(ev.detail || {}).conversationId === state.convId) openConversation(state.convId, false); });
  // Repli si le temps réel est indisponible : lecture légère toutes les 20 s (onglet visible uniquement)
  setInterval(function () {
    if (document.hidden || (window.wsGlobal && window.wsGlobal.isConnected && window.wsGlobal.isConnected())) return;
    fetchNew(); if (!state.loadingList && !state.q) loadList();
  }, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && state.convId) fetchNew(); });

  // Hauteur : toute la place visible sous la barre du haut (et au-dessus de la barre mobile du bas).
  function fit() {
    var top = app.getBoundingClientRect().top;
    // barre fixe en bas d'écran (navigation mobile de l'application) : on la détecte par ce qui est réellement affiché
    var bottomBar = 0;
    [0.25, 0.5, 0.75].forEach(function (fx) {
      var el = document.elementFromPoint(window.innerWidth * fx, window.innerHeight - 4);
      while (el && el !== document.body) {
        if (app.contains(el)) break;
        var cs = getComputedStyle(el);
        if (cs.position === 'fixed' || cs.position === 'sticky') { var r = el.getBoundingClientRect(); if (r.height < 160) bottomBar = Math.max(bottomBar, window.innerHeight - r.top); break; }
        el = el.parentElement;
      }
    });
    var margin = window.innerWidth > 860 ? 16 : 0;
    app.style.height = Math.max(420, window.innerHeight - top - bottomBar - margin) + 'px';
  }
  window.addEventListener('resize', fit); fit(); setTimeout(fit, 300);

  // ───────────────────────── démarrage ─────────────────────────
  loadList().then(function () {
    if (CFG.open) openConversation(CFG.open);
    if (CFG.compose) openCompose(CFG.prefill);
  });
})();
