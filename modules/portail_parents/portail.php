<?php
declare(strict_types=1);
/**
 * Module Portail Parents.
 * Vue consolidée par enfant : moyenne, notes/absences récentes, EDT du jour,
 * documents à signer, autorisations de sortie, historique paiements.
 */
$activePage = 'portail_parents';
require_once __DIR__ . '/../../API/module_boot.php';
$pageTitle  = __('portail_parents.page_title');
requireCapability('module.portail_parents.access'); // (parent par défaut — éditable plateforme)

require_once __DIR__ . '/includes/PortailParentsService.php';
$svc      = new \PortailParents\PortailParentsService($pdo);
$etabId   = \API\Core\EstablishmentContext::id();
$parentId = getUserId();

$enfants = $svc->getEnfants($parentId);
$enfantId = (int) ($_GET['enfant'] ?? ($enfants[0]['id'] ?? 0));
$resume   = $enfantId ? $svc->getResumeEnfant($parentId, $enfantId) : [];

$documents     = $svc->getDocumentsASigner($parentId, $etabId);
$autorisations = $svc->getAutorisations($parentId, $enfantId ?: null);
$paiements     = $svc->getHistoriquePaiements($parentId);

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="width:100%;max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 16px"><i class="fas fa-user-shield"></i> <?= __('portail_parents.page_title') ?></h1>

    <?php if (empty($enfants)): ?>
    <p style="color:var(--text-muted,#64748b);background:var(--bg-secondary);padding:16px;border-radius:8px"><?= __('portail_parents.no_child') ?></p>
    <?php else: ?>

    <?php if (count($enfants) > 1): ?>
    <form method="get" style="margin-bottom:20px">
        <label style="font-size:.9em;color:var(--text-muted,#64748b);margin-right:8px"><?= __('portail_parents.child') ?></label>
        <select name="enfant" data-fr-change="submitOwn" style="padding:8px 12px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            <?php foreach ($enfants as $en): ?>
            <option value="<?= (int) $en['id'] ?>" <?= (int) $en['id'] === $enfantId ? 'selected' : '' ?>><?= htmlspecialchars(($en['prenom'] ?? '') . ' ' . ($en['nom'] ?? '')) ?> (<?= htmlspecialchars($en['classe'] ?? '') ?>)</option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (!empty($resume['eleve'])): $el = $resume['eleve']; ?>
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:18px;margin-bottom:24px">
        <div>
            <div style="font-size:1.2em;font-weight:700"><?= htmlspecialchars(($el['prenom'] ?? '') . ' ' . ($el['nom'] ?? '')) ?></div>
            <div style="color:var(--text-muted,#64748b)"><?= htmlspecialchars($el['classe'] ?? '') ?></div>
        </div>
        <div style="margin-left:auto;text-align:center">
            <div style="font-size:2em;font-weight:700;color:var(--primary,#0f4c81)"><?= $resume['moyenne_generale'] !== null ? htmlspecialchars((string) $resume['moyenne_generale']) : '—' ?></div>
            <div style="font-size:.8em;color:var(--text-muted,#64748b)"><?= __('portail_parents.moyenne_generale') ?></div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:24px">
        <div>
            <h2 style="font-size:1.05em;margin:0 0 10px"><i class="fas fa-pen"></i> <?= __('portail_parents.dernieres_notes') ?></h2>
            <?php if (empty($resume['notes_recentes'])): ?>
            <p style="color:var(--text-muted,#64748b);font-size:.9em"><?= __('portail_parents.aucune_note') ?></p>
            <?php else: foreach (array_slice($resume['notes_recentes'], 0, 6) as $n): ?>
            <div style="display:flex;justify-content:space-between;font-size:.9em;padding:6px 0;border-bottom:1px solid #f1f5f9">
                <span><?= htmlspecialchars($n['matiere'] ?? '') ?></span>
                <strong><?= htmlspecialchars((string) $n['note']) ?>/<?= htmlspecialchars((string) ($n['note_sur'] ?? 20)) ?></strong>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div>
            <h2 style="font-size:1.05em;margin:0 0 10px"><i class="fas fa-calendar-times"></i> <?= __('portail_parents.absences_recentes') ?></h2>
            <?php if (empty($resume['absences_recentes'])): ?>
            <p style="color:var(--text-muted,#64748b);font-size:.9em"><?= __('portail_parents.aucune_absence') ?></p>
            <?php else: foreach (array_slice($resume['absences_recentes'], 0, 6) as $a): ?>
            <div style="display:flex;justify-content:space-between;font-size:.9em;padding:6px 0;border-bottom:1px solid #f1f5f9">
                <span><?= htmlspecialchars($a['date_debut'] ?? '') ?></span>
                <span style="color:<?= !empty($a['justifiee']) ? '#16a34a' : '#dc2626' ?>"><?= !empty($a['justifiee']) ? __('portail_parents.justifiee') : __('portail_parents.non_justifiee') ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($documents)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-file-signature"></i> <?= __('portail_parents.documents_a_signer') ?> (<?= count($documents) ?>)</h2>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px">
        <?php foreach ($documents as $d): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:12px 14px">
            <div>
                <strong><?= htmlspecialchars($d['titre'] ?? '') ?></strong>
                <?php if (!empty($d['obligatoire'])): ?><span style="background:#fef2f2;color:#991b1b;border-radius:10px;padding:2px 8px;font-size:.72em;margin-left:6px"><?= __('portail_parents.obligatoire') ?></span><?php endif; ?>
                <div style="font-size:.8em;color:var(--text-muted,#64748b)"><?= __('portail_parents.limite') ?> <?= htmlspecialchars($d['date_limite'] ?? '') ?></div>
            </div>
            <span style="font-size:.85em;font-weight:600;color:<?= !empty($d['deja_signe']) ? '#16a34a' : '#b45309' ?>">
                <?= !empty($d['deja_signe']) ? __('portail_parents.signe') : __('portail_parents.a_signer') ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($autorisations)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-door-open"></i> <?= __('portail_parents.autorisations_sortie') ?></h2>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px">
        <?php foreach ($autorisations as $a): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:12px 14px;font-size:.9em">
            <div>
                <strong><?= htmlspecialchars($a['eleve_nom'] ?? '') ?></strong> — <?= htmlspecialchars($a['motif'] ?? '') ?>
                <div style="font-size:.8em;color:var(--text-muted,#64748b)"><?= htmlspecialchars($a['date_debut'] ?? '') ?> → <?= htmlspecialchars($a['date_fin'] ?? '') ?></div>
            </div>
            <span style="text-transform:capitalize;color:var(--text-muted,#64748b)"><?= htmlspecialchars($a['statut'] ?? '') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($paiements)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-receipt"></i> <?= __('portail_parents.historique_paiements') ?></h2>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
        <thead><tr style="text-align:left;color:#718096;border-bottom:1px solid #e2e8f0">
            <th style="padding:8px 10px"><?= __('portail_parents.reference') ?></th><th style="padding:8px 10px"><?= __('label.montant') ?></th>
            <th style="padding:8px 10px"><?= __('portail_parents.echeance') ?></th><th style="padding:8px 10px"><?= __('label.statut') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($paiements, 0, 12) as $f): ?>
            <tr style="border-bottom:1px solid #f7fafc">
                <td style="padding:8px 10px"><?= htmlspecialchars($f['reference'] ?? ('#' . $f['id'])) ?></td>
                <td style="padding:8px 10px"><?= number_format((float) ($f['montant'] ?? 0), 2, ',', ' ') ?> €</td>
                <td style="padding:8px 10px"><?= htmlspecialchars($f['date_echeance'] ?? '') ?></td>
                <td style="padding:8px 10px;text-transform:capitalize"><?= htmlspecialchars($f['statut'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
