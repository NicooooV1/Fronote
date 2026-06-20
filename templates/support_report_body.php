<?php
/**
 * Corps du rapport de fin d'intervention Support (refonte 3-mondes).
 * Inclus par platform/support/report.php (Support) et tenant/support_report.php (Direction).
 * Attend : $report (sortie de API\Support\SupportReportService::build()).
 * Styling neutre (bordures rgba, couleur héritée) → lisible en thème clair ET sombre.
 */
$h = fn($s) => htmlspecialchars((string) $s);
$s = $report['session'];
$t = $report['ticket'];
?>
<dl class="sr-meta">
    <div><dt>Établissement</dt><dd>#<?= (int) $s['establishment_id'] ?></dd></div>
    <div><dt>Ticket</dt><dd><?= $t ? '#' . (int) $t['id'] . ' — ' . $h($t['title']) : '—' ?></dd></div>
    <div><dt>Niveau d'accès</dt><dd><?= $h($s['access_level']) ?></dd></div>
    <div><dt>Statut final</dt><dd><?= $h($s['status']) ?></dd></div>
    <div><dt>Début</dt><dd><?= $h($s['started_at'] ?? '—') ?></dd></div>
    <div><dt>Fin</dt><dd><?= $h($s['ended_at'] ?? '—') ?></dd></div>
    <div><dt>Durée</dt><dd><?= $report['duration_minutes'] !== null ? (int) $report['duration_minutes'] . ' min' : '—' ?></dd></div>
    <div><dt>Terminée par</dt><dd><?= $h($s['ended_by_type'] ?? '—') ?><?= !empty($s['end_reason']) ? ' (' . $h($s['end_reason']) . ')' : '' ?></dd></div>
    <div><dt>Actions sensibles</dt><dd><?= (int) $report['sensitive_actions'] ?></dd></div>
</dl>

<?php if (!empty($report['summary'])): ?>
    <h3>Synthèse de l'intervention</h3>
    <p class="sr-summary"><?= nl2br($h($report['summary'])) ?></p>
<?php endif; ?>

<?php if (!empty($report['action_counts'])): ?>
    <h3>Actions par type</h3>
    <p><?php foreach ($report['action_counts'] as $a => $c): ?><span class="sr-badge"><?= $h($a) ?> : <?= (int) $c ?></span> <?php endforeach; ?></p>
<?php endif; ?>

<?php if (!empty($report['restrictions'])): ?>
    <h3>Restrictions appliquées</h3>
    <ul><?php foreach ($report['restrictions'] as $r): ?><li><?= $h($r['restriction_key']) ?> = <?= $h($r['restriction_value']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<h3>Journal d'intervention (<?= count($report['trail']) ?>)</h3>
<table class="sr-table">
    <thead><tr><th>Date</th><th>Action</th><th>Cible</th><th>Niveau</th><th>Sensible</th></tr></thead>
    <tbody>
    <?php if (!$report['trail']): ?><tr><td colspan="5"><em>Aucune action journalisée.</em></td></tr><?php endif; ?>
    <?php foreach ($report['trail'] as $a): ?>
        <tr>
            <td><?= $h($a['created_at']) ?></td>
            <td><?= $h($a['action']) ?></td>
            <td><?= $h($a['target_type'] ?? '') ?> <?= !empty($a['target_id']) ? '#' . (int) $a['target_id'] : '' ?></td>
            <td><?= $h($a['access_level'] ?? '') ?></td>
            <td><?= !empty($a['sensitive']) ? 'oui' : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<style>
.sr-meta { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 10px; margin: 12px 0; }
.sr-meta dt { font-size: .72rem; opacity: .65; text-transform: uppercase; }
.sr-meta dd { margin: 0; font-weight: 600; }
.sr-summary { padding: 12px; border: 1px solid rgba(127,127,127,.3); border-radius: 8px; }
.sr-badge { display: inline-block; border: 1px solid rgba(127,127,127,.4); border-radius: 6px; padding: 2px 8px; font-size: .75rem; margin: 0 4px 4px 0; }
.sr-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.sr-table th, .sr-table td { text-align: left; padding: 6px; border-bottom: 1px solid rgba(127,127,127,.3); font-size: .82rem; }
</style>
