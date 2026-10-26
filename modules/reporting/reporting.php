<?php
declare(strict_types=1);
/**
 * M22 – Reporting — Tableau de bord par classe
 */
$pageTitle = 'Reporting';
$pageSubtitle = 'Tableau de bord par classe';
require_once __DIR__ . '/includes/header.php';

$classes = $reportService->getClasses();
$periodes = $reportService->getPeriodes();
$classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : ($classes[0]['id'] ?? 0);
$periodeId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

$rapport = $classeId ? $reportService->getRapportClasse($classeId, $periodeId ?: null) : null;

$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$fr = static fn($v, int $d = 2): string => $v === null || $v === '' ? '–' : number_format((float) $v, $d, ',', ' ');
$kpi = static fn(string $icon, string $value, string $label, string $tone = '') =>
    '<div class="rp-kpi' . ($tone ? ' rp-kpi--' . $tone : '') . '"><span class="rp-kpi__ic"><i class="' . $icon . '" aria-hidden="true"></i></span>'
    . '<span><strong>' . htmlspecialchars($value) . '</strong><small>' . htmlspecialchars($label) . '</small></span></div>';
?>
<div class="rp">
    <form method="get" class="rp-filter">
        <label class="rp-field">
            <span><?= __('label.classe') ?></span>
            <select name="classe_id" data-fr-change="submitOwn" class="form-select">
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $c['id'] == $classeId ? 'selected' : '' ?>><?= htmlspecialchars($c['niveau'].' – '.$c['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="rp-field">
            <span><?= __('label.periode') ?></span>
            <select name="periode_id" data-fr-change="submitOwn" class="form-select">
                <option value="0">Toute l'année</option>
                <?php foreach ($periodes as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= ($p['id'] ?? 0) == $periodeId ? 'selected' : '' ?>><?= htmlspecialchars($p['nom'] ?? $p['libelle'] ?? 'Période '.$p['id']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn btn-primary">Afficher</button></noscript>
    </form>

    <?php if ($rapport):
        $mat = $rapport['moyennes_matieres'] ?? [];
        usort($mat, fn($a, $b) => (float) ($b['moyenne'] ?? 0) <=> (float) ($a['moyenne'] ?? 0));
        $rpData = [
            'matieres' => [
                'labels' => array_map(fn($m) => (string) ($m['matiere'] ?? $m['nom'] ?? '?'), $mat),
                'values' => array_map(fn($m) => round((float) ($m['moyenne'] ?? 0), 2), $mat),
                'min'    => array_map(fn($m) => round((float) ($m['min_note'] ?? 0), 1), $mat),
                'max'    => array_map(fn($m) => round((float) ($m['max_note'] ?? 0), 1), $mat),
                'nb'     => array_map(fn($m) => (int) ($m['nb_notes'] ?? 0), $mat),
            ],
            'vie' => [(int) $rapport['total_absences'], (int) $rapport['total_retards'], (int) $rapport['nb_incidents']],
        ];
        $classement = $rapport['classement'] ?? [];
    ?>
    <div class="rp-kpis">
        <?= $kpi('fas fa-users', (string) $rapport['effectif'], __('label.eleves')) ?>
        <?= $kpi('fas fa-chart-bar', $fr($rapport['moyenne_classe']) . '/20', 'Moyenne de la classe', 'primary') ?>
        <?= $kpi('fas fa-calendar-times', (string) $rapport['total_absences'], 'Absences', 'warning') ?>
        <?= $kpi('fas fa-clock', (string) $rapport['total_retards'], 'Retards', 'info') ?>
        <?= $kpi('fas fa-exclamation-triangle', (string) $rapport['nb_incidents'], 'Incidents', 'danger') ?>
    </div>

    <div class="rp-grid">
        <section class="rp-card">
            <header class="rp-card__head">
                <h2><i class="fas fa-chart-bar" aria-hidden="true"></i>Moyennes par matière</h2>
                <span class="rp-legend"><i class="rp-dot rp-dot--good"></i>≥ 12 <i class="rp-dot rp-dot--mid"></i>10–12 <i class="rp-dot rp-dot--low"></i>&lt; 10</span>
            </header>
            <div class="rp-chart"><canvas id="chartMoyennes" role="img" aria-label="Moyenne de la classe dans chaque matière"></canvas></div>
        </section>

        <div class="rp-stack">
            <section class="rp-card">
                <header class="rp-card__head"><h2><i class="fas fa-user-clock" aria-hidden="true"></i>Vie scolaire</h2></header>
                <div class="rp-chart rp-chart--sm"><canvas id="chartVie" role="img" aria-label="Absences, retards et incidents de la classe"></canvas></div>
            </section>
            <section class="rp-card">
                <header class="rp-card__head"><h2><i class="fas fa-file-csv" aria-hidden="true"></i>Exports</h2></header>
                <div class="rp-exports">
                    <a href="exporter.php?type=absences&amp;classe_id=<?= $classeId ?>&amp;go=1"><i class="fas fa-calendar-times" aria-hidden="true"></i>Absences</a>
                    <a href="exporter.php?type=notes&amp;classe_id=<?= $classeId ?>&amp;periode_id=<?= $periodeId ?>&amp;go=1"><i class="fas fa-chart-bar" aria-hidden="true"></i>Notes</a>
                    <a href="exporter.php?type=moyennes&amp;classe_id=<?= $classeId ?>&amp;periode_id=<?= $periodeId ?>&amp;go=1"><i class="fas fa-calculator" aria-hidden="true"></i>Moyennes</a>
                    <a href="exporter.php?type=incidents&amp;classe_id=<?= $classeId ?>&amp;go=1"><i class="fas fa-gavel" aria-hidden="true"></i>Incidents</a>
                </div>
            </section>
        </div>

        <section class="rp-card rp-card--wide">
            <header class="rp-card__head">
                <h2><i class="fas fa-trophy" aria-hidden="true"></i>Classement des élèves</h2>
                <span class="rp-hint"><?= count($classement) ?> élèves notés</span>
            </header>
            <?php if ($classement): ?>
            <div class="rp-table rp-table--tall">
                <table class="ds-table">
                    <thead><tr><th class="num">#</th><th><?= __('label.nom') ?></th><th><?= __('label.prenom') ?></th><th class="rp-bar-col"><?= __('label.moyenne') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($classement as $i => $el): $moy = $el['moyenne']; $tone = $moy === null ? '' : ((float) $moy >= 12 ? 'good' : ((float) $moy >= 10 ? 'mid' : 'low')); ?>
                        <tr>
                            <td class="num"><strong><?= $i + 1 ?></strong></td>
                            <td><?= htmlspecialchars($el['nom']) ?></td>
                            <td><?= htmlspecialchars($el['prenom']) ?></td>
                            <td class="rp-bar-col">
                                <span class="rp-meter rp-meter--<?= $tone ?>"><span data-w="<?= $moy === null ? 0 : max(0, min(100, (int) round((float) $moy * 5))) ?>"></span></span>
                                <strong class="rp-val rp-val--<?= $tone ?>"><?= $fr($moy) ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="rp-empty">Aucune note sur cette période.</p>
            <?php endif; ?>
        </section>
    </div>

    <script type="application/json" id="rp-data"><?= json_encode($rpData, $J) ?></script>
    <script src="<?= asset_url('assets/lib/chartjs/chart.umd.min.js') ?>" nonce="<?= csp_nonce() ?>"></script>
    <script src="<?= asset_url('assets/js/chart-empty-state.js') ?>" nonce="<?= csp_nonce() ?>"></script>
    <script src="<?= asset_url('modules/reporting/assets/js/reporting-charts.js') ?>" nonce="<?= csp_nonce() ?>"></script>
    <?php else: ?>
        <p class="rp-empty">Sélectionnez une classe pour voir le rapport.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
