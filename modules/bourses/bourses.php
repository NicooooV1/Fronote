<?php
/**
 * Module Bourses & aides financières.
 * Admin : campagne (stats) + demandes à instruire. Tous : simulateur d'éligibilité.
 */
$pageTitle  = 'Bourses & aides financières';
$activePage = 'bourses';
require_once __DIR__ . '/../../API/module_boot.php';
requireRole('administrateur', 'parent', 'vie_scolaire');

require_once __DIR__ . '/includes/BoursesService.php';
$svc    = new \Bourses\BoursesService($pdo);
$etabId = \API\Core\EstablishmentContext::id();
$role   = getUserRole();
$isAdmin = in_array($role, ['administrateur', 'vie_scolaire'], true);

$m = (int) date('n');
$y = (int) date('Y');
$annee = $m >= 9 ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);

$simulation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'simuler') {
        $rf = max(0, (float) ($_POST['revenu_fiscal'] ?? 0));
        $ne = max(1, (int) ($_POST['nb_enfants'] ?? 1));
        $simulation = $svc->simulerEligibilite($rf, $ne);
    }
}

$stats = ['par_statut' => [], 'par_type' => [], 'par_echelon' => []];
$aInstruire = [];
if ($isAdmin) {
    try { $stats = $svc->getStatistiquesCampagne($etabId, $annee); } catch (\Throwable $e) { error_log('[bourses.php] ' . $e->getMessage()); }
    try { $aInstruire = $svc->getDemandesAInstruire($etabId); } catch (\Throwable $e) { error_log('[bourses.php] ' . $e->getMessage()); }
}

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 4px"><i class="fas fa-hand-holding-usd"></i> Bourses &amp; aides financières</h1>
    <p style="color:var(--text-muted,#64748b);margin:0 0 24px">Campagne <?= htmlspecialchars($annee) ?></p>

    <!-- Simulateur (tous rôles) -->
    <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:18px;margin-bottom:28px">
        <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-calculator"></i> Simulateur d'éligibilité</h2>
        <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token) ?>">
            <input type="hidden" name="action" value="simuler">
            <label style="display:flex;flex-direction:column;font-size:.85em;color:var(--text-muted,#64748b)">
                Revenu fiscal de référence (€)
                <input type="number" name="revenu_fiscal" min="0" step="1" required value="<?= htmlspecialchars((string) ($_POST['revenu_fiscal'] ?? '')) ?>" style="margin-top:4px;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px;width:180px">
            </label>
            <label style="display:flex;flex-direction:column;font-size:.85em;color:var(--text-muted,#64748b)">
                Nombre d'enfants à charge
                <input type="number" name="nb_enfants" min="1" step="1" required value="<?= htmlspecialchars((string) ($_POST['nb_enfants'] ?? '1')) ?>" style="margin-top:4px;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px;width:120px">
            </label>
            <button type="submit" style="background:var(--primary,#0f4c81);color:#fff;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600">Simuler</button>
        </form>
        <?php if ($simulation !== null): ?>
        <div style="margin-top:16px;padding:14px 16px;border-radius:8px;background:<?= $simulation['eligible'] ? '#d1fae5' : '#fef2f2' ?>;color:<?= $simulation['eligible'] ? '#065f46' : '#991b1b' ?>">
            <?php if ($simulation['eligible']): ?>
            <strong>Éligible</strong> — échelon <?= (int) $simulation['echelon'] ?> · <?= (float) $simulation['montant_annuel'] ?> €/an
            (<?= (float) $simulation['montant_trimestriel'] ?> €/trimestre). Quotient familial : <?= (float) $simulation['quotient_familial'] ?> €.
            <?php else: ?>
            <strong>Non éligible</strong> — quotient familial <?= (float) $simulation['quotient_familial'] ?> € au-dessus des plafonds.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Stats campagne -->
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-chart-pie"></i> État de la campagne</h2>
    <?php if (empty($stats['par_statut'])): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune demande pour cette campagne.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:28px">
        <?php foreach ($stats['par_statut'] as $s): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 18px;min-width:140px">
            <div style="font-size:1.6em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $s['nb'] ?></div>
            <div style="font-size:.8em;color:var(--text-muted,#64748b);text-transform:capitalize"><?= htmlspecialchars($s['statut']) ?></div>
            <?php if ((float) $s['montant_total'] > 0): ?>
            <div style="font-size:.75em;color:#16a34a"><?= number_format((float) $s['montant_total'], 0, ',', ' ') ?> €</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Demandes à instruire -->
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-inbox"></i> Demandes à instruire (<?= count($aInstruire) ?>)</h2>
    <?php if (empty($aInstruire)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune demande en attente d'instruction.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px">Élève</th><th style="padding:8px 10px">Classe</th>
            <th style="padding:8px 10px">Type</th><th style="padding:8px 10px">Échelon simulé</th>
            <th style="padding:8px 10px">Soumise le</th>
        </tr></thead>
        <tbody>
        <?php foreach ($aInstruire as $d): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($d['eleve_nom']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($d['classe'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($d['type_bourse'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= (int) $d['echelon_simule'] ?> (<?= (float) $d['montant_simule'] ?> €)</td>
                <td style="padding:8px 10px"><?= htmlspecialchars($d['date_soumission'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
