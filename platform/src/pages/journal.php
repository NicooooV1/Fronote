<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$page = max(1, (int) ($_GET['page'] ?? 1));
$rows = pf_db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100))->fetchAll();
pf_header('journal', 'Journal', 'Toutes les actions des opérateurs et des instances', pf_badges());
echo '<section class="pf-card"><div class="pf-card__body pf-card__body--flush">';
if (!$rows) { echo '<div class="pf-empty">Aucune action enregistrée.</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><thead><tr><th>Date</th><th>Auteur</th><th>Action</th><th>Cible</th><th>Détail</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $r) { echo '<tr><td class="pf-muted">' . e($r['created_at']) . '</td><td>' . e($r['actor']) . '</td><td class="pf-mono">' . e($r['action']) . '</td><td>' . e($r['target'] ?? '') . '</td><td class="pf-muted pf-mono">' . e(mb_strimwidth((string) ($r['detail'] ?? ''), 0, 120, '…')) . '</td><td class="pf-mono pf-muted">' . e($r['ip'] ?? '') . '</td></tr>'; }
    echo '</tbody></table>';
}
echo '</div></section><div class="pf-row">' . ($page > 1 ? '<a class="pf-btn pf-btn--ghost pf-btn--sm" href="?page=' . ($page - 1) . '">Plus récent</a>' : '') . (count($rows) === 100 ? '<a class="pf-btn pf-btn--ghost pf-btn--sm" href="?page=' . ($page + 1) . '">Plus ancien</a>' : '') . '</div>';
pf_footer();
