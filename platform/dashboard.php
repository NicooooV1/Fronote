<?php
declare(strict_types=1);
/**
 * Portail PLATEFORME — CENTRE DE COMMANDE de la flotte (console opérateur).
 * Vue « à la volée » : statut de la flotte + santé système + alertes + audit.
 * Chrome partagée via platform/includes/layout.php ; composants .pf-* (platform.css).
 * Métriques serveur en PHP pur (/proc + disk_*) ; compteurs DB/services.
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
platformAuthorize('platform.dashboard.view');

$base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
$auth = \API\Security\WorldContext::platformAuth();
$pdo  = getPDO();
$h    = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$can  = static function (string $p) use ($auth): bool { try { return $auth->can($p); } catch (\Throwable $e) { return false; } };
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
$accessPending   = $count("SELECT COUNT(*) FROM support_access_requests WHERE status IN ('sent_to_direction','waiting_direction')");
$platAccounts    = $count("SELECT COUNT(*) FROM platform_accounts WHERE status='active'");
$invitPending    = $count("SELECT COUNT(*) FROM director_invitations WHERE status='pending'");
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

/* ── Flotte : liste des établissements pour la grille de statut ── */
$fleet = [];
if ($can('platform.establishments.view')) {
    try { $fleet = (new \API\Platform\PlatformEstablishmentService($pdo))->listAll(); }
    catch (\Throwable $e) { error_log('[platform dashboard] fleet: ' . $e->getMessage()); }
}
// Statut établissement → [variante pill, libellé, priorité de tri « à surveiller d'abord »].
$pillMap = [
    'suspended'  => ['warn',  'Suspendu',   0],
    'onboarding' => ['info',  'Onboarding', 1],
    'draft'      => ['muted', 'Brouillon',  2],
    'active'     => ['ok',    'Actif',      3],
    'archived'   => ['muted', 'Archivé',    4],
    'deleted'    => ['crit',  'Supprimé',   5],
    'purged'     => ['muted', 'Purgé',      6],
];
$fleet = array_values(array_filter($fleet, fn($e) => ($e['status'] ?? '') !== 'purged'));
usort($fleet, function ($a, $b) use ($pillMap) {
    $pa = $pillMap[$a['status'] ?? ''][2] ?? 9;
    $pb = $pillMap[$b['status'] ?? ''][2] ?? 9;
    return $pa <=> $pb ?: strcasecmp((string) ($a['nom'] ?? ''), (string) ($b['nom'] ?? ''));
});
$fleetCap  = 12;
$fleetMore = max(0, count($fleet) - $fleetCap);
$fleetShow = array_slice($fleet, 0, $fleetCap);

/* ── Alertes ouvertes (dérivées de l'état courant, gatées par permission) ── */
$alerts = [];
if ($can('platform.support.ticket.view') && $ticketsCrit > 0) {
    $alerts[] = ['crit', 'fa-fire', $ticketsCrit . ' ticket' . ($ticketsCrit > 1 ? 's' : '') . ' critique' . ($ticketsCrit > 1 ? 's' : '') . ' ouvert' . ($ticketsCrit > 1 ? 's' : ''), '/platform/support/tickets.php'];
}
if ($can('platform.establishments.view') && $etabSusp > 0) {
    $alerts[] = ['warn', 'fa-circle-pause', $etabSusp . ' établissement' . ($etabSusp > 1 ? 's' : '') . ' suspendu' . ($etabSusp > 1 ? 's' : ''), '/platform/establishments.php'];
}
if ($can('platform.support.ticket.view') && $accessPending > 0) {
    $alerts[] = ['warn', 'fa-key', $accessPending . " demande" . ($accessPending > 1 ? 's' : '') . " d'accès en attente Direction", '/platform/support/tickets.php'];
}
if ($can('platform.backups.view')) {
    if ($backupAge === null) {
        $alerts[] = ['warn', 'fa-database', 'Aucune sauvegarde disponible', '/platform/backups.php'];
    } elseif ($backupAge > 48) {
        $alerts[] = ['warn', 'fa-database', 'Dernière sauvegarde il y a ' . round((float) $backupAge) . ' h', '/platform/backups.php'];
    }
}
$obsHref = $can('platform.system.view') ? '/platform/observability.php' : null;
foreach ([['CPU', $cpuPct], ['Mémoire', $ramPct], ['Stockage', $diskPct]] as [$rlbl, $rp]) {
    if ($rp >= 90)      { $alerts[] = ['crit', 'fa-gauge-high', $rlbl . ' saturé (' . $rp . ' %)', $obsHref]; }
    elseif ($rp >= 70)  { $alerts[] = ['warn', 'fa-gauge-high', $rlbl . ' élevé (' . $rp . ' %)', $obsHref]; }
}
if ($can('platform.security.view') && $supportSessions > 0) {
    $alerts[] = ['info', 'fa-user-shield', $supportSessions . ' session' . ($supportSessions > 1 ? 's' : '') . ' support active' . ($supportSessions > 1 ? 's' : ''), '/platform/security.php'];
}

/* ── Rendu d'une tuile stat .pf-stat ── */
$stat = function (string $label, string $value, string $status = 'ok', ?string $sub = null, ?string $href = null, ?int $gauge = null) use ($base, $h) {
    $tag = $href ? 'a' : 'div';
    echo '<' . $tag . ' class="pf-stat is-' . $h($status) . '"' . ($href ? ' href="' . $h($base . $href) . '"' : '') . '>';
    echo '<span class="pf-stat__label">' . $h($label) . '</span>';
    echo '<span class="pf-stat__value">' . $h($value) . '</span>';
    if ($gauge !== null) { echo '<div class="pf-gauge"><span style="width:' . max(0, min(100, $gauge)) . '%"></span></div>'; }
    if ($sub !== null) { echo '<span class="pf-stat__sub">' . $h($sub) . '</span>'; }
    echo '</' . $tag . '>';
};

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('dashboard', 'Centre de commande', 'Flotte');
?>

<!-- ═══ Rangée d'indicateurs de tête ═══ -->
<section class="pf-section">
  <div class="pf-grid">
    <?php
      if ($can('platform.establishments.view')) {
          $stat('Établissements', (string) $etabTotal, $etabSusp > 0 ? 'warn' : 'ok',
                $etabActifs . ' actifs' . ($etabSusp > 0 ? ' · ' . $etabSusp . ' suspendus' : ''),
                '/platform/establishments.php');
      }
      if ($can('platform.security.view')) {
          $stat('Comptes plateforme', (string) $platAccounts, 'ok', 'actifs', '/platform/security.php');
          $stat('Sessions support', (string) $supportSessions, $supportSessions > 0 ? 'warn' : 'ok', 'actives', '/platform/security.php');
      }
      if ($can('platform.support.ticket.view')) {
          $stat('Tickets ouverts', (string) $ticketsOpen, $ticketsCrit > 0 ? 'crit' : ($ticketsOpen > 5 ? 'warn' : 'ok'),
                $ticketsCrit > 0 ? ($ticketsCrit . ' critiques') : 'support', '/platform/support/tickets.php');
      }
      if ($can('platform.backups.view')) {
          if ($backupAge === null) {
              $stat('Dernière sauvegarde', 'aucune', 'warn', 'créer une sauvegarde', '/platform/backups.php');
          } else {
              $stat('Dernière sauvegarde', $backupAge < 1 ? '< 1 h' : round((float) $backupAge) . ' h',
                    $backupAge > 48 ? 'warn' : 'ok', (string) $backupName, '/platform/backups.php');
          }
      }
      if ($can('platform.director_invites.create') && $invitPending > 0) {
          $stat('Invitations Directeur', (string) $invitPending, 'info', 'en attente', '/platform/director-invitations.php');
      }
      $stat('Version', 'v' . $version, 'info', 'installée', $can('platform.updates.manage') ? '/platform/updates.php' : null);
    ?>
  </div>
</section>

<!-- ═══ Flotte + alertes ═══ -->
<section class="pf-section">
  <div class="pf-grid pf-grid--2">

    <!-- Grille de statut de la flotte -->
    <?php if ($can('platform.establishments.view')): ?>
    <div class="pf-card">
      <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-diagram-project"></i> Statut de la flotte</h2>
        <div class="pf-card__actions">
          <a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?= $h($base) ?>/platform/establishments.php">Tout gérer <i class="fas fa-arrow-right"></i></a>
        </div>
      </div>
      <div class="pf-card__body pf-card__body--flush">
        <?php if (empty($fleetShow)): ?>
          <div class="pf-empty">Aucun établissement enregistré.</div>
        <?php else: ?>
        <div class="pf-table-wrap">
          <table class="pf-table pf-table--compact">
            <thead>
              <tr>
                <th>Établissement</th>
                <th>Type</th>
                <th>Statut</th>
                <th class="pf-num">Membres</th>
                <th>Support</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fleetShow as $e):
                  $st = (string) ($e['status'] ?? '');
                  [$variant, $label] = $pillMap[$st] ?? ['muted', $st ?: 'n/d'];
                  $sup = (int) ($e['support_sessions'] ?? 0);
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;"><?= $h($e['nom'] ?? ('#' . ($e['id'] ?? '?'))) ?></div>
                  <?php if (!empty($e['ville'])): ?><div class="pf-muted" style="font-size:11.5px;"><?= $h($e['ville']) ?></div><?php endif; ?>
                </td>
                <td class="pf-mono pf-muted"><?= $h($e['type'] ?? '—') ?></td>
                <td><span class="pf-pill pf-pill--<?= $h($variant) ?>"><?= $h($label) ?></span></td>
                <td class="pf-num"><?= (int) ($e['members'] ?? 0) ?></td>
                <td><?php if ($sup > 0): ?><span class="pf-pill pf-pill--info"><?= $sup ?> actif<?= $sup > 1 ? 's' : '' ?></span><?php else: ?><span class="pf-muted">—</span><?php endif; ?></td>
                <td class="pf-num"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?= $h($base) ?>/platform/establishments.php" title="Gérer">Gérer</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($fleetMore > 0): ?>
          <div style="padding:10px 14px; border-top:1px solid var(--pf-border);">
            <a class="pf-muted" style="font-size:12.5px; text-decoration:none;" href="<?= $h($base) ?>/platform/establishments.php">+ <?= (int) $fleetMore ?> autre<?= $fleetMore > 1 ? 's' : '' ?> — voir tout &rarr;</a>
          </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Alertes ouvertes -->
    <div class="pf-card">
      <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-triangle-exclamation"></i> Alertes ouvertes</h2>
        <?php if (!empty($alerts)): ?><div class="pf-card__actions"><span class="pf-badge pf-badge--soft"><?= count($alerts) ?></span></div><?php endif; ?>
      </div>
      <div class="pf-card__body">
        <?php if (empty($alerts)): ?>
          <div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span>Tout est nominal — aucune alerte ouverte.</span></div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($alerts as [$lvl, $icon, $msg, $href]):
                $ncls = in_array($lvl, ['crit', 'warn'], true) ? ' pf-notice--' . $lvl : '';
                $tag  = $href ? 'a' : 'div';
            ?>
            <<?= $tag ?> class="pf-notice<?= $ncls ?>"<?= $href ? ' href="' . $h($base . $href) . '" style="text-decoration:none;"' : '' ?>>
              <i class="fas <?= $h($icon) ?>"></i>
              <span style="flex:1;"><?= $h($msg) ?></span>
              <?php if ($href): ?><i class="fas fa-chevron-right" style="font-size:11px; opacity:.6;"></i><?php endif; ?>
            </<?= $tag ?>>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ═══ Santé système ═══ -->
<section class="pf-section">
  <div class="pf-section__head">
    <div>
      <div class="pf-eyebrow">Observabilité</div>
      <h2 class="pf-title" style="font-size:16px;">Santé système</h2>
    </div>
    <?php if ($can('platform.system.view')): ?>
      <a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?= $h($base) ?>/platform/observability.php">Détail <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
  </div>
  <div class="pf-grid">
    <?php
      $stat('CPU', $cpuPct . ' %', $statusFor($cpuPct), 'load ' . number_format((float) ($load[0]), 2) . ' · ' . $cores . ' cœurs', $obsHref, $cpuPct);
      $stat('Mémoire vive', $ramPct . ' %', $statusFor($ramPct), $ramTotGb ? ($ramUsedGb . ' / ' . $ramTotGb . ' Go') : 'n/d', $obsHref, $ramPct);
      $stat('Stockage', $diskPct . ' %', $statusFor($diskPct), $diskTotGb ? ($diskUsedGb . ' / ' . $diskTotGb . ' Go') : 'n/d', $obsHref, $diskPct);
      if ($can('platform.system.view')) {
          $stat('Base de données', ($dbSizeMb ?: 0) . ' Mo', 'info', $dbTables . ' tables', '/platform/system.php');
      }
      $stat('Runtime PHP', PHP_VERSION, 'info', 'moteur applicatif');
    ?>
  </div>
</section>

<!-- ═══ Sessions support + audit récent ═══ -->
<section class="pf-section">
  <div class="pf-grid pf-grid--2">
    <?php if ($can('platform.security.view')): ?>
    <div class="pf-card">
      <div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-user-shield"></i> Sessions support actives</h2></div>
      <div class="pf-card__body pf-card__body--flush">
        <?php if (empty($activeSessions)): ?>
          <div class="pf-empty">Aucune session support en cours.</div>
        <?php else: ?>
        <div class="pf-table-wrap">
          <table class="pf-table pf-table--compact">
            <tbody>
            <?php foreach ($activeSessions as $s): ?>
              <tr>
                <td><?= $h($s['establishment_name'] ?? ('#' . ($s['establishment_id'] ?? '?'))) ?></td>
                <td><span class="pf-pill pf-pill--info"><?= $h($s['access_level'] ?? '') ?></span></td>
                <td class="pf-mono pf-muted" style="text-align:right;"><?= $h($s['expires_at'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can('platform.audit.view')): ?>
    <div class="pf-card">
      <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-clock-rotate-left"></i> Audit récent</h2>
        <div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?= $h($base) ?>/platform/audit.php">Journal <i class="fas fa-arrow-right"></i></a></div>
      </div>
      <div class="pf-card__body pf-card__body--flush">
        <?php if (empty($auditRows)): ?>
          <div class="pf-empty">Aucun événement enregistré.</div>
        <?php else: ?>
        <div class="pf-table-wrap">
          <table class="pf-table pf-table--compact">
            <tbody>
            <?php foreach ($auditRows as $r): ?>
              <tr>
                <td class="pf-mono pf-muted" style="white-space:nowrap;"><?= $h($r['created_at'] ?? '') ?></td>
                <td><?= $h($r['actor'] ?? '—') ?></td>
                <td><span class="pf-badge pf-badge--soft"><?= $h($r['action'] ?? '') ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php pf_layout_footer(); ?>
