<?php
declare(strict_types=1);
/**
 * Module Échanges & mobilité internationale.
 * Vue d'ensemble : stats + programmes + partenariats actifs.
 */
$pageTitle  = 'Échanges & mobilité';
$activePage = 'echanges';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.echanges.access'); // (administrateur, professeur, vie_scolaire par défaut — éditable plateforme)

require_once __DIR__ . '/includes/EchangesService.php';
$svc    = new \Echanges\EchangesService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$stats        = $svc->getStatistiques($etabId);
$programmes   = $svc->getProgrammes($etabId);
$partenariats = $svc->getPartenariats($etabId);

$statutLabels = ['planifie' => 'Planifié', 'candidatures_ouvertes' => 'Candidatures ouvertes', 'en_cours' => 'En cours', 'termine' => 'Terminé'];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="mk-page">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-globe-europe"></i> <?= __('echanges.title') ?></h1>

    <div class="stats-grid">
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= (int) $stats['nb_programmes'] ?></div>
            <div class="stat-label"><?= __('echanges.programmes') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= (int) $stats['nb_participants'] ?></div>
            <div class="stat-label"><?= __('echanges.participants') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div class="stat-value"><?= (int) $stats['nb_partenaires_actifs'] ?></div>
            <div class="stat-label"><?= __('echanges.partenaires_actifs') ?></div>
        </div>
    </div>

    <h2 class="section-title"><i class="fas fa-plane-departure"></i> <?= __('echanges.programmes') ?> (<?= count($programmes) ?>)</h2>
    <?php if (empty($programmes)): ?>
    <p class="empty-state mk-empty-sm"><?= __('echanges.aucun_programme') ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th><?= __('label.titre') ?></th><th><?= __('echanges.destination') ?></th>
            <th><?= __('label.periode') ?></th><th><?= __('echanges.inscrits') ?></th>
            <th><?= __('label.statut') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($programmes as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['titre']) ?></td>
                <td><?= htmlspecialchars($p['pays_destination'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['date_debut'] ?? '') ?> → <?= htmlspecialchars($p['date_fin'] ?? '') ?></td>
                <td><?= (int) ($p['nb_inscrits'] ?? 0) ?> / <?= (int) $p['places_max'] ?></td>
                <td><?= htmlspecialchars($statutLabels[$p['statut']] ?? $p['statut']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h2 class="section-title"><i class="fas fa-handshake"></i> <?= __('echanges.partenariats_actifs') ?> (<?= count($partenariats) ?>)</h2>
    <?php if (empty($partenariats)): ?>
    <p class="empty-state mk-empty-sm"><?= __('echanges.aucun_partenariat') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($partenariats as $pt): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:12px 16px;min-width:220px">
            <strong><?= htmlspecialchars($pt['nom_etablissement_partenaire']) ?></strong>
            <div class="stat-label"><?= htmlspecialchars(trim(($pt['ville'] ?? '') . ', ' . ($pt['pays'] ?? ''), ', ')) ?></div>
            <?php if (!empty($pt['type_partenariat'])): ?><div style="font-size:.8em;color:#a0aec0"><?= htmlspecialchars($pt['type_partenariat']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
