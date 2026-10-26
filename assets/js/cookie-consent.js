/* Fronote — consentement cookies (CNIL) : refuser aussi simple qu'accepter, rien avant choix. */
(function () {
  'use strict';
  var box = document.getElementById('cc');
  if (!box) return;
  var details = document.getElementById('cc-details');
  var custom = box.querySelector('[data-cc="customize"]'), save = box.querySelector('[data-cc="save"]');

  function loadAudience() {
    if (window.__fronoteAudience) return;
    var s = document.createElement('script');
    s.src = box.dataset.audienceSrc; s.defer = true;
    s.setAttribute('data-endpoint', box.dataset.audienceEndpoint);
    s.id = 'fronote-audience';
    document.head.appendChild(s);
  }
  function send(choice, analytics) {
    var fd = new FormData();
    fd.append('choice', choice); fd.append('analytics', analytics ? '1' : '0');
    return fetch(box.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) throw new Error('refus');
        box.dataset.analytics = j.analytics ? '1' : '0';
        close();
        if (j.analytics) loadAudience();
        else if (window.__fronoteAudience) location.reload(); // retrait : on recharge pour couper la mesure en cours
      }).catch(function () { close(); });
  }
  function open(expand) {
    box.hidden = false;
    document.getElementById('cc-analytics').checked = box.dataset.analytics === '1';
    toggleDetails(!!expand);
    setTimeout(function () { var b = box.querySelector('.cc-btn'); if (b) b.focus(); }, 50);
  }
  function close() { box.hidden = true; }
  function toggleDetails(show) {
    details.hidden = !show; custom.hidden = show; save.hidden = !show;
    custom.setAttribute('aria-expanded', String(show));
    box.classList.toggle('is-expanded', show);
  }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cc]'); if (!b) return;
    var a = b.dataset.cc;
    if (a === 'customize') { toggleDetails(true); return; }
    if (a === 'save') { var on = document.getElementById('cc-analytics').checked; send(on ? 'custom' : 'withdraw', on); return; }
    send(a, a === 'accept_all');
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-cc-open]');
    if (t) { e.preventDefault(); open(true); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden && box.classList.contains('is-expanded')) toggleDetails(false); });
  if (box.dataset.analytics === '1' && box.hidden) loadAudience();
  window.FronoteConsent = { open: open };
})();
