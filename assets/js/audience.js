/* Fronote — mesure d'audience interne. Chargé UNIQUEMENT après consentement (cookie-consent.js).
 * Envoie une page vue au chargement, puis la durée passée sur la page à la sortie. Rien d'autre. */
(function () {
  'use strict';
  if (window.__fronoteAudience) return;
  window.__fronoteAudience = true;
  var me = document.getElementById('fronote-audience') || document.currentScript;
  var endpoint = (me && me.getAttribute('data-endpoint')) || ((window.FRONOTE_BASE_URL || '/') + 'API/endpoints/audience.php');
  var start = Date.now(), hiddenFor = 0, hiddenAt = 0, sent = false;
  function payload(withDuration) {
    var fd = new FormData();
    fd.append('u', location.pathname);
    fd.append('vw', String(Math.round(window.innerWidth || 0)));
    if (withDuration) fd.append('d', String(Math.round((Date.now() - start - hiddenFor) / 1000)));
    return fd;
  }
  function beacon(fd) {
    if (navigator.sendBeacon) { navigator.sendBeacon(endpoint, fd); return; }
    fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
  }
  beacon(payload(false));
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); } else if (hiddenAt) { hiddenFor += Date.now() - hiddenAt; hiddenAt = 0; }
  });
  window.addEventListener('pagehide', function () {
    if (sent) return; sent = true;
    var d = (Date.now() - start - hiddenFor) / 1000;
    if (d >= 2) beacon(payload(true));
  });
})();
