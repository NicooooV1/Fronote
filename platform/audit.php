<?php
declare(strict_types=1);
/** Portail PLATEFORME — audit global (actions plateforme + établissements). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.audit.view');
$pdo = getPDO();
$h = fn($s) => htmlspecialchars((string) $s);

$platformLogs = [];
try {
    $platformLogs = $pdo->query(
        "SELECT l.*, pa.username AS actor FROM platform_audit_logs l
           LEFT JOIN platform_accounts pa ON pa.id = l.platform_account_id
          ORDER BY l.created_at DESC, l.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { error_log('[platform audit] ' . $e->getMessage()); }

$tenantLogs = [];
try {
    $tenantLogs = $pdo->query(
        "SELECT t.*, e.nom AS etab FROM tenant_audit_logs t
           JOIN etablissements e ON e.id = t.establishment_id
          ORDER BY t.created_at DESC, t.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* table peut être vide */ }

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('audit', 'Audit global', 'Observabilité');
?>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-network-wired"></i> Plateforme</h2>
      <div class="pf-card__actions"><span class="pf-badge pf-badge--soft"><?= count($platformLogs) ?> dernières</span></div>
    </div>
    <div class="pf-card__body pf-card__body--flush">
      <div class="pf-table-wrap">
        <table class="pf-table pf-table--compact">
          <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Établ.</th><th>Détail</th></tr></thead>
          <tbody>
          <?php if (!$platformLogs): ?><tr><td colspan="5"><div class="pf-empty">Aucune entrée.</div></td></tr><?php endif; ?>
          <?php foreach ($platformLogs as $l): ?>
            <tr>
              <td class="pf-mono pf-muted" style="white-space:nowrap;"><?= $h($l['created_at']) ?></td>
              <td><?= $h($l['actor'] ?? ('#' . $l['platform_account_id'])) ?></td>
              <td><span class="pf-badge pf-badge--soft"><?= $h($l['action']) ?></span></td>
              <td class="pf-mono pf-muted"><?= $l['establishment_id'] ? '#' . (int) $l['establishment_id'] : '—' ?></td>
              <td class="pf-mono pf-muted"><?= $h(mb_substr((string) ($l['new_value'] ?? ''), 0, 120)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-school"></i> Établissements</h2>
      <div class="pf-card__actions"><span class="pf-badge pf-badge--soft"><?= count($tenantLogs) ?> dernières</span></div>
    </div>
    <div class="pf-card__body pf-card__body--flush">
      <div class="pf-table-wrap">
        <table class="pf-table pf-table--compact">
          <thead><tr><th>Date</th><th>Établissement</th><th>Action</th><th>Cible</th><th>Détail</th></tr></thead>
          <tbody>
          <?php if (!$tenantLogs): ?><tr><td colspan="5"><div class="pf-empty">Aucune entrée.</div></td></tr><?php endif; ?>
          <?php foreach ($tenantLogs as $l): ?>
            <tr>
              <td class="pf-mono pf-muted" style="white-space:nowrap;"><?= $h($l['created_at']) ?></td>
              <td><?= $h($l['etab']) ?></td>
              <td><span class="pf-badge pf-badge--soft"><?= $h($l['action']) ?></span></td>
              <td class="pf-mono pf-muted"><?= $h($l['target_type'] ?? '') ?> <?= $l['target_id'] ? '#' . (int) $l['target_id'] : '' ?></td>
              <td class="pf-mono pf-muted"><?= $h(mb_substr((string) ($l['new_value'] ?? ''), 0, 120)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php pf_layout_footer(); ?>
