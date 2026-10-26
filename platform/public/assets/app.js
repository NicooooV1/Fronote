// Confirmation des actions sensibles (boutons data-confirm) — sans JS inline (CSP stricte).
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-confirm]');
  if (b && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
});
