<?php
declare(strict_types=1);
/**
 * Module Évaluations en ligne (QCM).
 * Prof : ses banques + ses évaluations (soumis/corrigés). Élève : évals de sa classe.
 */
$activePage = 'evaluations';
require_once __DIR__ . '/../../API/module_boot.php';
requireAuth();
$pageTitle  = __('evaluations.title');

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
    } catch (\Throwable $e) { error_log('[evaluations.php] ' . $e->getMessage()); }
    $evals = $classe ? $svc->getEvaluationsClasse($classe) : [];
}

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="mk-page">
    <h1 style="font-size:1.5em;margin:0 0 20px"><i class="fas fa-laptop-code"></i> <?= __('evaluations.title') ?></h1>

    <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
    <h2 class="section-title"><i class="fas fa-database"></i> <?= __('evaluations.my_question_banks') ?> (<?= count($banques) ?>)</h2>
    <?php if (empty($banques)): ?>
    <p class="empty-state mk-empty-sm"><?= __('evaluations.no_question_bank') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:28px">
        <?php foreach ($banques as $b): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:12px 16px;min-width:180px">
            <strong><?= htmlspecialchars($b['titre']) ?></strong>
            <div style="font-size:.8em;color:var(--text-muted,#64748b)"><?= (int) ($b['nb_questions'] ?? 0) ?> <?= __('evaluations.questions_count') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <h2 class="section-title"><i class="fas fa-list-check"></i> <?= __('evaluations.heading') ?> (<?= count($evals) ?>)</h2>
    <?php if (empty($evals)): ?>
    <p class="empty-state mk-empty-sm"><?= __('evaluations.none') ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead><tr>
            <th><?= __('label.titre') ?></th><th><?= __('label.classe') ?></th>
            <th><?= __('evaluations.opening') ?></th>
            <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
            <th><?= __('evaluations.submitted') ?></th><th><?= __('evaluations.corrected') ?></th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($evals as $ev): ?>
            <tr>
                <td><?= htmlspecialchars($ev['titre']) ?></td>
                <td><?= htmlspecialchars($ev['classe'] ?? '') ?></td>
                <td><?= htmlspecialchars($ev['date_ouverture'] ?? '') ?></td>
                <?php if (in_array($role, ['professeur', 'administrateur'], true)): ?>
                <td><?= (int) ($ev['nb_soumis'] ?? 0) ?></td>
                <td><?= (int) ($ev['nb_corriges'] ?? 0) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
