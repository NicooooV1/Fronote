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

<div style="width:100%;max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-globe-europe"></i> <?= __('echanges.title') ?></h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:28px">
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_programmes'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('echanges.programmes') ?></div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_participants'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('echanges.participants') ?></div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_partenaires_actifs'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= __('echanges.partenaires_actifs') ?></div>
        </div>
    </div>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-plane-departure"></i> <?= __('echanges.programmes') ?> (<?= count($programmes) ?>)</h2>
    <?php if (empty($programmes)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('echanges.aucun_programme') ?></p>
    <?php else: ?>
    <div style="overflow-x:auto;margin-bottom:28px">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px"><?= __('label.titre') ?></th><th style="padding:8px 10px"><?= __('echanges.destination') ?></th>
            <th style="padding:8px 10px"><?= __('label.periode') ?></th><th style="padding:8px 10px"><?= __('echanges.inscrits') ?></th>
            <th style="padding:8px 10px"><?= __('label.statut') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($programmes as $p): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($p['titre']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($p['pays_destination'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($p['date_debut'] ?? '') ?> → <?= htmlspecialchars($p['date_fin'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= (int) ($p['nb_inscrits'] ?? 0) ?> / <?= (int) $p['places_max'] ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($statutLabels[$p['statut']] ?? $p['statut']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-handshake"></i> <?= __('echanges.partenariats_actifs') ?> (<?= count($partenariats) ?>)</h2>
    <?php if (empty($partenariats)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('echanges.aucun_partenariat') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($partenariats as $pt): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:12px 16px;min-width:220px">
            <strong><?= htmlspecialchars($pt['nom_etablissement_partenaire']) ?></strong>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)"><?= htmlspecialchars(trim(($pt['ville'] ?? '') . ', ' . ($pt['pays'] ?? ''), ', ')) ?></div>
            <?php if (!empty($pt['type_partenariat'])): ?><div style="font-size:.8em;color:#a0aec0"><?= htmlspecialchars($pt['type_partenariat']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
