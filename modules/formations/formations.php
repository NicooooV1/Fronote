<?php
declare(strict_types=1);
/**
 * Module Formation continue du personnel.
 * Vue d'ensemble : stats annuelles + catalogue publié + certifications expirant.
 */
$pageTitle  = 'Formation continue';
$activePage = 'formations';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.formations.access'); // (administrateur, professeur, vie_scolaire par défaut — éditable plateforme)

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

<div class="mk-page">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-chalkboard-teacher"></i> <?= __('formations.heading') ?></h1>

    <div class="stats-grid">
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= (int) $stats['nb_formations'] ?></div>
            <div class="stat-label"><?= __('formations.formations') ?> (<?= htmlspecialchars($annee) ?>)</div>
        </div>
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= (int) $stats['nb_participants'] ?></div>
            <div class="stat-label"><?= __('formations.participants') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= number_format((float) $stats['budget_consomme'], 0, ',', ' ') ?> €</div>
            <div class="stat-label"><?= __('formations.budget_consomme') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= $stats['note_satisfaction_moyenne'] !== null ? htmlspecialchars((string) $stats['note_satisfaction_moyenne']) . '/5' : '—' ?></div>
            <div class="stat-label"><?= __('formations.satisfaction_moyenne') ?></div>
        </div>
    </div>

    <?php if (!empty($expirant)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px;color:#b45309"><i class="fas fa-clock"></i> <?= __('formations.certifications_expirant') ?> (<?= count($expirant) ?>)</h2>
    <div style="background:var(--ds-warning-light,#fffbeb);color:var(--text-color,#1d1d1f);border:1px solid var(--ds-warning,#fcd34d);border-radius:8px;padding:12px 16px;margin-bottom:28px;font-size:.9em">
        <?php foreach ($expirant as $c): ?>
        <div><?= htmlspecialchars($c['personnel_nom'] ?? '') ?> — <?= htmlspecialchars($c['titre'] ?? '') ?> (expire le <?= htmlspecialchars($c['date_expiration'] ?? '') ?>)</div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="section-title"><i class="fas fa-book-open"></i> <?= __('formations.catalogue') ?> (<?= count($catalogue) ?>)</h2>
    <?php if (empty($catalogue)): ?>
    <p class="empty-state mk-empty-sm"><?= __('formations.aucune_formation') ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th><?= __('label.titre') ?></th><th><?= __('formations.organisme') ?></th>
            <th><?= __('label.periode') ?></th><th><?= __('formations.places') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($catalogue as $f): ?>
            <tr style="border-bottom:1px solid var(--border-color,#f7fafc)">
                <td><?= htmlspecialchars($f['titre']) ?></td>
                <td><?= htmlspecialchars($f['organisme'] ?? '') ?></td>
                <td><?= htmlspecialchars($f['date_debut'] ?? '') ?> → <?= htmlspecialchars($f['date_fin'] ?? '') ?></td>
                <td><?= (int) ($f['nb_inscrits'] ?? 0) ?> / <?= (int) $f['places_max'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
