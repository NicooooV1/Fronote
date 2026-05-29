<?php
/**
 * Module Enquêtes & satisfaction.
 * Admin : pilotage des enquêtes (statut + participations). Autres : enquêtes ouvertes à répondre.
 */
$pageTitle  = 'Enquêtes & satisfaction';
$activePage = 'enquetes';
require_once __DIR__ . '/../../API/module_boot.php';
requireAuth();

require_once __DIR__ . '/includes/EnquetesService.php';
$svc    = new \Enquetes\EnquetesService($pdo);
$etabId = \API\Core\EstablishmentContext::id();
$role   = getUserRole();
$isAdmin = in_array($role, ['administrateur', 'vie_scolaire'], true);

$pilotage = [];
if ($isAdmin) {
    $st = $pdo->prepare("SELECT e.id, e.titre, e.type, e.statut, e.anonyme, e.date_fermeture,
            (SELECT COUNT(*) FROM enquete_participations p WHERE p.enquete_id = e.id AND p.completed = 1) AS nb_reponses
        FROM enquetes e WHERE e.etablissement_id = :e ORDER BY e.date_creation DESC");
    $st->execute([':e' => $etabId]);
    $pilotage = $st->fetchAll(PDO::FETCH_ASSOC);
}

$ouvertes = $svc->getEnquetesOuvertes($etabId, $role);

$statutLabels = ['brouillon' => 'Brouillon', 'ouverte' => 'Ouverte', 'fermee' => 'Fermée', 'archivee' => 'Archivée'];
$statutColors = ['brouillon' => '#64748b', 'ouverte' => '#16a34a', 'fermee' => '#0f4c81', 'archivee' => '#a0aec0'];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1000px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-poll-h"></i> Enquêtes &amp; satisfaction</h1>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-inbox"></i> À répondre (<?= count($ouvertes) ?>)</h2>
    <?php if (empty($ouvertes)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune enquête ouverte ne vous est destinée.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:28px">
        <?php foreach ($ouvertes as $e): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px">
            <strong><?= htmlspecialchars($e['titre']) ?></strong>
            <?php if (!empty($e['anonyme'])): ?><span style="background:#eef2ff;color:#3730a3;border-radius:12px;padding:2px 10px;font-size:.75em;margin-left:8px">Anonyme</span><?php endif; ?>
            <?php if (!empty($e['description'])): ?><div style="font-size:.85em;color:var(--text-muted,#64748b);margin-top:4px"><?= htmlspecialchars($e['description']) ?></div><?php endif; ?>
            <?php if (!empty($e['date_fermeture'])): ?><div style="font-size:.78em;color:#a0aec0;margin-top:4px">Clôture le <?= htmlspecialchars($e['date_fermeture']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-sliders-h"></i> Pilotage (<?= count($pilotage) ?>)</h2>
    <?php if (empty($pilotage)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune enquête créée.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px">Titre</th><th style="padding:8px 10px">Type</th>
            <th style="padding:8px 10px">Réponses</th><th style="padding:8px 10px">Statut</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pilotage as $e): $stt = $e['statut']; ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($e['titre']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($e['type']) ?></td>
                <td style="padding:8px 10px"><?= (int) $e['nb_reponses'] ?></td>
                <td style="padding:8px 10px"><span style="padding:3px 12px;border-radius:12px;font-size:.8em;font-weight:600;background:<?= ($statutColors[$stt] ?? '#999') ?>22;color:<?= $statutColors[$stt] ?? '#999' ?>"><?= htmlspecialchars($statutLabels[$stt] ?? $stt) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
