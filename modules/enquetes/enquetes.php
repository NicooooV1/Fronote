<?php
declare(strict_types=1);
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

<div class="mk-page">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-poll-h"></i> <?= __('enquetes.titre_page') ?></h1>

    <h2 class="section-title"><i class="fas fa-inbox"></i> <?= __('enquetes.a_repondre') ?> (<?= count($ouvertes) ?>)</h2>
    <?php if (empty($ouvertes)): ?>
    <p class="empty-state mk-empty-sm"><?= __('enquetes.aucune_ouverte') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:28px">
        <?php foreach ($ouvertes as $e): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px">
            <strong><?= htmlspecialchars($e['titre']) ?></strong>
            <?php if (!empty($e['anonyme'])): ?><span style="background:#eef2ff;color:#3730a3;border-radius:12px;padding:2px 10px;font-size:.75em;margin-left:8px"><?= __('enquetes.anonyme') ?></span><?php endif; ?>
            <?php if (!empty($e['description'])): ?><div style="font-size:.85em;color:var(--text-muted,#64748b);margin-top:4px"><?= htmlspecialchars($e['description']) ?></div><?php endif; ?>
            <?php if (!empty($e['date_fermeture'])): ?><div style="font-size:.78em;color:#a0aec0;margin-top:4px"><?= __('enquetes.cloture_le') ?> <?= htmlspecialchars($e['date_fermeture']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <h2 class="section-title"><i class="fas fa-sliders-h"></i> <?= __('enquetes.pilotage') ?> (<?= count($pilotage) ?>)</h2>
    <?php if (empty($pilotage)): ?>
    <p class="empty-state mk-empty-sm"><?= __('enquetes.aucune_creee') ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th><?= __('label.titre') ?></th><th><?= __('label.type') ?></th>
            <th><?= __('enquetes.reponses') ?></th><th><?= __('label.statut') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($pilotage as $e): $stt = $e['statut']; ?>
            <tr>
                <td><?= htmlspecialchars($e['titre']) ?></td>
                <td><?= htmlspecialchars($e['type']) ?></td>
                <td><?= (int) $e['nb_reponses'] ?></td>
                <td><span style="padding:3px 12px;border-radius:12px;font-size:.8em;font-weight:600;background:<?= ($statutColors[$stt] ?? '#999') ?>22;color:<?= $statutColors[$stt] ?? '#999' ?>"><?= htmlspecialchars($statutLabels[$stt] ?? $stt) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
