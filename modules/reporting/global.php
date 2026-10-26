<?php
declare(strict_types=1);
/**
 * M22 – Reporting — Vue globale établissement
 * Dashboard avec KPI, comparaisons inter-classes, tendances mensuelles
 */
$pageTitle = 'Reporting';
$pageSubtitle = "Vue d'ensemble de l'établissement";
require_once __DIR__ . '/includes/header.php';

$statsGlobales   = $reportService->getStatsGlobales();
$moyParClasse    = $reportService->getMoyennesParClasse();
$evolution       = $reportService->getEvolutionMensuelle();
$repartitionNotes = $reportService->getRepartitionNotes();
$absParClasse    = $reportService->getAbsencesParClasse();
$tauxReussite    = $reportService->getTauxReussiteParClasse();
$topIncidents    = $reportService->getTopIncidents();
?>
<?php
$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$rpData = [
    'moyClasse'  => ['labels' => array_column($moyParClasse, 'classe'), 'values' => array_map(fn($r) => round((float) $r['moyenne'], 2), $moyParClasse)],
    'reussite'   => ['labels' => array_column($tauxReussite, 'classe'), 'values' => array_map(fn($r) => round((float) $r['taux'], 1), $tauxReussite)],
    'evolution'  => ['labels' => array_column($evolution, 'mois'), 'absences' => array_map('intval', array_column($evolution, 'absences')), 'retards' => array_map('intval', array_column($evolution, 'retards'))],
    'repartition'=> ['labels' => array_column($repartitionNotes, 'tranche'), 'values' => array_map('intval', array_column($repartitionNotes, 'count'))],
];
$kpi = static fn(string $icon, $value, string $label, string $tone = '') =>
    '<div class="rp-kpi' . ($tone ? ' rp-kpi--' . $tone : '') . '"><span class="rp-kpi__ic"><i class="' . $icon . '" aria-hidden="true"></i></span>'
    . '<span><strong>' . htmlspecialchars((string) $value) . '</strong><small>' . htmlspecialchars($label) . '</small></span></div>';
?>
<div class="rp">
    <div class="rp-kpis">
        <?= $kpi('fas fa-user-graduate', $statsGlobales['total_eleves'], 'Élèves actifs') ?>
        <?= $kpi('fas fa-chalkboard-teacher', $statsGlobales['total_profs'], 'Professeurs') ?>
        <?= $kpi('fas fa-chart-bar', str_replace('.', ',', (string) $statsGlobales['moyenne_globale']) . '/20', 'Moyenne établissement', 'primary') ?>
        <?= $kpi('fas fa-calendar-times', $statsGlobales['absences_mois'], 'Absences ce mois', 'warning') ?>
        <?= $kpi('fas fa-exclamation-triangle', $statsGlobales['incidents_ouverts'], 'Incidents ouverts', 'danger') ?>
        <?= $kpi('fas fa-file-invoice-dollar', $statsGlobales['taux_recouvrement'] . ' %', 'Recouvrement', 'info') ?>
    </div>

    <div class="rp-grid">
        <section class="rp-card">
            <header class="rp-card__head">
                <h2><i class="fas fa-chart-bar" aria-hidden="true"></i>Moyennes par classe</h2>
                <span class="rp-legend"><i class="rp-dot rp-dot--good"></i>≥ 12 <i class="rp-dot rp-dot--mid"></i>10–12 <i class="rp-dot rp-dot--low"></i>&lt; 10</span>
            </header>
            <div class="rp-chart"><canvas id="chartMoyClasse" role="img" aria-label="Moyenne de chaque classe sur 20"></canvas></div>
        </section>
        <section class="rp-card">
            <header class="rp-card__head">
                <h2><i class="fas fa-trophy" aria-hidden="true"></i>Taux de réussite par classe</h2>
                <span class="rp-hint">part des élèves ≥ 10/20</span>
            </header>
            <div class="rp-chart"><canvas id="chartReussite" role="img" aria-label="Pourcentage d'élèves ayant au moins 10 sur 20, par classe"></canvas></div>
        </section>
        <section class="rp-card">
            <header class="rp-card__head">
                <h2><i class="fas fa-chart-line" aria-hidden="true"></i>Absences et retards</h2>
                <span class="rp-hint">depuis septembre, par mois</span>
            </header>
            <div class="rp-chart"><canvas id="chartEvolution" role="img" aria-label="Nombre d'absences et de retards par mois"></canvas></div>
        </section>
        <section class="rp-card">
            <header class="rp-card__head">
                <h2><i class="fas fa-chart-area" aria-hidden="true"></i>Répartition des notes</h2>
                <span class="rp-hint">toutes classes, sur 20</span>
            </header>
            <div class="rp-chart"><canvas id="chartRepartition" role="img" aria-label="Nombre de notes par tranche"></canvas></div>
        </section>
        <section class="rp-card">
            <header class="rp-card__head"><h2><i class="fas fa-calendar-times" aria-hidden="true"></i>Absences par classe</h2></header>
            <div class="rp-table">
                <table class="ds-table">
                    <thead><tr><th><?= __('label.classe') ?></th><th class="num"><?= __('label.total') ?></th><th class="num">Justifiées</th><th class="num"><?= __('label.eleves') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($absParClasse as $ac): ?>
                        <tr>
                            <td><?= htmlspecialchars($ac['classe']) ?></td>
                            <td class="num"><strong><?= (int) $ac['total_absences'] ?></strong></td>
                            <td class="num"><?= (int) $ac['justifiees'] ?></td>
                            <td class="num"><?= (int) $ac['eleves_concernes'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$absParClasse): ?><tr><td colspan="4" class="rp-empty">Aucune absence.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="rp-card">
            <header class="rp-card__head"><h2><i class="fas fa-gavel" aria-hidden="true"></i>Types d'incidents</h2></header>
            <div class="rp-table">
                <?php if (empty($topIncidents)): ?>
                    <p class="rp-empty">Aucun incident enregistré.</p>
                <?php else: ?>
                <table class="ds-table">
                    <thead><tr><th><?= __('label.type') ?></th><th class="num"><?= __('label.total') ?></th><th class="num">Résolus</th><th class="num"><?= __('status.en_cours') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($topIncidents as $inc): ?>
                        <tr>
                            <td><?= htmlspecialchars($inc['type']) ?></td>
                            <td class="num"><strong><?= (int) $inc['total'] ?></strong></td>
                            <td class="num text-success"><?= (int) $inc['resolus'] ?></td>
                            <td class="num text-warning"><?= (int) $inc['total'] - (int) $inc['resolus'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<script type="application/json" id="rp-data"><?= json_encode($rpData, $J) ?></script>
<script src="<?= asset_url('assets/lib/chartjs/chart.umd.min.js') ?>" nonce="<?= csp_nonce() ?>"></script>
<script src="<?= asset_url('assets/js/chart-empty-state.js') ?>" nonce="<?= csp_nonce() ?>"></script>
<script src="<?= asset_url('modules/reporting/assets/js/reporting-charts.js') ?>" nonce="<?= csp_nonce() ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
