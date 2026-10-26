<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$db = pf_db();
$byStatus = $db->query("SELECT status, COUNT(*) n FROM establishments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$tickets = $db->query("SELECT status, COUNT(*) n FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$park = Dist::get('/admin/park', 'park');
$lic = Dist::get('/admin/licenses', 'licenses');
$props = Dist::get('/admin/proposals?since_id=0&limit=10', 'proposals');
$instances = $park['data']['instances'] ?? [];
$alerts = array_filter($instances, fn($i) => pf_instance_tone($i)[1] !== 'ok');
$recentT = $db->query("SELECT t.id, t.subject, t.status, t.priority, t.updated_at, COALESCE(e.name, l.label, l.hostname) etab
    FROM tickets t JOIN instance_links l ON l.id = t.link_id LEFT JOIN establishments e ON e.id = t.establishment_id
    WHERE t.status IN ('nouveau','en_cours') ORDER BY FIELD(t.priority,'urgente','haute','normale','basse'), t.updated_at DESC LIMIT 6")->fetchAll();
$audit = $db->query("SELECT actor, action, target, created_at FROM audit_log ORDER BY id DESC LIMIT 6")->fetchAll();

pf_header('dashboard', 'Tableau de bord', 'Plateforme Fronote', pf_badges());
$stat = fn($label, $value, $sub = '', $tone = '') => '<div class="pf-stat' . ($tone ? ' pf-stat--' . $tone : '') . '"><div class="pf-stat__label">' . e($label) . '</div><div class="pf-stat__value">' . e($value) . '</div>' . ($sub !== '' ? '<div class="pf-stat__sub">' . e($sub) . '</div>' : '') . '</div>';
echo '<div class="pf-grid">';
echo $stat('Établissements autorisés', array_sum($byStatus), ($byStatus['installe'] ?? 0) . ' installé(s), ' . ($byStatus['autorise'] ?? 0) . ' en attente');
echo $stat('Instances dans le parc', $park['ok'] ? count($instances) : '—', $park['ok'] ? count($alerts) . ' à surveiller' : Dist::explain($park['code'], $park['data']));
echo $stat('Licences émises', $lic['ok'] ? count($lic['data']['licenses'] ?? []) : '—', $lic['ok'] ? count(array_filter($lic['data']['licenses'] ?? [], fn($l) => !empty($l['revoked']))) . ' révoquée(s)' : '');
echo $stat('Demandes ouvertes', ($tickets['nouveau'] ?? 0) + ($tickets['en_cours'] ?? 0), ($tickets['nouveau'] ?? 0) . ' nouvelle(s)');
echo '</div>';

echo '<div class="pf-grid pf-grid--2">';
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-life-ring"></i> Demandes à traiter</h2><div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="/support">Tout voir</a></div></div><div class="pf-card__body pf-card__body--flush">';
if (!$recentT) { echo '<div class="pf-empty">Aucune demande en attente.</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($recentT as $t) {
        [$sl, $st] = PF_TICKET_STATUS[$t['status']];
        echo '<tr><td><a href="/support/' . (int) $t['id'] . '">' . e($t['subject']) . '</a><div class="pf-muted">' . e($t['etab']) . '</div></td><td>' . pf_pill($t['priority'], in_array($t['priority'], ['haute', 'urgente'], true) ? 'crit' : 'muted') . '</td><td>' . pf_pill($sl, $st) . '</td><td class="pf-muted">' . e(pf_ago($t['updated_at'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-server"></i> Instances à surveiller</h2><div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="/distribution">Parc</a></div></div><div class="pf-card__body pf-card__body--flush">';
if (!$park['ok']) { echo '<div class="pf-notice pf-notice--warn">' . e(Dist::explain($park['code'], $park['data'])) . '</div>'; }
elseif (!$alerts) { echo '<div class="pf-empty">' . ($instances ? 'Toutes les instances vont bien.' : 'Aucune instance installée pour l\'instant.') . '</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($alerts as $i) {
        [$tl, $tt] = pf_instance_tone($i);
        echo '<tr><td>' . e($i['hostname'] ?? '?') . '<div class="pf-muted">' . e($i['app_url'] ?? '') . '</div></td><td>v' . e($i['app_version'] ?? '?') . '</td><td>' . pf_pill($tl, $tt) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-envelope-open-text"></i> Dernières propositions (vitrine)</h2></div><div class="pf-card__body pf-card__body--flush">';
$pl = array_slice(array_reverse($props['data']['proposals'] ?? []), 0, 5);
if (!$pl) { echo '<div class="pf-empty">' . ($props['ok'] ? 'Aucune proposition.' : e(Dist::explain($props['code'], $props['data']))) . '</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($pl as $p) {
        echo '<tr><td>' . e($p['etablissement'] ?? '—') . '<div class="pf-muted">' . e(mb_strimwidth((string) ($p['message'] ?? ''), 0, 90, '…')) . '</div></td><td class="pf-muted">' . e($p['at'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-list-check"></i> Dernières actions</h2><div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="/journal">Journal</a></div></div><div class="pf-card__body pf-card__body--flush">';
if (!$audit) { echo '<div class="pf-empty">Rien pour l\'instant.</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($audit as $a) { echo '<tr><td><span class="pf-mono">' . e($a['action']) . '</span> ' . e($a['target'] ?? '') . '</td><td class="pf-muted">' . e($a['actor']) . '</td><td class="pf-muted">' . e(pf_ago($a['created_at'])) . '</td></tr>'; }
    echo '</tbody></table>';
}
echo '</div></section></div>';
pf_footer();
