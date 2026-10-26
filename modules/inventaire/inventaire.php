<?php
declare(strict_types=1);
/**
 * Module Inventaire & patrimoine IT.
 * Vue d'ensemble parc : stats + maintenances à venir + prêts en retard.
 */
$pageTitle  = 'Inventaire & patrimoine IT';
$activePage = 'inventaire';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.inventaire.access'); // (administrateur, professeur par défaut — éditable plateforme)

require_once __DIR__ . '/includes/InventaireService.php';
$svc    = new \Inventaire\InventaireService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$stats        = $svc->getStatistiquesParc($etabId);
$maintenances = $svc->getMaintenancesAVenir($etabId, 30);
$retards      = $svc->getPretsEnRetard($etabId);

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="mk-page">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-laptop-house"></i> <?= __('inventaire.titre_page') ?></h1>

    <div class="stats-grid">
        <div class="stat-card mk-stack">
            <div style="font-size:1.7em;font-weight:700;color:var(--primary,#0f4c81)"><?= number_format((float) ($stats['valeur_achat_totale']), 0, ',', ' ') ?> €</div>
            <div class="stat-label"><?= __('inventaire.valeur_achat_totale') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div style="font-size:1.7em;font-weight:700;color:var(--primary,#0f4c81)"><?= number_format((float) ($stats['valeur_residuelle_totale']), 0, ',', ' ') ?> €</div>
            <div class="stat-label"><?= __('inventaire.valeur_residuelle') ?></div>
        </div>
        <div class="stat-card mk-stack">
            <div style="font-size:1.7em;font-weight:700;color:<?= $stats['pannes_ouvertes'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= (int) $stats['pannes_ouvertes'] ?></div>
            <div class="stat-label"><?= __('inventaire.pannes_ouvertes') ?></div>
        </div>
    </div>

    <h2 class="section-title"><?= __('inventaire.parc_par_type') ?></h2>
    <?php if (empty($stats['par_type'])): ?>
    <p class="empty-state mk-empty-sm"><?= __('inventaire.aucun_equipement') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:28px">
        <?php foreach ($stats['par_type'] as $t): ?>
        <span style="background:#eef2ff;color:#3730a3;border-radius:20px;padding:5px 14px;font-size:.85em"><?= htmlspecialchars($t['type_asset'] ?? '—') ?> · <strong><?= (int) $t['nb'] ?></strong></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($retards)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px;color:#b91c1c"><i class="fas fa-exclamation-circle"></i> <?= __('inventaire.prets_en_retard') ?> (<?= count($retards) ?>)</h2>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:28px;font-size:.9em">
        <?php foreach ($retards as $r): ?>
        <div><?= htmlspecialchars($r['asset_nom'] ?? '') ?> — <?= __('inventaire.retour_prevu_le') ?> <?= htmlspecialchars($r['date_retour_prevue'] ?? '') ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="section-title"><i class="fas fa-tools"></i> <?= __('inventaire.maintenances_a_venir') ?> (<?= count($maintenances) ?>)</h2>
    <?php if (empty($maintenances)): ?>
    <p class="empty-state mk-empty-sm"><?= __('inventaire.aucune_maintenance') ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th><?= __('inventaire.equipement') ?></th><th><?= __('label.type') ?></th>
            <th><?= __('inventaire.prevue_le') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($maintenances as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['asset_nom'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['type_maintenance'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['date_prevue'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
