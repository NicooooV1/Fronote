<?php
declare(strict_types=1);
/**
 * Module Intelligence — Analyse prédictive du risque de décrochage.
 * Dashboard RAG (vert/jaune/orange/rouge), top élèves à risque, alertes actives.
 */
$pageTitle  = 'Analyse prédictive';
$activePage = 'intelligence';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.intelligence.access'); // (administrateur, vie_scolaire par défaut — éditable plateforme)

require_once __DIR__ . '/includes/IntelligenceService.php';
$svc    = new \Intelligence\IntelligenceService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$flash = '';
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'recalculer') {
        $n = $svc->recalculerTous($etabId);
        $a = $svc->genererAlertes($etabId);
        if (function_exists('app') && ($__audit = app('audit'))) { $__audit->log('intelligence.recalcul', 'intelligence_scores', ['new' => ['scores' => $n, 'alertes' => $a]]); }
        $flash = "{$n} score(s) recalculé(s), {$a} alerte(s) générée(s).";
    } elseif ($action === 'traiter_alerte') {
        $svc->traiterAlerte((int) ($_POST['alerte_id'] ?? 0), trim($_POST['action_prise'] ?? '') ?: 'Traité', getUserId());
        if (function_exists('app') && ($__audit = app('audit'))) { $__audit->log('intelligence.alerte_traitee', 'intelligence_alertes', ['new' => ['alerte_id' => (int) ($_POST['alerte_id'] ?? 0)]]); }
        $flash = 'Alerte traitée.';
    }
}

$dash    = $svc->getDashboardRisque($etabId);
$alertes = $svc->getAlertes($etabId, 'active');
$dist    = $dash['distribution'];
$ragColors = ['rouge' => '#dc2626', 'orange' => '#f59e0b', 'jaune' => '#eab308', 'vert' => '#16a34a'];
$ragLabels = ['rouge' => __('intelligence.risque_eleve'), 'orange' => __('intelligence.risque_modere'), 'jaune' => __('intelligence.a_surveiller'), 'vert' => __('intelligence.aucun_risque')];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="width:100%;max-width:1100px;margin:24px auto;padding:0 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <h1 style="font-size:1.5em;margin:0"><i class="fas fa-brain"></i> <?= __('intelligence.analyse_predictive_titre') ?></h1>
        <form method="post" data-fr-confirm="Recalculer les scores de tous les élèves ?">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token) ?>">
            <input type="hidden" name="action" value="recalculer">
            <button type="submit" style="background:var(--primary,#0f4c81);color:#fff;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600">
                <i class="fas fa-rotate"></i> <?= __('intelligence.recalculer') ?>
            </button>
        </form>
    </div>

    <?php if ($flash): ?>
    <div style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:12px 16px;border-radius:8px;margin-bottom:18px"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Distribution RAG -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:28px">
        <?php foreach (['rouge','orange','jaune','vert'] as $niv): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-left:5px solid <?= $ragColors[$niv] ?>;border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:<?= $ragColors[$niv] ?>"><?= (int) ($dist[$niv] ?? 0) ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= htmlspecialchars($ragLabels[$niv]) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Élèves à risque -->
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-triangle-exclamation"></i> <?= __('intelligence.eleves_a_risque') ?> (<?= count($dash['eleves_a_risque']) ?>)</h2>
    <?php if (empty($dash['eleves_a_risque'])): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('intelligence.aucun_eleve_risque') ?></p>
    <?php else: ?>
    <div style="overflow-x:auto;margin-bottom:28px">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px"><?= __('intelligence.eleve') ?></th><th style="padding:8px 10px"><?= __('label.classe') ?></th>
            <th style="padding:8px 10px"><?= __('intelligence.score') ?></th><th style="padding:8px 10px"><?= __('intelligence.niveau') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($dash['eleves_a_risque'] as $e): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($e['eleve']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($e['classe'] ?? '') ?></td>
                <td style="padding:8px 10px;font-weight:600"><?= (float) $e['score_risque'] ?>/100</td>
                <td style="padding:8px 10px"><span style="padding:2px 10px;border-radius:10px;font-size:.8em;font-weight:600;background:<?= $ragColors[$e['niveau_alerte']] ?? '#999' ?>22;color:<?= $ragColors[$e['niveau_alerte']] ?? '#999' ?>"><?= htmlspecialchars($e['niveau_alerte']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- Alertes actives -->
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-bell"></i> <?= __('intelligence.alertes_actives') ?> (<?= count($alertes) ?>)</h2>
    <?php if (empty($alertes)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('intelligence.aucune_alerte') ?></p>
    <?php else: ?>
    <?php foreach ($alertes as $al): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:12px 14px;margin-bottom:8px">
            <div>
                <strong><?= htmlspecialchars($al['eleve_nom']) ?></strong> <span style="color:#a0aec0">(<?= htmlspecialchars($al['classe'] ?? '') ?>)</span>
                <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= htmlspecialchars($al['message']) ?></div>
            </div>
            <form method="post" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token) ?>">
                <input type="hidden" name="action" value="traiter_alerte">
                <input type="hidden" name="alerte_id" value="<?= (int) $al['id'] ?>">
                <input type="text" name="action_prise" placeholder="<?= __('intelligence.action_prise') ?>" style="padding:6px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px;font-size:.85em">
                <button type="submit" style="background:#16a34a;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:.85em"><?= __('intelligence.traiter') ?></button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
