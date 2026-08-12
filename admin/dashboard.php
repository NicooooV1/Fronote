<?php
declare(strict_types=1);
/**
 * Tableau de bord DIRECTION (refonte UX, cahier §21.1).
 * Présentation via le design system (.ds-*) ; données via le service existant
 * AdminDashboardService (scopé établissement + caché). Garde 3-mondes conservée.
 */
require_once __DIR__ . '/../API/core.php';
require_once __DIR__ . '/includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view');

$dash   = app('admin_dashboard');
$users  = $dash->getUserCounts();
$kpi    = $dash->getKPIs();
$alerts = $dash->getAlerts();
$mods   = $dash->getModuleStats();
$extra  = $dash->getExtraCounts();
$logins = $dash->getRecentLogins(8);

$etab = \API\Core\EstablishmentContext::id();
$supportOpen = 0;
try {
    $st = getPDO()->prepare("SELECT COUNT(*) FROM support_tickets WHERE establishment_id = ? AND status NOT IN ('resolved','closed','cancelled')");
    $st->execute([$etab]); $supportOpen = (int) $st->fetchColumn();
} catch (\Throwable $e) { /* table support absente : 0 */ }

$lockedCount   = is_array($alerts['locked_accounts'] ?? null) ? count($alerts['locked_accounts']) : 0;
$resetPending  = (int) ($alerts['reset_pending'] ?? 0);
$justifPending = (int) ($alerts['justificatifs_pending'] ?? 0);
$alertTotal    = $lockedCount + $resetPending + $justifPending + $supportOpen;
$h = fn($s) => htmlspecialchars((string) $s);

$pageTitle   = 'Tableau de bord';
$currentPage = 'dashboard';
$extraCss    = ['../assets/css/admin.css'];
include __DIR__ . '/includes/header.php';
$rp = $rootPrefix ?? '../';

$stat = function (string $icon, string $label, $value, string $href) use ($rp, $h) {
    echo '<a class="ds-card ds-card--stat ds-card--interactive" href="' . $h($rp . $href) . '">'
       . '<span class="ds-card__stat-label"><i class="fas ' . $h($icon) . '"></i> ' . $h($label) . '</span>'
       . '<span class="ds-card__stat-value">' . $h((string) $value) . '</span></a>';
};
$action = function (string $icon, string $title, string $href) use ($rp, $h) {
    echo '<a class="ds-quick-action" href="' . $h($rp . $href) . '">'
       . '<span class="ds-quick-action-icon"><i class="fas ' . $h($icon) . '"></i></span>'
       . '<span class="ds-quick-action-title">' . $h($title) . '</span></a>';
};
?>
<div class="ds-tenant" style="padding: var(--space-5, 20px)">
  <p class="ds-muted" style="margin:0 0 var(--space-5)">
    Pilotage de l'établissement — <span class="ds-badge ds-badge--success"><?= (int) $mods['enabled'] ?>/<?= (int) $mods['total'] ?> modules actifs</span>
  </p>

  <!-- Effectifs / activité -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:var(--space-4);margin-bottom:var(--space-6)">
    <?php
      $stat('fa-user-graduate',   'Élèves',           $users['eleves'] ?? 0,        'admin/users/index.php');
      $stat('fa-chalkboard-user', 'Professeurs',      $users['professeurs'] ?? 0,   'admin/users/index.php');
      $stat('fa-people-roof',     'Parents',          $users['parents'] ?? 0,       'admin/users/index.php');
      $stat('fa-chalkboard',      'Classes',          $extra['classes'] ?? 0,       'admin/classes/index.php');
      $stat('fa-calendar-xmark',  'Absences du jour', $extra['absences_today'] ?? 0,'admin/scolaire/absences.php');
      $stat('fa-life-ring',       'Tickets support',  $supportOpen,                 'modules/support/tickets.php');
    ?>
  </div>

  <!-- Indicateurs -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-4);margin-bottom:var(--space-6)">
    <div class="ds-card ds-card--stat"><span class="ds-card__stat-label">Taux d'absentéisme (30j)</span><span class="ds-card__stat-value"><?= $kpi['taux_absenteisme'] === null ? '—' : $h($kpi['taux_absenteisme']) . ' %' ?></span></div>
    <div class="ds-card ds-card--stat"><span class="ds-card__stat-label">Moyenne générale</span><span class="ds-card__stat-value"><?= $kpi['moyenne_generale'] === null ? '—' : $h($kpi['moyenne_generale']) . '/20' ?></span></div>
    <div class="ds-card ds-card--stat"><span class="ds-card__stat-label">Remplissage des notes</span><span class="ds-card__stat-value"><?= $kpi['taux_remplissage_notes'] === null ? '—' : $h($kpi['taux_remplissage_notes']) . ' %' ?></span></div>
    <div class="ds-card ds-card--stat"><span class="ds-card__stat-label">Sessions actives</span><span class="ds-card__stat-value"><?= (int) ($kpi['sessions_actives'] ?? 0) ?></span></div>
  </div>

  <!-- Actions rapides -->
  <h2 style="margin:0 0 var(--space-3);font-size:var(--fs-lg, 1.125rem)">Actions rapides</h2>
  <div class="ds-quick-actions">
    <?php
      $action('fa-user-plus',      'Créer un utilisateur',      'admin/users/create.php');
      $action('fa-file-import',    'Importer des utilisateurs', 'admin/users/import.php');
      $action('fa-clipboard-check', "Faire l'appel",            'modules/appel/appel.php');
      $action('fa-bullhorn',       'Publier une annonce',       'admin/messagerie/annonces.php');
      $action('fa-chalkboard',     'Gérer les classes',         'admin/classes/index.php');
      $action('fa-puzzle-piece',   'Modules',                   'admin/modules/index.php');
    ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:var(--space-4)">
    <!-- Alertes -->
    <section class="ds-card">
      <div class="ds-card__header"><h2 class="ds-card__title"><i class="fas fa-triangle-exclamation"></i> Alertes <?php if ($alertTotal): ?><span class="ds-badge ds-badge--warning"><?= $alertTotal ?></span><?php endif; ?></h2></div>
      <div class="ds-card__body ds-stack" style="gap:var(--space-2)">
        <?php if ($alertTotal === 0): ?>
          <div class="ds-alert ds-alert--success"><i class="fas fa-circle-check"></i><div>Aucune alerte. Tout est en ordre.</div></div>
        <?php else: ?>
          <?php if ($supportOpen): ?><a class="ds-alert ds-alert--info" style="text-decoration:none" href="<?= $h($rp) ?>modules/support/tickets.php"><i class="fas fa-life-ring"></i><div><strong><?= $supportOpen ?></strong> ticket(s) support ouvert(s)</div></a><?php endif; ?>
          <?php if ($justifPending): ?><a class="ds-alert ds-alert--warning" style="text-decoration:none" href="<?= $h($rp) ?>admin/scolaire/justificatifs.php"><i class="fas fa-file-circle-question"></i><div><strong><?= $justifPending ?></strong> justificatif(s) à traiter</div></a><?php endif; ?>
          <?php if ($lockedCount): ?><div class="ds-alert ds-alert--danger"><i class="fas fa-lock"></i><div><strong><?= $lockedCount ?></strong> compte(s) verrouillé(s)</div></div><?php endif; ?>
          <?php if ($resetPending): ?><div class="ds-alert ds-alert--warning"><i class="fas fa-key"></i><div><strong><?= $resetPending ?></strong> demande(s) de réinitialisation</div></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- Dernières connexions -->
    <section class="ds-card">
      <div class="ds-card__header"><h2 class="ds-card__title"><i class="fas fa-clock-rotate-left"></i> Dernières connexions</h2></div>
      <div class="ds-card__body">
        <?php if (empty($logins)): ?>
          <p class="ds-muted">Aucune connexion récente.</p>
        <?php else: ?>
          <table class="ds-table ds-table--compact">
            <tbody>
            <?php foreach ($logins as $l): ?>
              <tr><td><?= $h($l['identifiant'] ?? '') ?></td><td><span class="ds-badge ds-badge--neutral"><?= $h($l['type'] ?? '') ?></span></td><td class="ds-muted" style="text-align:right"><?= $h($l['last_login'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
