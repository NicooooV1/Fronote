<?php
declare(strict_types=1);
/**
 * Module Conseils de classe.
 * Sélection d'une classe → liste des conseils planifiés/tenus + statut.
 */
$pageTitle  = 'Conseils de classe';
$activePage = 'conseil_classe';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.conseil_classe.access'); // (administrateur, professeur, vie_scolaire par défaut — éditable plateforme)

require_once __DIR__ . '/includes/ConseilClasseService.php';
$svc    = new \ConseilClasse\ConseilClasseService($pdo);
$etabId = \API\Core\EstablishmentContext::id();

$m = (int) date('n');
$y = (int) date('Y');
$annee = $m >= 9 ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);

$classes = [];
try {
    $st = $pdo->prepare("SELECT DISTINCT classe FROM eleves WHERE etablissement_id = :e AND actif = 1 AND classe <> '' ORDER BY classe");
    $st->execute([':e' => $etabId]);
    $classes = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    try {
        $classes = $pdo->query("SELECT DISTINCT classe FROM eleves WHERE etablissement_id = " . (int)\API\Core\EstablishmentContext::id() . " AND actif = 1 AND classe <> '' ORDER BY classe")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e2) { $classes = []; }
}

$classe = $_GET['classe'] ?? ($classes[0] ?? '');
$sessions = $classe ? $svc->getSessions($classe, $annee) : [];

$statutLabels = ['planifie' => __('conseil_classe.statut_planifie'), 'en_cours' => __('status.en_cours'), 'termine' => __('status.termine')];
$statutColors = ['planifie' => '#0f4c81', 'en_cours' => '#f59e0b', 'termine' => '#16a34a'];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="width:100%;max-width:1000px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 4px"><i class="fas fa-users-rectangle"></i> <?= __('conseil_classe.page_title') ?></h1>
    <p style="color:var(--text-muted,#64748b);margin:0 0 20px"><?= __('conseil_classe.annee') ?> <?= htmlspecialchars($annee) ?></p>

    <?php if (empty($classes)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('conseil_classe.aucune_classe') ?></p>
    <?php else: ?>
    <form method="get" style="margin-bottom:24px">
        <label style="font-size:.9em;color:var(--text-muted,#64748b);margin-right:8px"><?= __('conseil_classe.filtre_classe') ?></label>
        <select name="classe" data-fr-change="submitOwn" style="padding:8px 12px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $c === $classe ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <h2 style="font-size:1.1em;margin:0 0 12px"><?= __('conseil_classe.conseils') ?> — <?= htmlspecialchars($classe) ?> (<?= count($sessions) ?>)</h2>
    <?php if (empty($sessions)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('conseil_classe.aucun_conseil') ?></p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($sessions as $s): $stt = $s['statut'] ?? 'planifie'; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px">
            <div>
                <strong><?= htmlspecialchars($s['date_conseil'] ?? __('conseil_classe.date_a_definir')) ?></strong>
                <?php if (!empty($s['lieu'])): ?><span style="color:#a0aec0"> · <?= htmlspecialchars($s['lieu']) ?></span><?php endif; ?>
            </div>
            <span style="padding:3px 12px;border-radius:12px;font-size:.8em;font-weight:600;background:<?= ($statutColors[$stt] ?? '#999') ?>22;color:<?= $statutColors[$stt] ?? '#999' ?>">
                <?= htmlspecialchars($statutLabels[$stt] ?? $stt) ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
