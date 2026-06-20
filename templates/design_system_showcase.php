<?php
/**
 * Fronote — Vitrine du Design System (corps réutilisable).
 * Inclus par platform/design-system.php (monde plateforme) et admin/design-system.php
 * (monde établissement). La page hôte fournit <html>/<head> avec design-system.css +
 * assets/js/ui/interactions.js chargés. Aucun style codé en dur : tout via .ds-* + tokens.
 */
$dsScope = $dsScope ?? 'tenant'; // 'platform' | 'tenant'
?>
<div class="ds-showcase ds-<?= htmlspecialchars($dsScope) ?>" id="dsShowcaseRoot">

  <!-- Barre de contrôle (sélecteurs) -->
  <div class="ds-card" style="margin-bottom:var(--space-6)">
    <div class="ds-card__header"><h2 class="ds-card__title">Design System — vitrine</h2></div>
    <div class="ds-card__body">
      <div class="ds-row ds-gap-4" style="flex-wrap:wrap;align-items:center">
        <div class="ds-stack" style="gap:6px">
          <span class="ds-muted">Thème</span>
          <div class="ds-row ds-gap-2">
            <button class="ds-btn ds-btn--soft ds-btn--sm" data-ds-set-theme="light">Clair</button>
            <button class="ds-btn ds-btn--soft ds-btn--sm" data-ds-set-theme="dark">Sombre</button>
            <button class="ds-btn ds-btn--soft ds-btn--sm" data-ds-set-theme="liquid">Liquide</button>
            <button class="ds-btn ds-btn--ghost ds-btn--sm" data-ds-set-theme="auto">Auto</button>
          </div>
        </div>
        <div class="ds-stack" style="gap:6px">
          <span class="ds-muted">Animations</span>
          <button class="ds-switch is-on" role="switch" aria-checked="true" id="swMotion" title="Animations"></button>
        </div>
        <div class="ds-stack" style="gap:6px">
          <span class="ds-muted">Transparence</span>
          <button class="ds-switch is-on" role="switch" aria-checked="true" id="swTransp" title="Transparence (liquide)"></button>
        </div>
        <div class="ds-stack" style="gap:6px">
          <span class="ds-muted">Aperçu</span>
          <div class="ds-row ds-gap-2">
            <button class="ds-btn ds-btn--soft ds-btn--sm" data-ds-preview="desktop">Desktop</button>
            <button class="ds-btn ds-btn--soft ds-btn--sm" data-ds-preview="mobile">Mobile</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="dsPreviewFrame">

  <!-- Boutons -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Boutons</h3></div>
    <div class="ds-card__body ds-row ds-gap-2" style="flex-wrap:wrap">
      <button class="ds-btn ds-btn--primary">Primary</button>
      <button class="ds-btn ds-btn--secondary">Secondary</button>
      <button class="ds-btn ds-btn--ghost">Ghost</button>
      <button class="ds-btn ds-btn--soft">Soft</button>
      <button class="ds-btn ds-btn--success">Success</button>
      <button class="ds-btn ds-btn--warning">Warning</button>
      <button class="ds-btn ds-btn--danger">Danger</button>
      <button class="ds-btn ds-btn--link">Link</button>
      <button class="ds-btn ds-btn--primary" disabled>Disabled</button>
      <button class="ds-btn ds-btn--icon" aria-label="Icône"><i class="fas fa-gear"></i></button>
      <button class="ds-btn ds-btn--primary" id="dsBtnLoadingDemo">Enregistrer</button>
    </div>
  </section>

  <!-- Switches -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Switches On / Off</h3></div>
    <div class="ds-card__body ds-row ds-gap-6" style="flex-wrap:wrap;align-items:center">
      <button class="ds-switch" role="switch" aria-checked="false" title="Off"></button>
      <button class="ds-switch is-on" role="switch" aria-checked="true" title="On"></button>
      <button class="ds-switch is-disabled" role="switch" aria-checked="false" disabled title="Désactivé"></button>
      <label class="ds-row ds-gap-2" style="align-items:center"><button class="ds-switch" role="switch" aria-checked="false"></button> <span>Activer le module</span></label>
    </div>
  </section>

  <!-- Formulaires -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Formulaires</h3></div>
    <div class="ds-card__body ds-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--space-4)">
      <div class="ds-field"><label class="ds-label">Nom</label><input class="ds-input" placeholder="Saisir…"></div>
      <div class="ds-field"><label class="ds-label">Email</label><input class="ds-input" type="email" value="contact@fronote.local"></div>
      <div class="ds-field has-error"><label class="ds-label">Mot de passe</label><input class="ds-input" type="password" value="x"><span class="ds-error-text">Trop court (12 caractères min.)</span></div>
      <div class="ds-field has-success"><label class="ds-label">Identifiant</label><input class="ds-input" value="admin"><span class="ds-help">Disponible</span></div>
      <div class="ds-field"><label class="ds-label">Type</label><select class="ds-select"><option>Collège</option><option>Lycée</option><option>Primaire</option></select></div>
      <div class="ds-field"><label class="ds-label">Note</label><textarea class="ds-textarea" rows="2" placeholder="Message…"></textarea></div>
    </div>
  </section>

  <!-- Dropdown + Tabs -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Liste déroulante &amp; onglets</h3></div>
    <div class="ds-card__body ds-row ds-gap-6" style="flex-wrap:wrap;align-items:flex-start">
      <div class="ds-dropdown">
        <button class="ds-btn ds-btn--secondary ds-dropdown-toggle" data-ds-dropdown-toggle aria-expanded="false">Menu <i class="fas fa-chevron-down"></i></button>
        <div class="ds-dropdown-menu">
          <button class="ds-dropdown-item is-active"><i class="fas fa-check"></i> Option active</button>
          <button class="ds-dropdown-item">Deuxième option</button>
          <button class="ds-dropdown-item">Troisième option</button>
          <div class="ds-dropdown-divider"></div>
          <button class="ds-dropdown-item is-disabled" disabled>Désactivée</button>
        </div>
      </div>
      <div class="ds-tabs-scope" style="flex:1;min-width:260px">
        <div class="ds-tabs" role="tablist">
          <button class="ds-tab is-active" data-ds-tab="t1" role="tab" aria-selected="true">Vue d'ensemble</button>
          <button class="ds-tab" data-ds-tab="t2" role="tab" aria-selected="false">Détails</button>
          <button class="ds-tab" data-ds-tab="t3" role="tab" aria-selected="false">Journal</button>
        </div>
        <div class="ds-tabpanel is-active" data-ds-panel="t1">Contenu de la vue d'ensemble.</div>
        <div class="ds-tabpanel" data-ds-panel="t2">Contenu des détails.</div>
        <div class="ds-tabpanel" data-ds-panel="t3">Contenu du journal.</div>
      </div>
    </div>
  </section>

  <!-- Cartes -->
  <section style="margin-bottom:var(--space-5)">
    <h3 style="margin:0 0 var(--space-3)">Cartes</h3>
    <div class="ds-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-4)">
      <div class="ds-card ds-card--interactive ds-card--stat"><span class="ds-muted">Élèves</span><div style="font-size:var(--fs-2xl);font-weight:var(--fw-bold)">312</div><span class="ds-badge ds-badge--success">+3%</span></div>
      <div class="ds-card ds-card--interactive ds-card--stat"><span class="ds-muted">CPU</span><div style="font-size:var(--fs-2xl);font-weight:var(--fw-bold)">42%</div><span class="ds-badge ds-badge--success">normal</span></div>
      <div class="ds-card ds-card--interactive ds-card--stat"><span class="ds-muted">Tickets</span><div style="font-size:var(--fs-2xl);font-weight:var(--fw-bold)">7</div><span class="ds-badge ds-badge--warning">à traiter</span></div>
      <div class="ds-card ds-card--interactive ds-card--stat"><span class="ds-muted">Erreurs 500</span><div style="font-size:var(--fs-2xl);font-weight:var(--fw-bold)">0</div><span class="ds-badge ds-badge--success">OK</span></div>
    </div>
  </section>

  <!-- Badges + Alertes -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Badges &amp; alertes</h3></div>
    <div class="ds-card__body">
      <div class="ds-row ds-gap-2" style="flex-wrap:wrap;margin-bottom:var(--space-4)">
        <span class="ds-badge ds-badge--primary">Primary</span>
        <span class="ds-badge ds-badge--success">Activé</span>
        <span class="ds-badge ds-badge--warning">Attention</span>
        <span class="ds-badge ds-badge--danger">Critique</span>
        <span class="ds-badge ds-badge--info">Info</span>
        <span class="ds-badge ds-badge--neutral">Neutre</span>
      </div>
      <div class="ds-stack" style="gap:var(--space-2)">
        <div class="ds-alert ds-alert--success"><i class="fas fa-circle-check"></i><div><strong>Enregistré</strong><div>Vos modifications ont été sauvegardées.</div></div></div>
        <div class="ds-alert ds-alert--warning"><i class="fas fa-triangle-exclamation"></i><div><strong>Session support active</strong><div>Un technicien accède à l'établissement.</div></div></div>
        <div class="ds-alert ds-alert--danger"><i class="fas fa-circle-xmark"></i><div><strong>Erreur</strong><div>Impossible de contacter le serveur.</div></div></div>
      </div>
    </div>
  </section>

  <!-- Tableau -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Tableau</h3></div>
    <div class="ds-card__body">
      <table class="ds-table ds-table--cards">
        <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th></tr></thead>
        <tbody>
          <tr><td data-label="Utilisateur">admin</td><td data-label="Rôle"><span class="ds-badge ds-badge--primary">administration</span></td><td data-label="Statut"><span class="ds-badge ds-badge--success">actif</span></td><td data-label="Dernière connexion">aujourd'hui</td></tr>
          <tr><td data-label="Utilisateur">m.dupont</td><td data-label="Rôle"><span class="ds-badge ds-badge--neutral">professeur</span></td><td data-label="Statut"><span class="ds-badge ds-badge--success">actif</span></td><td data-label="Dernière connexion">hier</td></tr>
          <tr><td data-label="Utilisateur">p.martin</td><td data-label="Rôle"><span class="ds-badge ds-badge--neutral">parent</span></td><td data-label="Statut"><span class="ds-badge ds-badge--warning">inactif</span></td><td data-label="Dernière connexion">—</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Modale + Toasts + Skeleton -->
  <section class="ds-card" style="margin-bottom:var(--space-5)">
    <div class="ds-card__header"><h3 class="ds-card__title">Modale, toasts &amp; chargement</h3></div>
    <div class="ds-card__body ds-row ds-gap-2" style="flex-wrap:wrap">
      <button class="ds-btn ds-btn--primary" data-ds-modal-open="dsDemoModal">Ouvrir une modale</button>
      <button class="ds-btn ds-btn--soft" data-ds-toast="success">Toast succès</button>
      <button class="ds-btn ds-btn--soft" data-ds-toast="error">Toast erreur</button>
      <button class="ds-btn ds-btn--soft" data-ds-toast="warning">Toast avertissement</button>
      <button class="ds-btn ds-btn--soft" data-ds-toast="info">Toast info</button>
    </div>
    <div class="ds-card__body">
      <div class="ds-stack" style="gap:var(--space-2);max-width:420px">
        <div class="ds-skeleton ds-skeleton--title"></div>
        <div class="ds-skeleton ds-skeleton--text"></div>
        <div class="ds-skeleton ds-skeleton--text" style="width:70%"></div>
      </div>
    </div>
  </section>

  </div><!-- /#dsPreviewFrame -->

  <!-- Modale de démonstration -->
  <div class="ds-modal-overlay" id="dsDemoModal">
    <div class="ds-modal ds-modal--danger" role="dialog" aria-modal="true">
      <div class="ds-modal__header"><i class="fas fa-triangle-exclamation"></i><h3>Désactiver ce compte ?</h3><button class="ds-modal__close" data-ds-modal-close aria-label="Fermer">&times;</button></div>
      <div class="ds-modal__body">Cette action bloquera immédiatement l'accès de l'utilisateur.</div>
      <div class="ds-modal__footer"><button class="ds-btn ds-btn--ghost" data-ds-modal-close>Annuler</button><button class="ds-btn ds-btn--danger" data-ds-modal-close>Désactiver</button></div>
    </div>
  </div>

  <!-- Bottom bar (démo) + retour haut -->
  <nav class="ds-bottom-bar is-visible" aria-label="Navigation rapide (démo)">
    <a class="ds-bottom-bar-item is-active"><i class="fas fa-house"></i><span>Accueil</span></a>
    <a class="ds-bottom-bar-item"><i class="fas fa-calendar"></i><span>Agenda</span></a>
    <a class="ds-bottom-bar-item"><i class="fas fa-chart-column"></i><span>Notes</span></a>
    <a class="ds-bottom-bar-item"><i class="fas fa-envelope"></i><span>Messages</span></a>
    <a class="ds-bottom-bar-item"><i class="fas fa-user"></i><span>Profil</span></a>
  </nav>
  <button class="ds-back-to-top" aria-label="Retour en haut"><i class="fas fa-arrow-up"></i></button>
</div>

<script>
(function () {
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
  ready(function () {
    var UI = window.FronoteUI || {};
    // Sélecteur de thème
    document.querySelectorAll('[data-ds-set-theme]').forEach(function (b) {
      b.addEventListener('click', function () { if (UI.setTheme) UI.setTheme(b.getAttribute('data-ds-set-theme')); });
    });
    // Animations / transparence (switches dédiés)
    var swM = document.getElementById('swMotion'), swT = document.getElementById('swTransp');
    if (swM) swM.addEventListener('ds:toggle', function (e) { if (UI.setReducedMotion) UI.setReducedMotion(!e.detail.on); });
    if (swT) swT.addEventListener('ds:toggle', function (e) { if (UI.setReducedTransparency) UI.setReducedTransparency(!e.detail.on); });
    // Aperçu desktop/mobile
    var frame = document.getElementById('dsPreviewFrame');
    document.querySelectorAll('[data-ds-preview]').forEach(function (b) {
      b.addEventListener('click', function () { frame.classList.toggle('ds-preview-mobile', b.getAttribute('data-ds-preview') === 'mobile'); });
    });
    // Toasts
    document.querySelectorAll('[data-ds-toast]').forEach(function (b) {
      b.addEventListener('click', function () { if (UI.toast) UI.toast('Exemple de notification ' + b.getAttribute('data-ds-toast'), b.getAttribute('data-ds-toast')); });
    });
    // Bouton loading→success
    var lb = document.getElementById('dsBtnLoadingDemo');
    if (lb) lb.addEventListener('click', function () {
      if (lb.classList.contains('is-loading')) return;
      lb.classList.add('is-loading');
      setTimeout(function () { lb.classList.remove('is-loading'); lb.classList.add('has-success'); if (UI.toast) UI.toast('Enregistré', 'success'); setTimeout(function(){ lb.classList.remove('has-success'); }, 1500); }, 1200);
    });
  });
})();
</script>
<style>
#dsPreviewFrame.ds-preview-mobile { max-width: 420px; margin: 0 auto; border: 1px dashed var(--border-medium); border-radius: var(--radius-lg); padding: var(--space-4); }
</style>
