<?php
/**
 * Module Évaluations en ligne (QCM).
 * Prof : ses banques + ses évaluations (soumis/corrigés). Élève : évals de sa classe.
 */
$pageTitle  = 'Évaluations en ligne';
$activePage = 'evaluations';
require_once __DIR__ . '/../../API/module_boot.php';
requireAuth();

require_once __DIR__ . '/includes/EvaluationService.php';
$svc  = new \Evaluations\EvaluationService($pdo);
$role = getUserRole();
$uid  = getUserId();

$banques = [];
$evals   = [];
if (in_array($role, ['professeur', 'administrateur'], true)) {
    $banques = $svc->getBanques($uid);
    $evals   = $svc->getEvaluationsProf($uid);
} elseif ($role === 'eleve') {
    $classe = '';
    try {
        $st = $pdo->prepare("SELECT classe FROM eleves WHERE id = :id LIMIT 1");
        $st->execute([':id' => $uid]);
        $classe = (string) $st->fetchColumn();
    } catch (\Throwable $e) {}
    $evals = $classe ? $svc->getEvaluationsClasse($classe) : [];
}

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1000px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-laptop-code"></i> Évaluations en ligne</h1>

    <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-database"></i> Mes banques de questions (<?= count($banques) ?>)</h2>
    <?php if (empty($banques)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune banque de questions.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:28px">
        <?php foreach ($banques as $b): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:12px 16px;min-width:180px">
            <strong><?= htmlspecialchars($b['titre']) ?></strong>
            <div style="font-size:.8em;color:var(--text-muted,#64748b)"><?= (int) ($b['nb_questions'] ?? 0) ?> question(s)</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-list-check"></i> Évaluations (<?= count($evals) ?>)</h2>
    <?php if (empty($evals)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucune évaluation.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px">Titre</th><th style="padding:8px 10px">Classe</th>
            <th style="padding:8px 10px">Ouverture</th>
            <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
            <th style="padding:8px 10px">Soumis</th><th style="padding:8px 10px">Corrigés</th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($evals as $ev): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($ev['titre']) ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($ev['classe'] ?? '') ?></td>
                <td style="padding:8px 10px"><?= htmlspecialchars($ev['date_ouverture'] ?? '') ?></td>
                <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
                <td style="padding:8px 10px"><?= (int) ($ev['nb_soumis'] ?? 0) ?></td>
                <td style="padding:8px 10px"><?= (int) ($ev['nb_corriges'] ?? 0) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
