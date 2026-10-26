/**
 * Réunions (refonte 4.6) — comportements : comptes à rebours, minuteurs de séance, actions
 * (api.php), sauvegarde automatique des notes, réservation de créneaux, formulaire de planification.
 */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    // ── Petites notifications ──
    function toast(msg, bad) {
        var t = document.createElement('div');
        t.className = 'rn-toast' + (bad ? ' is-bad' : '');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('is-out'); }, 2600);
        setTimeout(function () { t.remove(); }, 3100);
    }

    // ── Appel de l'API ──
    function ctx(el) {
        var holder = el.closest('[data-rn-id]') || $('[data-rn-id]');
        var tok = el.closest('[data-csrf]') || $('[data-csrf]');
        return { id: holder ? holder.getAttribute('data-rn-id') : '', csrf: tok ? tok.getAttribute('data-csrf') : '' };
    }
    // Les appels sont mis en file : le jeton CSRF est à usage unique et tourne à chaque réponse.
    var queue = Promise.resolve();
    function api(el, params) {
        var run = function () { return send(el, params); };
        var p = queue.then(run, run);
        queue = p.catch(function () {});
        return p;
    }
    function send(el, params) {
        var c = ctx(el);
        var body = params instanceof FormData ? params : new FormData();
        if (!(params instanceof FormData)) { Object.keys(params).forEach(function (k) { body.append(k, params[k]); }); }
        body.append('id', c.id);
        body.append('csrf_token', c.csrf);
        return fetch('api.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
                var next = r.headers.get('X-Csrf-Token-Next');
                if (next) { $$('[data-csrf]').forEach(function (n) { n.setAttribute('data-csrf', next); }); }
                return r.json().catch(function () { return { ok: false, message: 'Réponse inattendue du serveur.' }; });
            });
    }
    function after(res, reload) {
        if (res.redirect) { location.href = res.redirect; return; }
        if (!res.ok) { toast(res.message || 'Action impossible.', true); return; }
        if (res.message) { toast(res.message); }
        if (reload !== false) { setTimeout(function () { location.reload(); }, res.message ? 650 : 0); }
    }

    // ── Temps : comptes à rebours et minuteurs ──
    function fmtDur(sec) {
        sec = Math.max(0, Math.floor(sec));
        var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
        return (h ? h + ' h ' + String(m).padStart(2, '0') : m) + ' min' + (h ? '' : ' ' + String(s).padStart(2, '0') + ' s');
    }
    function rel(target, end) {
        var now = Date.now();
        if (now >= target && now <= end) { return 'en ce moment'; }
        var d = Math.round((target - now) / 1000), a = Math.abs(d);
        var txt = a >= 86400 ? Math.round(a / 86400) + ' j' : a >= 3600 ? Math.floor(a / 3600) + ' h ' + String(Math.floor(a % 3600 / 60)).padStart(2, '0') : Math.max(1, Math.floor(a / 60)) + ' min';
        return (d > 0 ? 'dans ' : 'il y a ') + txt;
    }
    function tick() {
        $$('[data-rn-countdown]').forEach(function (el) {
            var t = Date.parse(el.getAttribute('data-rn-countdown')), e = Date.parse(el.getAttribute('data-rn-end') || el.getAttribute('data-rn-countdown'));
            var tgt = el.querySelector('.rn-hero__count-val') || el;
            tgt.textContent = rel(t, e);
        });
        $$('[data-rn-since]').forEach(function (el) {
            var sec = (Date.now() - Date.parse(el.getAttribute('data-rn-since'))) / 1000;
            var budget = parseInt(el.getAttribute('data-rn-budget') || '0', 10) * 60;
            el.textContent = fmtDur(sec) + (budget ? ' / ' + Math.round(budget / 60) + ' min' : '');
            el.classList.toggle('is-over', budget > 0 && sec > budget);
            el.classList.toggle('is-near', budget > 0 && sec > budget * 0.8 && sec <= budget);
        });
    }
    tick(); setInterval(tick, 1000);

    // ── Actions ──
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rn-action]');
        if (!b) { return; }
        var act = b.getAttribute('data-rn-action');
        if (act === 'ouvrir_cloture') { var dlg = $('#rn-cloture'); if (dlg) { dlg.showModal(); } return; }
        if (b.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) { return; }
        var p = { action: act };
        ['reponse', 'point', 'champ', 'valeur', 'participant', 'presence', 'resa'].forEach(function (k) { if (b.dataset[k] !== undefined) { p[k] = b.dataset[k]; } });
        if (act === 'presence' && b.classList.contains('is-on')) { p.presence = ''; } // second clic = effacer
        b.disabled = true;
        api(b, p).then(function (res) {
            b.disabled = false;
            if (act === 'presence' && res.ok) {
                // Mise à jour sur place : pas de rechargement pendant la séance
                $$('button', b.parentNode).forEach(function (x) { x.className = ''; x.setAttribute('aria-pressed', 'false'); });
                if (p.presence) { b.className = 'is-on is-' + p.presence; b.setAttribute('aria-pressed', 'true'); }
                return;
            }
            after(res);
        }).catch(function () { b.disabled = false; toast('Réseau indisponible.', true); });
    });

    // ── Formulaires (ajout de point, compte rendu, clôture, réservation) ──
    document.addEventListener('submit', function (e) {
        var f = e.target.closest('[data-rn-form]');
        if (!f) { return; }
        e.preventDefault();
        var fd = new FormData(f);
        fd.append('action', f.getAttribute('data-rn-form'));
        var btn = f.querySelector('[type=submit]'); if (btn) { btn.disabled = true; }
        api(f, fd).then(function (res) {
            if (btn) { btn.disabled = false; }
            if (!res.ok) { var msg = f.querySelector('.rn-form-error') || document.createElement('p'); msg.className = 'rn-form-error'; msg.textContent = res.message || 'Action impossible.'; f.appendChild(msg); return; }
            var dlg = f.closest('dialog'); if (dlg && !res.redirect) { dlg.close(); }
            after(res);
        });
    });
    document.addEventListener('click', function (e) {
        var c = e.target.closest('[data-rn-close]');
        if (c) { var d = c.closest('dialog'); if (d) { d.close(); } }
    });

    // ── Notes de séance : sauvegarde automatique ──
    var timers = {};
    document.addEventListener('input', function (e) {
        var ta = e.target.closest('textarea[data-rn-point]');
        if (!ta) { return; }
        var key = ta.dataset.rnPoint + ta.dataset.rnChamp;
        var info = ta.closest('.rn-focus__notes') && ta.closest('.rn-focus__notes').querySelector('.rn-saved');
        if (info) { info.textContent = '…'; }
        clearTimeout(timers[key]);
        timers[key] = setTimeout(function () {
            api(ta, { action: 'maj_point', point: ta.dataset.rnPoint, champ: ta.dataset.rnChamp, valeur: ta.value }).then(function (res) {
                if (info) { info.textContent = res.ok ? 'Enregistré' : 'Non enregistré'; }
            });
        }, 700);
    });

    // ── Réservation d'un créneau (familles) ──
    document.addEventListener('click', function (e) {
        var s = e.target.closest('[data-rn-book]');
        if (!s) { return; }
        var dlg = $('#rn-book'); if (!dlg) { return; }
        var eleves = JSON.parse(s.getAttribute('data-eleves') || '[]');
        var sel = dlg.querySelector('select[name=eleve]');
        sel.innerHTML = '';
        eleves.forEach(function (x) { var o = document.createElement('option'); o.value = x.id; o.textContent = x.nom; sel.appendChild(o); });
        sel.closest('label').hidden = eleves.length < 2;
        dlg.querySelector('[name=creneau]').value = s.getAttribute('data-rn-book');
        dlg.querySelector('.rn-book__label').textContent = s.getAttribute('data-label');
        var err = dlg.querySelector('.rn-form-error'); if (err) { err.remove(); }
        dlg.showModal();
    });

    // ── Planifier : participants proposés, ordre du jour, titre ──
    var plan = $('[data-rn-plan]');
    if (plan) {
        var type = plan.getAttribute('data-rn-plan');
        var people = $('[data-rn-people]', plan);
        var titre = plan.querySelector('[name=titre]');
        var titreAuto = true;
        titre.addEventListener('input', function () { titreAuto = false; });
        var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var refresh = function (eleveLabel) {
            var classe = plan.querySelector('[name=classe]'), eleve = plan.querySelector('[name=eleve_id]');
            var c = classe ? classe.value : '', el = eleve ? eleve.value : '';
            if (titreAuto) {
                var base = titre.getAttribute('data-rn-autotitle');
                titre.value = base + (c ? ' — ' + c : '') + (eleveLabel ? ' — ' + eleveLabel : '');
            }
            if (!people) { return; }
            fetch('planifier.php?proposer=1&type=' + encodeURIComponent(type) + '&classe=' + encodeURIComponent(c) + '&eleve=' + encodeURIComponent(el), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    people.innerHTML = d.participants.map(function (p) {
                        var v = p.user_type + ':' + p.user_id + ':' + p.role;
                        return '<li><label class="rn-check"><input type="checkbox" name="p[]" value="' + esc(v) + '" checked> <b>' + esc(p.nom) + '</b> <small>' + esc(p.role_lib) + '</small></label></li>';
                    }).join('') || '<li class="rn-muted">Aucun participant proposé : vous serez seul·e convoqué·e.</li>';
                    var cnt = $('[data-rn-pcount]', plan); if (cnt) { cnt.textContent = d.participants.length + ' proposés'; }
                    var hint = $('[data-rn-phint]', plan); if (hint && d.participants.length) { hint.textContent = 'Décochez les personnes à ne pas convoquer.'; }
                });
        };
        plan.addEventListener('change', function (e) { if (e.target.matches('select[data-rn-propose]')) { refresh(); } });
        plan.addEventListener('eleve:change', function (e) { refresh(e.detail ? e.detail.label : ''); });
        refresh();
        var list = $('[data-rn-points]', plan);
        var total = function () {
            if (!list) { return; }
            var t = $$('input[name="pt_duree[]"]', list).reduce(function (a, i) { return a + (parseInt(i.value, 10) || 0); }, 0);
            var el = $('[data-rn-total]', plan); if (el) { el.textContent = t + ' min'; }
        };
        if (list) {
            list.addEventListener('input', total); total();
            list.addEventListener('click', function (e) { var b = e.target.closest('[data-rn-rm]'); if (b && list.children.length > 1) { b.closest('li').remove(); total(); } });
            $('[data-rn-addpt]', plan).addEventListener('click', function () {
                var li = list.lastElementChild.cloneNode(true);
                li.querySelector('[name="pt_titre[]"]').value = ''; li.querySelector('[name="pt_duree[]"]').value = '10';
                list.appendChild(li); li.querySelector('input').focus(); total();
            });
        }
    }
})();
