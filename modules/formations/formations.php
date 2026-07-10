<?php
declare(strict_types=1);
/**
 * Module Formation continue du personnel.
 * Vue d'ensemble : stats annuelles + catalogue publié + certifications expirant.
 */
$pageTitle  = 'Formation continue';
$activePage = 'formations';
require_once __DIR__ . '/../../API/module_boot.php';
requireRole('administrateur', 'professeur', 'vie_scolaire');

require_once __DIR__ . '/includes/FormationService.php';
$svc    = new \Formations\FormationService($pdo);
$etabId = \API\Core\EstablishmentContext::id();
$annee  = (string) date('Y');

$stats     = $svc->getStatistiques($etabId, $annee);
$catalogue = $svc->getCatalogue($etabId);
$expirant  = [];
try { $expirant = $svc->checkExpirations($etabId, 60); } catch (\Throwable $e) { error_log('[formations.php] ' . $e->getMessage()); }

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-chalkboard-teacher"></i> <?= __('formations.heading') ?></h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:28px">
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_formations'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('formations.formations') ?> (<?= htmlspecialchars($annee) ?>)</div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_participants'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('formations.participants') ?></div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= number_format((float) $stats['budget_consomme'], 0, ',', ' ') ?> €</div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('formations.budget_consomme') ?></div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= $stats['note_satisfaction_moyenne'] !== null ? htmlspecialchars((string) $stats['note_satisfaction_moyenne']) . '/5' : '—' ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('formations.satisfaction_moyenne') ?></div>
        </div>
    </div>

    <?php if (!empty($expirant)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px;color:#b45309"><i class="fas fa-clock"></i> <?= __('formations.certifications_expirant') ?> (<?= count($expirant) ?>)</h2>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:28px;font-size:.9em">
        <?php foreach ($expirant as $c): ?>
        <div><?= htmlspecialchars($c['personnel_nom'] ?? '') ?> — <?= htmlspecialchars($c['titre'] ?? '') ?> (expire le <?= htmlspecialchars($c['date_expiration'] ?? '') ?>)</div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-book-open"></i> <?= __('formations.catalogue') ?> (<?= count($catalogue) ?>)</h2>
    <?php if (empty($catalogue)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px"><?= __('formations.aucune_formation') ?></p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px"><?= __('label.titre') ?></th><th style="padding:8px 10px"><?= __('formations.organisme') ?></th>
            <th style="padding:8px 10px"><?= __('label.periode') ?></th><th style="padding:8px 10px"><?= __('formations.places') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($catalogue as $f): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($f['titre']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($f['organisme'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($f['date_debut'] ?? '') ?> → <?= htmlspecialchars($f['date_fin'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= (int) ($f['nb_inscrits'] ?? 0) ?> / <?= (int) $f['places_max'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
