<?php
declare(strict_types=1);
/**
 * Portail PLATEFORME — console de supervision (monitoring SaaS, cahier §20/§34).
 * Design system (.ds-platform) ; métriques serveur en PHP pur + compteurs DB/services.
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.dashboard.view');

$auth     = \API\Security\WorldContext::platformAuth();
$username = $_SESSION['platform']['username'] ?? '';
$pdo      = getPDO();
$h  = fn($s) => htmlspecialchars((string) $s);
$v  = static fn(string $p) => $base . '/' . ltrim($p, '/') . '?v=' . (is_file(__DIR__ . '/../' . $p) ? filemtime(__DIR__ . '/../' . $p) : '1');
$count = function (string $sql, array $a = []) use ($pdo): int { try { $s = $pdo->prepare($sql); $s->execute($a); return max(0, (int) $s->fetchColumn()); } catch (\Throwable $e) { return 0; } };
$statusFor = fn(float $p) => $p >= 90 ? 'crit' : ($p >= 70 ? 'warn' : 'ok');

/* ── Métriques serveur (PHP pur, /proc + disk_*) ── */
$cores = 1;
$ci = @file('/proc/cpuinfo');
if ($ci) { $n = count(array_filter($ci, fn($l) => stripos($l, 'processor') === 0)); if ($n > 0) $cores = $n; }
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
$cpuPct = (int) min(100, round((float) (($load[0] / $cores) * 100)));
$ramPct = 0; $ramTotGb = 0; $ramUsedGb = 0;
$mi = @file_get_contents('/proc/meminfo');
if ($mi && preg_match('/MemTotal:\s+(\d+)/', $mi, $mt) && preg_match('/MemAvailable:\s+(\d+)/', $mi, $ma)) {
    $tot = (int) $mt[1]; $avail = (int) $ma[1];
    if ($tot > 0) { $ramPct = (int) round((float) ((1 - $avail / $tot) * 100)); $ramTotGb = round((float) ($tot / 1048576), 1); $ramUsedGb = round((float) (($tot - $avail) / 1048576), 1); }
}
$dt = @disk_total_space(__DIR__); $df = @disk_free_space(__DIR__);
$diskPct = ($dt && $df !== false && $dt > 0) ? (int) round((float) ((1 - $df / $dt) * 100)) : 0;
$diskUsedGb = $dt ? round((float) (($dt - $df) / 1073741824), 1) : 0; $diskTotGb = $dt ? round((float) ($dt / 1073741824), 1) : 0;

/* ── Compteurs (DB + services) ── */
// Établissements : total / actifs / suspendus en UNE requête (SUM de prédicats booléens).
$etabTotal = 0; $etabActifs = 0; $etabSusp = 0;
try {
    $r = $pdo->query("SELECT SUM(status NOT IN ('deleted','purged')) AS total, SUM(status='active') AS actifs, SUM(status='suspended') AS susp FROM etablissements")->fetch(PDO::FETCH_ASSOC);
    $etabTotal = (int) ($r['total'] ?? 0); $etabActifs = (int) ($r['actifs'] ?? 0); $etabSusp = (int) ($r['susp'] ?? 0);
} catch (\Throwable $e) { error_log('[platform dashboard] etab counts: ' . $e->getMessage()); }
// Tickets : ouverts / critiques en UNE requête.
$ticketsOpen = 0; $ticketsCrit = 0;
try {
    $r = $pdo->query("SELECT SUM(status NOT IN ('resolved','closed','cancelled')) AS opn, SUM(priority='critical' AND status NOT IN ('resolved','closed','cancelled')) AS crit FROM support_tickets")->fetch(PDO::FETCH_ASSOC);
    $ticketsOpen = (int) ($r['opn'] ?? 0); $ticketsCrit = (int) ($r['crit'] ?? 0);
} catch (\Throwable $e) { error_log('[platform dashboard] ticket counts: ' . $e->getMessage()); }
$supportSessions = $count("SELECT COUNT(*) FROM support_sessions WHERE status='active'");
$accessPending = $count("SELECT COUNT(*) FROM support_access_requests WHERE status IN ('sent_to_direction','waiting_direction')");
$platAccounts = $count("SELECT COUNT(*) FROM platform_accounts WHERE status='active'");
$invitPending = $count("SELECT COUNT(*) FROM director_invitations WHERE status='pending'");
$dbTables = 0; $dbSizeMb = 0.0;  // nb de tables + taille : une seule requête information_schema
try {
    $r = $pdo->query("SELECT COUNT(*) AS n, ROUND(SUM(data_length+index_length)/1048576,1) AS mb FROM information_schema.tables WHERE table_schema=DATABASE()")->fetch(PDO::FETCH_ASSOC);
    $dbTables = (int) ($r['n'] ?? 0); $dbSizeMb = (float) ($r['mb'] ?? 0);
} catch (\Throwable $e) { error_log('[platform dashboard] db size: ' . $e->getMessage()); }

$version = '?'; try { $version = app('updates')->getCurrentVersion() ?: '?'; } catch (\Throwable $e) { error_log('[platform dashboard] version: ' . $e->getMessage()); }
$backupAge = null; $backupName = null;
try { $bks = app('backup')->listBackups(); if (!empty($bks)) { $backupName = $bks[0]['filename']; $bts = strtotime((string) ($bks[0]['created_at'] ?? '')); if ($bts) { $backupAge = (time() - $bts) / 3600; } } } catch (\Throwable $e) { error_log('[platform dashboard] backup: ' . $e->getMessage()); }
$auditRows = []; try { $auditRows = $pdo->query("SELECT l.created_at, l.action, pa.username AS actor FROM platform_audit_logs l LEFT JOIN platform_accounts pa ON pa.id=l.platform_account_id ORDER BY l.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { error_log('[platform dashboard] audit: ' . $e->getMessage()); }
$activeSessions = []; try { $activeSessions = (new \API\Support\SupportSessionService($pdo))->allActive(); } catch (\Throwable $e) { error_log('[platform dashboard] sessions: ' . $e->getMessage()); }

$menu = [
    ['platform.establishments.view',    'Établissements',        'fa-school',           '/platform/establishments.php'],
    ['platform.director_invites.create','Invitations Directeur',  'fa-envelope-open-text','/platform/director-invitations.php'],
    ['platform.support.ticket.view',    'Support',                'fa-life-ring',        '/platform/support/tickets.php'],
    ['platform.audit.view',             'Audit global',           'fa-list-check',       '/platform/audit.php'],
    ['platform.security.view',          'Sécurité',               'fa-shield-halved',    '/platform/security.php'],
    ['platform.backups.view',           'Sauvegardes',            'fa-database',         '/platform/backups.php'],
    ['platform.maintenance.manage',     'Maintenance',            'fa-screwdriver-wrench','/platform/maintenance.php'],
    ['platform.updates.manage',         'Mises à jour',           'fa-cloud-arrow-down', '/platform/updates.php'],
    ['platform.system.view',            'Système',                'fa-server',           '/platform/system.php'],
    ['platform.dashboard.view',         'Design System',          'fa-palette',          '/platform/design-system.php'],
];

/** Carte métrique. */
$card = function (string $label, string $value, string $status = 'ok', ?string $sub = null, ?string $href = null, ?int $gauge = null) use ($h, $base) {
    $tag = $href ? 'a' : 'div';
    echo '<' . $tag . ' class="ds-stat-card is-' . $status . '"' . ($href ? ' href="' . $h($base . $href) . '" style="text-decoration:none"' : '') . '>';
    echo '<span class="ds-stat-card-label">' . $h($label) . '</span>';
    echo '<span class="ds-stat-card-value">' . $h($value) . '</span>';
    if ($gauge !== null) { echo '<div class="ds-gauge"><span style="width:' . max(0, min(100, $gauge)) . '%"></span></div>'; }
    if ($sub !== null) { echo '<span class="ds-stat-card-status">' . $h($sub) . '</span>'; }
    echo '</' . $tag . '>';
};
?>
<!doctype html>
<html lang="fr" data-theme="dark" data-theme-pref="dark">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Plateforme Fronote — Supervision</title>
  <script>
  (function(){ var el=document.documentElement,s=null; try{s=localStorage.getItem('fronote_dark_mode');}catch(e){}
    var p=s||'dark', t=p; if(p==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';} else if(['light','dark','liquid'].indexOf(p)<0){t='light';}
    el.setAttribute('data-theme',t); el.setAttribute('data-theme-pref',p);
    try{ el.setAttribute('data-reduce-motion',localStorage.getItem('fronote_reduce_motion')==='true'?'true':'false'); el.setAttribute('data-reduce-transparency',localStorage.getItem('fronote_reduce_transparency')==='true'?'true':'false'); }catch(e){}
  })();
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= $h($v('assets/css/design-system.css')) ?>">
  <style>
    body { margin:0; background:var(--surface-app); color:var(--text-primary); font-family:var(--font-sans); }
    .pf-top { display:flex; align-items:center; justify-content:space-between; gap:var(--space-4); padding:var(--space-3) var(--space-5); background:var(--surface-panel); border-bottom:1px solid var(--border-light); position:sticky; top:0; z-index:var(--z-sticky); backdrop-filter:blur(var(--glass-blur)); flex-wrap:wrap; }
    .pf-brand { font-weight:var(--fw-bold); color:var(--platform-accent); }
    .pf-nav { display:flex; gap:var(--space-1); flex-wrap:wrap; }
    .pf-nav a { color:var(--text-secondary); text-decoration:none; padding:6px 10px; border-radius:var(--radius-md); font-size:var(--fs-sm); }
    .pf-nav a:hover { background:var(--surface-hover); color:var(--text-primary); }
    .pf-main { max-width:1200px; margin:0 auto; padding:var(--space-6) var(--space-5) var(--space-12); }
    .pf-section { margin-top:var(--space-8); }
  </style>
</head>
<body class="ds-platform">
  <header class="pf-top">
    <span class="pf-brand">⬢ Plateforme Fronote</span>
    <nav class="pf-nav">
      <?php foreach ($menu as [$perm, $label, $icon, $href]): if (!$auth->can($perm)) continue; ?>
        <a href="<?= $h($base . $href) ?>"><i class="fas <?= $h($icon) ?>"></i> <?= $h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <span style="display:flex;align-items:center;gap:var(--space-3)">
      <button class="ds-btn ds-btn--ghost ds-btn--sm" id="pfTheme" title="Thème"><i class="fas fa-circle-half-stroke"></i></button>
      <span class="ds-muted"><?= $h($username) ?></span>
      <a class="ds-btn ds-btn--soft ds-btn--sm" href="<?= $h($base) ?>/platform/logout.php">Déconnexion</a>
    </span>
  </header>

  <main class="pf-main">
    <div class="ds-platform-eyebrow">Monitoring</div>
    <h1 class="ds-platform-heading">Console de supervision</h1>

    <!-- Santé serveur -->
    <div class="ds-monitor-grid">
      <?php
        $card('CPU (charge 1 min)', $cpuPct . ' %', $statusFor($cpuPct), 'load ' . number_format((float) ($load[0]), 2) . ' · ' . $cores . ' cœurs', null, $cpuPct);
        $card('Mémoire vive', $ramPct . ' %', $statusFor($ramPct), $ramTotGb ? ($ramUsedGb . ' / ' . $ramTotGb . ' Go') : 'n/d', null, $ramPct);
        $card('Stockage', $diskPct . ' %', $statusFor($diskPct), $diskTotGb ? ($diskUsedGb . ' / ' . $diskTotGb . ' Go') : 'n/d', null, $diskPct);
        if ($auth->can('platform.system.view')) { $card('Base de données', ($dbSizeMb ?: 0) . ' Mo', 'ok', $dbTables . ' tables', '/platform/system.php'); }
      ?>
    </div>

    <!-- Activité plateforme -->
    <div class="ds-section pf-section">
      <div class="ds-platform-eyebrow">Activité</div>
      <div class="ds-monitor-grid" style="margin-top:var(--space-3)">
        <?php
          // Chaque métrique n'est affichée qu'avec la permission granulaire correspondante
          // (moindre privilège : un rôle « dashboard.view » seul ne voit pas l'intelligence opérationnelle).
          if ($auth->can('platform.establishments.view')) {
              $card('Établissements', (string) $etabTotal, 'ok', $etabActifs . ' actifs' . ($etabSusp > 0 ? ' · ' . $etabSusp . ' suspendus' : ''), '/platform/establishments.php');
          }
          if ($auth->can('platform.support.ticket.view')) {
              $card('Tickets support', (string) $ticketsOpen, $ticketsOpen > 5 ? 'warn' : 'ok', 'ouverts', '/platform/support/tickets.php');
              $card('Tickets critiques', (string) $ticketsCrit, $ticketsCrit > 0 ? 'crit' : 'ok', 'priorité critique', '/platform/support/tickets.php');
              $card("Demandes d'accès", (string) $accessPending, $accessPending > 0 ? 'warn' : 'ok', 'en attente Direction', '/platform/support/tickets.php');
          }
          if ($auth->can('platform.security.view')) {
              $card('Sessions support', (string) $supportSessions, $supportSessions > 0 ? 'warn' : 'ok', 'actives', '/platform/security.php');
              $card('Comptes plateforme', (string) $platAccounts, 'ok', 'actifs', '/platform/security.php');
          }
          if ($auth->can('platform.director_invites.create')) {
              $card('Invitations Directeur', (string) $invitPending, 'ok', 'en attente', '/platform/director-invitations.php');
          }
        ?>
      </div>
    </div>

    <!-- Système : version, MAJ, sauvegarde -->
    <div class="ds-section pf-section">
      <div class="ds-platform-eyebrow">Système</div>
      <div class="ds-monitor-grid" style="margin-top:var(--space-3)">
        <?php
          $card('Version Fronote', 'v' . $version, 'ok', 'installée', $auth->can('platform.updates.manage') ? '/platform/updates.php' : null);
          if ($auth->can('platform.backups.view')) {
              if ($backupAge === null) {
                  $card('Dernière sauvegarde', 'aucune', 'warn', 'créer une sauvegarde', '/platform/backups.php');
              } else {
                  $card('Dernière sauvegarde', $backupAge < 1 ? "< 1 h" : round((float) ($backupAge)) . ' h', $backupAge > 48 ? 'warn' : 'ok', $h((string) $backupName), '/platform/backups.php');
              }
          }
          $card('Disponibilité PHP', PHP_VERSION, 'ok', 'runtime');
        ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:var(--space-4);margin-top:var(--space-8)">
      <!-- Sessions support actives -->
      <?php if ($auth->can('platform.security.view')): ?>
      <section class="ds-card">
        <div class="ds-card__header"><h3 class="ds-card__title"><i class="fas fa-user-shield"></i> Sessions support actives</h3></div>
        <div class="ds-card__body">
          <?php if (empty($activeSessions)): ?>
            <p class="ds-muted">Aucune session support en cours.</p>
          <?php else: ?>
            <table class="ds-table ds-table--compact"><tbody>
            <?php foreach ($activeSessions as $s): ?>
              <tr><td><?= $h($s['establishment_name'] ?? ('#' . ($s['establishment_id'] ?? '?'))) ?></td><td><span class="ds-badge ds-badge--info"><?= $h($s['access_level'] ?? '') ?></span></td><td class="ds-muted" style="text-align:right"><?= $h($s['expires_at'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- Audit récent -->
      <?php if ($auth->can('platform.audit.view')): ?>
      <section class="ds-card">
        <div class="ds-card__header"><h3 class="ds-card__title"><i class="fas fa-clock-rotate-left"></i> Événements récents</h3></div>
        <div class="ds-card__body">
          <?php if (empty($auditRows)): ?>
            <p class="ds-muted">Aucun événement enregistré.</p>
          <?php else: ?>
            <table class="ds-table ds-table--compact"><tbody>
            <?php foreach ($auditRows as $r): ?>
              <tr><td class="ds-muted" style="white-space:nowrap"><?= $h($r['created_at'] ?? '') ?></td><td><?= $h($r['actor'] ?? '—') ?></td><td><span class="ds-badge ds-badge--neutral"><?= $h($r['action'] ?? '') ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>
    </div>

    <!-- Accès rapides -->
    <div class="ds-section pf-section">
      <div class="ds-platform-eyebrow">Gestion</div>
      <div class="ds-quick-actions" style="margin-top:var(--space-3)">
        <?php foreach ($menu as [$perm, $label, $icon, $href]): if (!$auth->can($perm)) continue; ?>
          <a class="ds-quick-action" href="<?= $h($base . $href) ?>"><span class="ds-quick-action-icon"><i class="fas <?= $h($icon) ?>"></i></span><span class="ds-quick-action-title"><?= $h($label) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

  <script src="<?= $h($v('assets/js/ui/interactions.js')) ?>" defer></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var order = ['dark', 'liquid', 'light'], btn = document.getElementById('pfTheme');
    if (btn) btn.addEventListener('click', function () {
      var cur = (window.FronoteUI && FronoteUI.getTheme && FronoteUI.getTheme()) || 'dark';
      var next = order[(order.indexOf(cur) + 1) % order.length];
      if (window.FronoteUI) FronoteUI.setTheme(next);
    });
  });
  </script>
</body>
</html>
