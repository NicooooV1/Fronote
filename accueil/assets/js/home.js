/* Fronote — accueil : personnalisation (masquer / réordonner) et devoirs faits. */
(function () {
  'use strict';
  var root = document.getElementById('hm');
  if (!root) return;
  var API = root.dataset.api;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var panel = document.getElementById('hm-custom'), list = document.getElementById('hm-custom-list'), grid = document.getElementById('hm-grid');
  var initial = Array.prototype.map.call(list.children, function (li) { return li.dataset.key; });

  function post(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { var v = data[k]; if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v); });
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'Erreur'); return j; });
  }
  function apply() {
    Array.prototype.forEach.call(list.children, function (li, i) {
      var card = grid.querySelector('.hm-card[data-key="' + li.dataset.key + '"]');
      if (!card) return;
      card.hidden = !li.querySelector('input').checked;
      card.style.order = String(i);
      card.parentNode.appendChild(card);
    });
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-hm],[data-move]'); if (!b) return;
    if (b.dataset.move) {
      var li = b.closest('li'), d = +b.dataset.move;
      if (d < 0 && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
      if (d > 0 && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
      apply(); return;
    }
    if (b.dataset.hm === 'customize') { panel.hidden = !panel.hidden; b.setAttribute('aria-expanded', String(!panel.hidden)); return; }
    if (b.dataset.hm === 'reset') {
      var base = grid.dataset.defaultOrder ? grid.dataset.defaultOrder.split(',') : initial;
      base.forEach(function (k) { var li = list.querySelector('li[data-key="' + k + '"]'); if (li) { li.querySelector('input').checked = true; list.appendChild(li); } });
      apply(); return;
    }
    if (b.dataset.hm === 'save') {
      var order = [], hidden = [];
      Array.prototype.forEach.call(list.children, function (li) { order.push(li.dataset.key); if (!li.querySelector('input').checked) hidden.push(li.dataset.key); });
      b.disabled = true;
      post({ action: 'prefs', order: order, hidden: hidden }).then(function () { panel.hidden = true; root.querySelector('[data-hm="customize"]').setAttribute('aria-expanded', 'false'); })
        .catch(function (x) { alert(x.message); }).then(function () { b.disabled = false; });
    }
  });
  list.addEventListener('change', apply);

  // Devoirs : case « fait »
  root.addEventListener('change', function (e) {
    var cb = e.target.closest('input[data-devoir]'); if (!cb) return;
    var item = cb.closest('.hm-dv-item');
    item.classList.toggle('is-done', cb.checked);
    post({ action: 'devoir', id: cb.dataset.devoir, fait: cb.checked ? 1 : 0 }).catch(function () { cb.checked = !cb.checked; item.classList.toggle('is-done', cb.checked); });
  });
})();
