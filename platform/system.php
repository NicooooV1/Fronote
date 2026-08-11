<?php
declare(strict_types=1);
/** Portail PLATEFORME — vue système / infrastructure (supervision globale). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.system.view');
$pdo = getPDO();
$h = fn($s) => htmlspecialchars((string) $s);

$count = function (string $sql) use ($pdo): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); } catch (\Throwable $e) { return -1; }
};

$byStatus = [];
try { foreach ($pdo->query("SELECT status, COUNT(*) c FROM etablissements GROUP BY status") as $r) { $byStatus[$r['status']] = (int) $r['c']; } } catch (\Throwable $e) {}

$metrics = [
    'Établissements'            => $count("SELECT COUNT(*) FROM etablissements"),
    'Comptes établissement'     => $count("SELECT COUNT(*) FROM tenant_accounts"),
    'Appartenances actives'     => $count("SELECT COUNT(*) FROM tenant_memberships WHERE status='active'"),
    'Comptes plateforme'        => $count("SELECT COUNT(*) FROM platform_accounts"),
    'Sessions support actives'  => $count("SELECT COUNT(*) FROM support_sessions WHERE status='active'"),
    'Tickets support ouverts'   => $count("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed','cancelled')"),
    'Invitations en attente'    => $count("SELECT COUNT(*) FROM director_invitations WHERE status='pending'"),
];

$dbName = '';
try { $dbName = (string) $pdo->query("SELECT DATABASE()")->fetchColumn(); } catch (\Throwable $e) {}
$tableCount = $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");

$version = '?';
$vf = __DIR__ . '/../version.json';
if (is_file($vf)) { $v = json_decode((string) file_get_contents($vf), true); $version = $v['version'] ?? ($v['app_version'] ?? '?'); }

// Correspondance statut établissement → variante de pill.
$statusPill = [
    'active'     => 'ok',    'onboarding' => 'info',  'draft'    => 'muted',
    'suspended'  => 'warn',  'archived'   => 'muted', 'deleted'  => 'crit',
    'purged'     => 'muted',
];

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('system', 'Système & infrastructure', 'Opérations');
?>

<section class="pf-section">
  <div class="pf-notice">
    <i class="fas fa-server"></i>
    <span>Base <span class="pf-mono"><?= $h($dbName) ?></span> · <?= (int) $tableCount ?> tables · Fronote <span class="pf-mono">v<?= $h($version) ?></span></span>
  </div>
</section>

<section class="pf-section">
  <div class="pf-section__head">
    <div>
      <div class="pf-eyebrow">Supervision</div>
      <h2 class="pf-title" style="font-size:16px;">Indicateurs globaux</h2>
    </div>
  </div>
  <div class="pf-grid">
    <?php foreach ($metrics as $label => $val): ?>
      <div class="pf-stat is-info">
        <span class="pf-stat__label"><?= $h($label) ?></span>
        <span class="pf-stat__value"><?= $val < 0 ? '—' : (int) $val ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-school"></i> Établissements par statut</h2>
    </div>
    <div class="pf-card__body">
      <?php if (empty($byStatus)): ?>
        <div class="pf-empty">Aucun établissement enregistré.</div>
      <?php else: ?>
        <div class="pf-row">
          <?php foreach ($byStatus as $s => $c): ?>
            <span class="pf-pill pf-pill--<?= $h($statusPill[$s] ?? 'muted') ?>"><?= $h($s) ?> · <?= (int) $c ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php pf_layout_footer(); ?>
