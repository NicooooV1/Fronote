<?php
/**
 * Module Inventaire & patrimoine IT.
 * Vue d'ensemble parc : stats + maintenances à venir + prêts en retard.
 */
$pageTitle  = 'Inventaire & patrimoine IT';
$activePage = 'inventaire';
require_once __DIR__ . '/../../API/module_boot.php';
requireRole('administrateur', 'professeur');

require_once __DIR__ . '/includes/InventaireService.php';
$svc    = new \Inventaire\InventaireService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$stats        = $svc->getStatistiquesParc($etabId);
$maintenances = $svc->getMaintenancesAVenir($etabId, 30);
$retards      = $svc->getPretsEnRetard($etabId);

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-laptop-house"></i> Inventaire &amp; patrimoine IT</h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:28px">
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:1.7em;font-weight:700;color:var(--primary,#0f4c81)"><?= number_format($stats['valeur_achat_totale'], 0, ',', ' ') ?> €</div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">Valeur d'achat totale</div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:1.7em;font-weight:700;color:var(--primary,#0f4c81)"><?= number_format($stats['valeur_residuelle_totale'], 0, ',', ' ') ?> €</div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">Valeur résiduelle</div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:1.7em;font-weight:700;color:<?= $stats['pannes_ouvertes'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= (int) $stats['pannes_ouvertes'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">Pannes ouvertes</div>
        </div>
    </div>

    <h2 style="font-size:1.1em;margin:0 0 12px">Parc par type</h2>
    <?php if (empty($stats['par_type'])): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucun équipement enregistré.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:28px">
        <?php foreach ($stats['par_type'] as $t): ?>
        <span style="background:#eef2ff;color:#3730a3;border-radius:20px;padding:5px 14px;font-size:.85em"><?= htmlspecialchars($t['type_asset'] ?? '—') ?> · <strong><?= (int) $t['nb'] ?></strong></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($retards)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px;color:#b91c1c"><i class="fas fa-exclamation-circle"></i> Prêts en retard (<?= count($retards) ?>)</h2>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:28px;font-size:.9em">
        <?php foreach ($retards as $r): ?>
        <div><?= htmlspecialchars($r['asset_nom'] ?? '') ?> — retour prévu le <?= htmlspecialchars($r['date_retour_prevue'] ?? '') ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-tools"></i> Maintenances à venir (<?= count($maintenances) ?>)</h2>
    <?php if (empty($maintenances)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune maintenance planifiée dans les 30 jours.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px">Équipement</th><th style="padding:8px 10px">Type</th>
            <th style="padding:8px 10px">Prévue le</th>
        </tr></thead>
        <tbody>
        <?php foreach ($maintenances as $m): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($m['asset_nom'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($m['type_maintenance'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($m['date_prevue'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
