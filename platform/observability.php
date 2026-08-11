<?php
declare(strict_types=1);
/**
 * Portail PLATEFORME — OBSERVABILITÉ / santé de l'infrastructure.
 *
 * Vue de supervision « mission-control » : elle réunit en une page
 *   1. la LIVENESS (heartbeat du cron via app_metrics) — un arrêt silencieux du
 *      cron/backup devient visible ;
 *   2. les SONDES CRITIQUES spécifiques au boîtier 2 Go : mémoire, SWAP (/proc),
 *      stockage, CPU, connexions MariaDB, workers PHP-FPM ;
 *   3. les SOUS-SYSTÈMES via API/Services/HealthCheckService (DB/disque/cache/SMTP/
 *      WebSocket/PHP/app), rendus en .pf-stat + .pf-pill ;
 *   4. la CHARGE PAR ÉTABLISSEMENT (comptes actifs par etablissement_id — un seul
 *      GROUP BY sur la base partagée).
 *
 * Chrome partagée via platform/includes/layout.php ; composants .pf-* (platform.css).
 * Aucune donnée serveur exécutée en shell : /proc + disk_* + requêtes bornées.
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
platformAuthorize('platform.system.view');

$base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
$pdo  = getPDO();
$h    = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$statusFor = static fn(float $p) => $p >= 90 ? 'crit' : ($p >= 70 ? 'warn' : 'ok');
// Traduction statut HealthCheckService → variante .pf-*
$mapHealth = static fn(string $s) => $s === 'ok' ? 'ok' : ($s === 'warning' ? 'warn' : 'crit');

/* ─────────── Sondes serveur (PHP pur : /proc + disk_*) ─────────── */

// CPU (charge normalisée par cœur)
$cores = 1;
$ci = @file('/proc/cpuinfo');
if ($ci) { $n = count(array_filter($ci, static fn($l) => stripos($l, 'processor') === 0)); if ($n > 0) { $cores = $n; } }
$load   = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
$cpuPct = (int) min(100, round((float) (($load[0] / $cores) * 100)));

// Mémoire + SWAP (/proc/meminfo) — le swap est le signal d'alerte n°1 sur 2 Go de RAM
$ramPct = 0; $ramTotGb = 0.0; $ramUsedGb = 0.0;
$swapPct = 0; $swapTotMb = 0.0; $swapUsedMb = 0.0; $swapKnown = false;
$mi = @file_get_contents('/proc/meminfo');
if ($mi) {
    if (preg_match('/MemTotal:\s+(\d+)/', $mi, $mt) && preg_match('/MemAvailable:\s+(\d+)/', $mi, $ma)) {
        $tot = (int) $mt[1]; $avail = (int) $ma[1];
        if ($tot > 0) {
            $ramPct    = (int) round((float) ((1 - $avail / $tot) * 100));
            $ramTotGb  = round((float) ($tot / 1048576), 1);
            $ramUsedGb = round((float) (($tot - $avail) / 1048576), 1);
        }
    }
    if (preg_match('/SwapTotal:\s+(\d+)/', $mi, $st_) && preg_match('/SwapFree:\s+(\d+)/', $mi, $sf)) {
        $swapTot = (int) $st_[1]; $swapFree = (int) $sf[1];
        $swapKnown = true;
        $swapTotMb  = round((float) ($swapTot / 1024), 0);
        $swapUsedMb = round((float) (($swapTot - $swapFree) / 1024), 0);
        $swapPct    = $swapTot > 0 ? (int) round((float) ((1 - $swapFree / $swapTot) * 100)) : 0;
    }
}

// Stockage (partition de l'application)
$dt = @disk_total_space(__DIR__); $df = @disk_free_space(__DIR__);
$diskPct    = ($dt && $df !== false && $dt > 0) ? (int) round((float) ((1 - $df / $dt) * 100)) : 0;
$diskUsedGb = $dt ? round((float) (($dt - $df) / 1073741824), 1) : 0.0;
$diskTotGb  = $dt ? round((float) ($dt / 1073741824), 1) : 0.0;

// MariaDB : up + connexions ouvertes / plafond
$dbUp = false; $dbConn = null; $dbMaxConn = null; $dbUptime = null;
try {
    $pdo->query('SELECT 1');
    $dbUp = true;
    $g = static function (string $like) use ($pdo): ?string {
        try { $r = $pdo->query("SHOW STATUS LIKE " . $pdo->quote($like))->fetch(PDO::FETCH_NUM); return $r ? (string) $r[1] : null; }
        catch (\Throwable $e) { return null; }
    };
    $gv = static function (string $like) use ($pdo): ?string {
        try { $r = $pdo->query("SHOW VARIABLES LIKE " . $pdo->quote($like))->fetch(PDO::FETCH_NUM); return $r ? (string) $r[1] : null; }
        catch (\Throwable $e) { return null; }
    };
    $c = $g('Threads_connected'); if ($c !== null) { $dbConn = (int) $c; }
    $m = $gv('max_connections');  if ($m !== null) { $dbMaxConn = (int) $m; }
    $u = $g('Uptime');            if ($u !== null) { $dbUptime = (int) $u; }
} catch (\Throwable $e) {
    error_log('[observability] db probe: ' . $e->getMessage());
}
$dbConnPct = ($dbConn !== null && $dbMaxConn) ? (int) min(100, round((float) (($dbConn / $dbMaxConn) * 100))) : null;

// PHP-FPM : nombre de workers vivants (scan /proc/*/comm — « si disponible »)
$fpmWorkers = null;
$procGlob = @glob('/proc/[0-9]*/comm');
if (is_array($procGlob)) {
    $count = 0;
    foreach ($procGlob as $comm) {
        $name = @file_get_contents($comm);
        if ($name !== false && stripos($name, 'php-fpm') !== false) { $count++; }
    }
    // 0 worker mais on tourne quand même : soit non-FPM (mod_php/CLI), soit /proc masqué → inconnu.
    $fpmWorkers = $count > 0 ? $count : null;
}
$fpmMax = (int) (getenv('PHP_FPM_MAX_CHILDREN') ?: 0);
$fpmPct = ($fpmWorkers !== null && $fpmMax > 0) ? (int) min(100, round((float) (($fpmWorkers / $fpmMax) * 100))) : null;

/* ─────────── Sous-systèmes : HealthCheckService ─────────── */
$health = ['healthy' => null, 'checks' => [], 'checked_at' => null];
try {
    $health = app('health')->runAll();
} catch (\Throwable $e) {
    error_log('[observability] health runAll: ' . $e->getMessage());
}
$checkLabels = [
    'database'  => ['Base de données', 'fa-database'],
    'disk'      => ['Stockage',        'fa-hard-drive'],
    'cache'     => ['Cache',           'fa-bolt'],
    'smtp'      => ['SMTP',            'fa-envelope'],
    'websocket' => ['WebSocket',       'fa-tower-broadcast'],
    'php'       => ['Runtime PHP',     'fa-code'],
    'app'       => ['Application',     'fa-cube'],
];

/* ─────────── Liveness : heartbeat du cron (app_metrics) ─────────── */
$metrics   = new \API\Services\MetricsService($pdo);
$snapKeys  = ['heartbeat.cron', 'backup.age_hours', 'sys.swap_percent', 'sys.disk_percent', 'db.connections'];
$snapshot  = $metrics->latestMany($snapKeys);
$beat      = $snapshot['heartbeat.cron'] ?? null;
$beatTs    = $beat ? strtotime($beat['recorded_at']) : null;
$beatAgeH  = $beatTs ? (time() - $beatTs) / 3600 : null;
// Le cron quotidien tourne toutes les 24 h ; > 26 h = manqué (warn), > 50 h = arrêt (crit).
if ($beatAgeH === null)      { $liveVariant = 'crit'; $liveLabel = 'Aucun signal'; }
elseif ($beatAgeH > 50)      { $liveVariant = 'crit'; $liveLabel = 'Cron arrêté'; }
elseif ($beatAgeH > 26)      { $liveVariant = 'warn'; $liveLabel = 'Battement manqué'; }
else                         { $liveVariant = 'ok';   $liveLabel = 'Cron vivant'; }
$fmtAge = static function (?float $hours): string {
    if ($hours === null) { return 'jamais'; }
    if ($hours < 1) { return 'il y a ' . max(1, (int) round($hours * 60)) . ' min'; }
    if ($hours < 48) { return 'il y a ' . (int) round($hours) . ' h'; }
    return 'il y a ' . (int) round($hours / 24) . ' j';
};

/* ─────────── Charge par établissement (UN seul GROUP BY, base partagée) ─────────── */
$tenants = [];
try {
    $sql = "SELECT e.id AS eid, e.nom, e.ville, e.status,\n"
         . "       COUNT(a.id)                                                       AS comptes,\n"
         . "       SUM(a.status = 'active')                                          AS actifs,\n"
         . "       SUM(a.last_login_at >= (NOW() - INTERVAL 30 DAY))                 AS actifs_30j\n"
         . "FROM etablissements e\n"
         . "LEFT JOIN accounts a\n"
         . "  ON a.etablissement_id = e.id AND a.deleted_at IS NULL AND a.account_type <> 'platform'\n"
         . "WHERE e.status NOT IN ('deleted','purged')\n"
         . "GROUP BY e.id, e.nom, e.ville, e.status\n"
         . "ORDER BY comptes DESC, e.nom ASC";
    $tenants = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log('[observability] tenant grid: ' . $e->getMessage());
}
$tenantPill = [
    'active' => 'ok', 'onboarding' => 'info', 'draft' => 'muted',
    'suspended' => 'warn', 'archived' => 'muted',
];
$totalAccounts = 0; $totalActive = 0;
foreach ($tenants as $t) { $totalAccounts += (int) $t['comptes']; $totalActive += (int) $t['actifs']; }

/* ─────────── Rendu d'une tuile stat ─────────── */
$stat = function (string $label, string $value, string $status, ?string $sub = null, ?int $gauge = null) use ($h): void {
    echo '<div class="pf-stat is-' . $h($status) . '">';
    echo '<span class="pf-stat__label">' . $h($label) . '</span>';
    echo '<span class="pf-stat__value">' . $h($value) . '</span>';
    if ($gauge !== null) { echo '<div class="pf-gauge"><span style="width:' . max(0, min(100, $gauge)) . '%"></span></div>'; }
    if ($sub !== null) { echo '<span class="pf-stat__sub">' . $h($sub) . '</span>'; }
    echo '</div>';
};

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('observability', 'Observabilité', 'Observabilité');
?>

<style>
/* Indicateur de battement (liveness) — pulsation discrète, désactivée en reduce-motion. */
.pf-live { display:flex; align-items:center; gap:12px; }
.pf-live__dot { width:12px; height:12px; border-radius:50%; flex:none; position:relative; }
.pf-live__dot::after { content:""; position:absolute; inset:0; border-radius:50%; background:inherit; opacity:.55; animation:pf-live-pulse 2s ease-out infinite; }
.pf-live--ok   .pf-live__dot { background:var(--pf-ok); }
.pf-live--warn .pf-live__dot { background:var(--pf-warn); }
.pf-live--crit .pf-live__dot { background:var(--pf-crit); }
@keyframes pf-live-pulse { 0%{transform:scale(1);opacity:.55;} 70%{transform:scale(2.6);opacity:0;} 100%{opacity:0;} }
@media (prefers-reduced-motion: reduce) { .pf-live__dot::after { animation:none; opacity:0; } }
:root[data-reduce-motion="true"] .pf-live__dot::after { animation:none; opacity:0; }
</style>

<!-- ═══ Bandeau LIVENESS (heartbeat du cron) ═══ -->
<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__body">
      <div class="pf-live pf-live--<?= $h($liveVariant) ?>">
        <span class="pf-live__dot" aria-hidden="true"></span>
        <div style="flex:1;">
          <div style="font-weight:650;"><?= $h($liveLabel) ?>
            <span class="pf-pill pf-pill--<?= $h($liveVariant) ?>" style="margin-left:6px;">heartbeat</span>
          </div>
          <div class="pf-muted" style="font-size:12.5px;">
            Dernier battement de maintenance <span class="pf-mono"><?= $h($fmtAge($beatAgeH)) ?></span>
            <?php if ($beat): ?> · <span class="pf-mono"><?= $h($beat['recorded_at']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="pf-row">
          <?php $overall = $health['healthy']; ?>
          <?php if ($overall === true): ?>
            <span class="pf-pill pf-pill--ok">Systèmes nominaux</span>
          <?php elseif ($overall === false): ?>
            <span class="pf-pill pf-pill--crit">Anomalie détectée</span>
          <?php else: ?>
            <span class="pf-pill pf-pill--muted">Santé indéterminée</span>
          <?php endif; ?>
          <?php if (!empty($health['checked_at'])): ?>
            <span class="pf-muted pf-mono" style="font-size:11.5px;">contrôlé <?= $h(date('H:i:s', strtotime((string) $health['checked_at']))) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ Sondes critiques (boîtier 2 Go) ═══ -->
<section class="pf-section">
  <div class="pf-section__head">
    <div>
      <div class="pf-eyebrow">Ressources</div>
      <h2 class="pf-title" style="font-size:16px;">Sondes critiques</h2>
    </div>
    <span class="pf-muted" style="font-size:12px;">boîtier contraint · 2 Go</span>
  </div>
  <div class="pf-grid">
    <?php
      $stat('Mémoire vive', $ramPct . ' %', $statusFor($ramPct), $ramTotGb ? ($ramUsedGb . ' / ' . $ramTotGb . ' Go') : 'n/d', $ramPct);

      // SWAP — sur 2 Go, tout swap soutenu = signal rouge : seuils abaissés.
      if ($swapKnown) {
          $swapStatus = $swapPct >= 60 ? 'crit' : ($swapPct >= 20 ? 'warn' : 'ok');
          $stat('Swap', $swapPct . ' %', $swapStatus,
                $swapTotMb > 0 ? (number_format($swapUsedMb, 0, ',', ' ') . ' / ' . number_format($swapTotMb, 0, ',', ' ') . ' Mo') : 'aucun swap',
                $swapPct);
      } else {
          $stat('Swap', 'n/d', 'info', '/proc indisponible');
      }

      $stat('Stockage', $diskPct . ' %', $statusFor($diskPct), $diskTotGb ? ($diskUsedGb . ' / ' . $diskTotGb . ' Go') : 'n/d', $diskPct);
      $stat('CPU', $cpuPct . ' %', $statusFor($cpuPct), 'load ' . number_format((float) $load[0], 2) . ' · ' . $cores . ' cœurs', $cpuPct);

      // MariaDB : disponibilité + connexions
      if (!$dbUp) {
          $stat('MariaDB', 'DOWN', 'crit', 'connexion impossible');
      } elseif ($dbConn !== null) {
          $sub = $dbMaxConn ? ($dbConn . ' / ' . $dbMaxConn . ' conn.') : ($dbConn . ' connexions');
          $stat('MariaDB', $dbConnPct !== null ? ($dbConnPct . ' %') : (string) $dbConn,
                $dbConnPct !== null ? $statusFor((float) $dbConnPct) : 'ok', $sub, $dbConnPct);
      } else {
          $stat('MariaDB', 'UP', 'ok', 'connexions n/d');
      }

      // PHP-FPM : workers vivants
      if ($fpmWorkers === null) {
          $stat('PHP-FPM', 'n/d', 'info', 'pool non exposé');
      } elseif ($fpmPct !== null) {
          $stat('PHP-FPM', $fpmWorkers . ' w', $statusFor((float) $fpmPct), $fpmWorkers . ' / ' . $fpmMax . ' workers', $fpmPct);
      } else {
          $stat('PHP-FPM', $fpmWorkers . ' w', 'ok', 'workers vivants');
      }
    ?>
  </div>
</section>

<!-- ═══ Sous-systèmes (HealthCheckService) ═══ -->
<section class="pf-section">
  <div class="pf-section__head">
    <div>
      <div class="pf-eyebrow">Diagnostic</div>
      <h2 class="pf-title" style="font-size:16px;">Sous-systèmes</h2>
    </div>
  </div>
  <?php if (empty($health['checks'])): ?>
    <div class="pf-notice pf-notice--warn"><i class="fas fa-triangle-exclamation"></i><span>Diagnostic indisponible — le service de santé n'a pas pu s'exécuter.</span></div>
  <?php else: ?>
  <div class="pf-grid">
    <?php foreach ($health['checks'] as $key => $chk):
        [$lbl, $icon] = $checkLabels[$key] ?? [ucfirst((string) $key), 'fa-circle-dot'];
        $variant = $mapHealth((string) ($chk['status'] ?? 'error'));
        // Sous-texte contextuel selon le type de sonde.
        $sub = null;
        if (isset($chk['latency_ms']))      { $sub = $chk['latency_ms'] . ' ms'; }
        elseif (isset($chk['used_percent'])){ $sub = $chk['used_percent'] . ' % · ' . ($chk['free_gb'] ?? '?') . ' Go libres'; }
        elseif (isset($chk['version']))     { $sub = 'v' . $chk['version']; }
        elseif (isset($chk['driver']))      { $sub = (string) $chk['driver']; }
        elseif (isset($chk['message']))     { $sub = (string) $chk['message']; }
        $valueMap = ['ok' => 'OK', 'warn' => 'DÉGRADÉ', 'crit' => 'ERREUR'];
    ?>
      <div class="pf-stat is-<?= $h($variant) ?>">
        <span class="pf-stat__label"><i class="fas <?= $h($icon) ?>" style="margin-right:6px;opacity:.7;"></i><?= $h($lbl) ?></span>
        <span class="pf-stat__value" style="font-size:20px;">
          <span class="pf-pill pf-pill--<?= $h($variant) ?>"><?= $h($valueMap[$variant] ?? $variant) ?></span>
        </span>
        <?php if ($sub !== null): ?><span class="pf-stat__sub"><?= $h($sub) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ═══ Charge par établissement (un seul GROUP BY) ═══ -->
<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-people-roof"></i> Charge par établissement</h2>
      <div class="pf-card__actions">
        <span class="pf-badge pf-badge--soft"><?= (int) $totalActive ?> actifs / <?= (int) $totalAccounts ?> comptes</span>
      </div>
    </div>
    <div class="pf-card__body pf-card__body--flush">
      <?php if (empty($tenants)): ?>
        <div class="pf-empty">Aucun établissement à superviser.</div>
      <?php else: ?>
      <div class="pf-table-wrap">
        <table class="pf-table pf-table--compact">
          <thead>
            <tr>
              <th>Établissement</th>
              <th>Statut</th>
              <th class="pf-num">Comptes</th>
              <th class="pf-num">Actifs</th>
              <th class="pf-num">Actifs 30 j</th>
              <th style="width:26%;">Répartition</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tenants as $t):
                $comptes = (int) $t['comptes'];
                $actifs  = (int) $t['actifs'];
                $a30     = (int) $t['actifs_30j'];
                $st      = (string) ($t['status'] ?? '');
                $variant = $tenantPill[$st] ?? 'muted';
                $ratio   = $comptes > 0 ? (int) round($actifs / $comptes * 100) : 0;
            ?>
            <tr>
              <td>
                <div style="font-weight:600;"><?= $h($t['nom'] ?? ('#' . ($t['eid'] ?? '?'))) ?></div>
                <?php if (!empty($t['ville'])): ?><div class="pf-muted" style="font-size:11.5px;"><?= $h($t['ville']) ?></div><?php endif; ?>
              </td>
              <td><span class="pf-pill pf-pill--<?= $h($variant) ?>"><?= $h($st ?: 'n/d') ?></span></td>
              <td class="pf-num"><?= $comptes ?></td>
              <td class="pf-num"><?= $actifs ?></td>
              <td class="pf-num"><?= $a30 ?></td>
              <td>
                <div class="pf-gauge" title="<?= $ratio ?> % actifs"><span style="width:<?= $ratio ?>%"></span></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:9px 14px; border-top:1px solid var(--pf-border);" class="pf-muted">
        <i class="fas fa-circle-info" style="font-size:11px;"></i>
        Comptes du miroir d'identité <span class="pf-mono">accounts</span> par <span class="pf-mono">etablissement_id</span> — une seule agrégation sur la base partagée.
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ═══ Instantané persistant (app_metrics) ═══ -->
<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-wave-square"></i> Dernier instantané persistant</h2>
      <div class="pf-card__actions"><span class="pf-badge pf-badge--soft"><span class="pf-mono">app_metrics</span></span></div>
    </div>
    <div class="pf-card__body pf-card__body--flush">
      <?php if (empty($snapshot)): ?>
        <div class="pf-empty">Aucune métrique enregistrée — le cron n'a pas encore écrit d'instantané.</div>
      <?php else:
        $snapLabels = [
            'heartbeat.cron'   => ['Battement cron', 's', 'fa-heart-pulse'],
            'backup.age_hours' => ['Âge sauvegarde', 'h', 'fa-database'],
            'sys.swap_percent' => ['Swap', '%', 'fa-memory'],
            'sys.disk_percent' => ['Stockage', '%', 'fa-hard-drive'],
            'db.connections'   => ['Connexions DB', '', 'fa-plug'],
        ];
      ?>
      <div class="pf-table-wrap">
        <table class="pf-table pf-table--compact">
          <thead><tr><th>Métrique</th><th class="pf-num">Valeur</th><th>Enregistrée</th></tr></thead>
          <tbody>
            <?php foreach ($snapLabels as $key => $meta):
                if (!isset($snapshot[$key])) { continue; }
                [$lbl, $unit, $icon] = $meta;
                $val = $snapshot[$key]['value'];
                $disp = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
            ?>
            <tr>
              <td><i class="fas <?= $h($icon) ?>" style="opacity:.6;margin-right:7px;"></i><?= $h($lbl) ?></td>
              <td class="pf-num"><?= $h($disp) ?><?= $unit !== '' ? '&nbsp;' . $h($unit) : '' ?></td>
              <td class="pf-mono pf-muted"><?= $h($snapshot[$key]['recorded_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php pf_layout_footer(); ?>
