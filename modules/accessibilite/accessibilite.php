<?php
/**
 * Module Accessibilité — aménagements, AESH, MDPH/ESS.
 * Vue d'ensemble établissement (stats + liste AESH).
 */
$pageTitle  = 'Accessibilité & inclusion';
$activePage = 'accessibilite';
require_once __DIR__ . '/../../API/module_boot.php';
requireRole('administrateur', 'vie_scolaire');

require_once __DIR__ . '/includes/AccessibiliteService.php';
$svc    = new \Accessibilite\AccessibiliteService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$stats = $svc->getStatistiques($etabId);
$aesh  = $svc->getAeshList($etabId);
$expirant = [];
try { $expirant = $svc->getDecisionsExpirant($etabId); } catch (\Throwable $e) { $expirant = []; }

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-universal-access"></i> Accessibilité & inclusion</h1>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:28px">
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_eleves_amenages'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">Élèves avec aménagement</div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (int) $stats['nb_aesh'] ?></div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">AESH actifs</div>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= (float) $stats['total_heures_aesh'] ?>h</div>
            <div style="font-size:.85em;color:var(--text-muted,#64748b)">Heures AESH/semaine</div>
        </div>
    </div>

    <h2 style="font-size:1.1em;margin:0 0 12px">Aménagements par type</h2>
    <?php if (empty($stats['amenagements_par_type'])): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucun aménagement enregistré.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:28px">
        <?php foreach ($stats['amenagements_par_type'] as $t): ?>
        <span style="background:#eef2ff;color:#3730a3;border-radius:20px;padding:5px 14px;font-size:.85em"><?= htmlspecialchars($t['type_amenagement']) ?> · <strong><?= (int) $t['nb'] ?></strong></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($expirant)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px;color:#b45309"><i class="fas fa-clock"></i> Décisions MDPH expirant bientôt (<?= count($expirant) ?>)</h2>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:28px;font-size:.9em">
        <?php foreach ($expirant as $d): ?>
        <div><?= htmlspecialchars(($d['eleve_nom'] ?? ('Élève #' . ($d['eleve_id'] ?? '?')))) ?> — expire le <?= htmlspecialchars($d['date_expiration'] ?? '') ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-hands-helping"></i> AESH (<?= count($aesh) ?>)</h2>
    <?php if (empty($aesh)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucun AESH enregistré.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px">Nom</th><th style="padding:8px 10px">Élèves suivis</th><th style="padding:8px 10px">Heures/sem.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($aesh as $a): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')) ?></td>
                <td style="padding:8px 10px"><?= (int) ($a['nb_eleves'] ?? 0) ?></td>
                <td style="padding:8px 10px"><?= (float) ($a['total_heures'] ?? 0) ?>h</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
