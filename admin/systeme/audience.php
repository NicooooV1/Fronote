<?php
declare(strict_types=1);
/**
 * Mesure d'audience interne — tableau de bord de la direction.
 * Données anonymes, collectées uniquement auprès des visiteurs ayant accepté (ConsentService).
 * Graphiques en SVG rendus côté serveur (aucune bibliothèque externe, compatible CSP stricte).
 */
use API\Services\Privacy\AudienceService;
use API\Services\Privacy\ConsentService;

$pageTitle = 'Mesure d\'audience';
$currentPage = 'audience';
$extraCss = ['../../assets/css/admin.css'];
include __DIR__ . '/../includes/header.php';

$days = (int) ($_GET['p'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) { $days = 30; }
$s = (new AudienceService(getPDO()))->stats($days);
$c = (new ConsentService(getPDO()))->stats($days);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$nf = fn($n) => number_format((float) $n, 0, ',', "\u{202F}");
$dur = function (?int $sec): string {
    if ($sec === null) { return '—'; }
    return $sec >= 60 ? intdiv($sec, 60) . ' min ' . str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT) . ' s' : $sec . ' s';
};
$modName = function (string $k): string {
    static $cache = [];
    if (isset($cache[$k])) { return $cache[$k]; }
    $labels = ['admin' => 'Administration', 'accueil' => 'Accueil', 'login' => 'Connexion', 'parametres' => 'Paramètres', 'rgpd' => 'RGPD', 'demo' => 'Démonstration', 'autre' => 'Autres pages'];
    if (isset($labels[$k])) { return $cache[$k] = $labels[$k]; }
    $f = BASE_PATH . '/modules/' . basename($k) . '/module.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $n = is_array($j['name'] ?? null) ? ($j['name']['fr'] ?? $k) : ($j['name'] ?? $k);
    return $cache[$k] = (string) $n;
};
$roleName = ['eleve' => 'Élèves', 'parent' => 'Parents', 'professeur' => 'Professeurs', 'vie_scolaire' => 'Vie scolaire', 'administrateur' => 'Direction', 'anonyme' => 'Non connectés'];
$roleColor = ['eleve' => '#059669', 'parent' => '#d97706', 'professeur' => '#2563eb', 'vie_scolaire' => '#7c3aed', 'administrateur' => '#be123c', 'anonyme' => '#94a3b8'];
$avgUv = $s['series'] ? array_sum(array_column($s['series'], 'uv')) / max(1, count($s['series'])) : 0;

// ── courbe quotidienne (SVG) ──
$W = 900; $H = 220; $pad = 34;
$maxPv = max(1, max(array_column($s['series'], 'pv') ?: [0]));
$n = count($s['series']);
$x = fn($i) => $pad + ($n > 1 ? $i * ($W - 2 * $pad) / ($n - 1) : ($W - 2 * $pad) / 2);
$y = fn($v) => $H - 26 - ($v / $maxPv) * ($H - 50);
$pathPv = $pathUv = $area = '';
foreach ($s['series'] as $i => $p) {
    $pathPv .= ($i ? 'L' : 'M') . round($x($i), 1) . ',' . round($y($p['pv']), 1);
    $pathUv .= ($i ? 'L' : 'M') . round($x($i), 1) . ',' . round($y($p['uv']), 1);
}
if ($n) { $area = $pathPv . 'L' . round($x($n - 1), 1) . ',' . ($H - 26) . 'L' . round($x(0), 1) . ',' . ($H - 26) . 'Z'; }
$heat = []; $maxHeat = 1;
foreach ($s['heat'] as $r) { $heat[(int) $r['weekday']][(int) $r['hour']] = (int) $r['pv']; $maxHeat = max($maxHeat, (int) $r['pv']); }
?>
<style>
.aud { padding: 20px; max-width: 1200px; }
.aud-top { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.aud-note { color: var(--ds-text-muted, #6b7280); font-size: .88rem; max-width: 70ch; margin: 0; }
.aud-periods { display: flex; gap: 4px; background: var(--ds-bg, #f1f3f6); padding: 4px; border-radius: 10px; }
.aud-periods a { padding: 6px 12px; border-radius: 8px; font-size: .85rem; font-weight: 600; color: var(--ds-text-muted, #6b7280); text-decoration: none; }
.aud-periods a.is-on { background: var(--ds-surface, #fff); color: var(--ds-text, #1d1d1f); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.aud-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
.aud-kpi, .aud-card { background: var(--ds-surface, #fff); border: 1px solid var(--ds-border, #e5e7eb); border-radius: 14px; padding: 16px; }
.aud-kpi small { display: block; color: var(--ds-text-muted, #6b7280); font-size: .8rem; font-weight: 600; }
.aud-kpi b { display: block; font-size: 1.7rem; margin-top: 4px; font-variant-numeric: tabular-nums; }
.aud-kpi span { font-size: .78rem; color: var(--ds-text-muted, #6b7280); }
.aud-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 12px; margin-top: 12px; }
.aud-card h2 { font-size: .98rem; margin: 0 0 12px; }
.aud-svg { width: 100%; height: auto; display: block; }
.aud-legend { display: flex; gap: 16px; font-size: .8rem; color: var(--ds-text-muted, #6b7280); margin-top: 6px; }
.aud-legend i { display: inline-block; width: 12px; height: 3px; border-radius: 2px; vertical-align: middle; margin-right: 6px; }
.aud-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.aud-bars li { display: grid; grid-template-columns: 150px 1fr 60px; gap: 10px; align-items: center; font-size: .86rem; }
.aud-bars .bar { height: 10px; border-radius: 6px; background: var(--ds-bg, #eef0f3); overflow: hidden; }
.aud-bars .bar span { display: block; height: 100%; border-radius: 6px; background: var(--ds-primary, #0f4c81); }
.aud-bars .v { text-align: right; font-variant-numeric: tabular-nums; color: var(--ds-text-muted, #6b7280); }
.aud-bars .lbl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.aud-heat { display: grid; grid-template-columns: 34px repeat(17, 1fr); gap: 3px; font-size: .68rem; color: var(--ds-text-muted, #6b7280); }
.aud-heat span { aspect-ratio: 1; border-radius: 4px; }
.aud-heat .h { aspect-ratio: auto; text-align: center; }
.aud-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.aud-table th { text-align: left; font-size: .75rem; color: var(--ds-text-muted, #6b7280); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--ds-border, #e5e7eb); }
.aud-table td { padding: 7px 4px; border-bottom: 1px solid var(--ds-border, #f1f3f6); }
.aud-table td:not(:first-child), .aud-table th:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; }
.aud-table code { font-size: .8rem; }
.aud-empty { color: var(--ds-text-muted, #6b7280); font-size: .9rem; padding: 20px 0; text-align: center; }
@media (max-width: 600px) { .aud-bars li { grid-template-columns: 110px 1fr 50px; } }
</style>
<div class="aud">
  <div class="aud-top">
    <p class="aud-note"><i class="fas fa-user-shield" aria-hidden="true"></i> Statistiques anonymes, hébergées par l'établissement, collectées
      <strong>uniquement auprès des visiteurs qui l'ont accepté</strong> (sans nom, sans compte, sans adresse IP ; conservation 13 mois).</p>
    <nav class="aud-periods" aria-label="Période">
      <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '3 mois', 365 => '12 mois'] as $p => $l): ?>
      <a href="?p=<?= $p ?>" class="<?= $p === $days ? 'is-on' : '' ?>"<?= $p === $days ? ' aria-current="page"' : '' ?>><?= $l ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="aud-kpis">
    <div class="aud-kpi"><small>Pages vues</small><b><?= $nf($s['pageviews']) ?></b><span>sur <?= $days ?> jours</span></div>
    <div class="aud-kpi"><small>Visites</small><b><?= $nf($s['visits']) ?></b><span>visiteur × jour</span></div>
    <div class="aud-kpi"><small>Visiteurs par jour</small><b><?= $nf(round($avgUv)) ?></b><span>en moyenne</span></div>
    <div class="aud-kpi"><small>Durée moyenne d'une page</small><b><?= $h($dur($s['avg_duration'])) ?></b><span>temps actif</span></div>
    <div class="aud-kpi"><small>Acceptation de la mesure</small><b><?= $c['rate'] === null ? '—' : $c['rate'] . ' %' ?></b><span><?= $nf($c['accept']) ?> oui · <?= $nf($c['refuse']) ?> non</span></div>
  </div>

  <div class="aud-card">
    <h2>Fréquentation quotidienne</h2>
    <?php if (!$s['pageviews']): ?><p class="aud-empty">Aucune donnée sur la période : la mesure démarre dès que des visiteurs l'acceptent.</p><?php else: ?>
    <svg class="aud-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Pages vues et visiteurs par jour">
      <?php for ($g = 0; $g <= 4; $g++): $gy = $H - 26 - $g * ($H - 50) / 4; ?>
      <line x1="<?= $pad ?>" x2="<?= $W - $pad ?>" y1="<?= round($gy, 1) ?>" y2="<?= round($gy, 1) ?>" stroke="currentColor" stroke-opacity=".08"/>
      <text x="<?= $pad - 6 ?>" y="<?= round($gy + 4, 1) ?>" text-anchor="end" font-size="10" fill="currentColor" fill-opacity=".5"><?= (int) round($maxPv * $g / 4) ?></text>
      <?php endfor; ?>
      <path d="<?= $area ?>" fill="#2563eb" fill-opacity=".08"/>
      <path d="<?= $pathPv ?>" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linejoin="round"/>
      <path d="<?= $pathUv ?>" fill="none" stroke="#059669" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>
      <?php $step = max(1, (int) ceil($n / 8)); foreach ($s['series'] as $i => $p): if ($i % $step) continue; ?>
      <text x="<?= round($x($i), 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity=".55"><?= date('d/m', strtotime($p['day'])) ?></text>
      <?php endforeach; ?>
      <?php foreach ($s['series'] as $i => $p): ?><circle cx="<?= round($x($i), 1) ?>" cy="<?= round($y($p['pv']), 1) ?>" r="3" fill="#2563eb"><title><?= date('d/m', strtotime($p['day'])) ?> : <?= $p['pv'] ?> pages vues, <?= $p['uv'] ?> visiteurs</title></circle><?php endforeach; ?>
    </svg>
    <div class="aud-legend"><span><i style="background:#2563eb"></i>Pages vues</span><span><i style="background:#059669"></i>Visiteurs</span></div>
    <?php endif; ?>
  </div>

  <div class="aud-grid">
    <div class="aud-card">
      <h2>Modules les plus utilisés</h2>
      <?php if (!$s['modules']): ?><p class="aud-empty">—</p><?php else: $mx = max(array_map(fn($r) => (int) $r['pv'], $s['modules'])); ?>
      <ul class="aud-bars"><?php foreach ($s['modules'] as $r): ?>
        <li><span class="lbl"><?= $h($modName((string) $r['module'])) ?></span><span class="bar"><span style="width:<?= round(100 * (int) $r['pv'] / max(1, $mx)) ?>%"></span></span><span class="v"><?= $nf($r['pv']) ?></span></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </div>
    <div class="aud-card">
      <h2>Qui utilise Fronote</h2>
      <?php if (!$s['roles']): ?><p class="aud-empty">—</p><?php else: $mx = max(array_map(fn($r) => (int) $r['uv'], $s['roles'])); ?>
      <ul class="aud-bars"><?php foreach ($s['roles'] as $r): $k = (string) $r['role']; ?>
        <li><span class="lbl"><?= $h($roleName[$k] ?? $k) ?></span><span class="bar"><span style="width:<?= round(100 * (int) $r['uv'] / max(1, $mx)) ?>%;background:<?= $roleColor[$k] ?? '#64748b' ?>"></span></span><span class="v"><?= $nf($r['uv']) ?></span></li>
      <?php endforeach; ?></ul>
      <h2 style="margin-top:18px">Appareils</h2>
      <?php $tot = max(1, array_sum(array_map(fn($r) => (int) $r['uv'], $s['devices']))); ?>
      <ul class="aud-bars"><?php foreach ($s['devices'] as $r): ?>
        <li><span class="lbl"><i class="fas fa-<?= $r['device'] === 'mobile' ? 'mobile-alt' : ($r['device'] === 'tablette' ? 'tablet-alt' : 'desktop') ?>" aria-hidden="true"></i> <?= $h(ucfirst((string) $r['device'])) ?></span><span class="bar"><span style="width:<?= round(100 * (int) $r['uv'] / $tot) ?>%;background:#0891b2"></span></span><span class="v"><?= round(100 * (int) $r['uv'] / $tot) ?> %</span></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </div>
    <div class="aud-card">
      <h2>Moments d'affluence</h2>
      <div class="aud-heat" role="img" aria-label="Pages vues par jour de la semaine et par heure">
        <span class="h"></span><?php for ($hr = 6; $hr <= 22; $hr++): ?><span class="h"><?= $hr % 3 === 0 ? $hr . 'h' : '' ?></span><?php endfor; ?>
        <?php foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'] as $wd => $wl): ?>
          <span class="h" style="text-align:left"><?= $wl ?></span>
          <?php for ($hr = 6; $hr <= 22; $hr++): $v = $heat[$wd][$hr] ?? 0; $a = $v ? .12 + .88 * $v / $maxHeat : 0; ?>
          <span style="background:<?= $v ? 'rgba(37,99,235,' . round($a, 2) . ')' : 'var(--ds-bg,#f1f3f6)' ?>" title="<?= $wl ?> <?= $hr ?>h : <?= $v ?> pages vues"></span>
          <?php endfor; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="aud-card">
      <h2>Pages les plus consultées</h2>
      <?php if (!$s['pages']): ?><p class="aud-empty">—</p><?php else: ?>
      <table class="aud-table"><thead><tr><th>Page</th><th>Vues</th><th>Durée moy.</th></tr></thead><tbody>
      <?php foreach ($s['pages'] as $r): ?><tr><td><code><?= $h($r['path']) ?></code></td><td><?= $nf($r['pv']) ?></td><td><?= $h($dur($r['dur'] !== null ? (int) $r['dur'] : null)) ?></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
