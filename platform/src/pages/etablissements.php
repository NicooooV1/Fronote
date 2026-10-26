<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$q = trim((string) ($_GET['q'] ?? ''));
$st = (string) ($_GET['statut'] ?? '');
$sql = "SELECT e.*, (SELECT COUNT(*) FROM tickets t WHERE t.establishment_id = e.id AND t.status IN ('nouveau','en_cours')) open_tickets FROM establishments e WHERE 1";
$args = [];
if ($q !== '') { $sql .= " AND (e.name LIKE ? OR e.uai LIKE ? OR e.city LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (isset(PF_STATUS[$st])) { $sql .= " AND e.status = ?"; $args[] = $st; }
$sql .= " ORDER BY FIELD(e.status,'autorise','installe','suspendu','archive'), e.name";
$stmt = pf_db()->prepare($sql); $stmt->execute($args); $rows = $stmt->fetchAll();
$park = pf_park_by_license();

pf_header('etablissements', 'Établissements', 'Parc', pf_badges());
echo '<div class="pf-section__head pf-row"><form method="get" class="pf-row pf-filters"><input class="pf-input" type="search" name="q" value="' . e($q) . '" placeholder="Nom, UAI, ville…">'
   . '<select class="pf-input" name="statut"><option value="">Tous les statuts</option>';
foreach (PF_STATUS as $k => [$l]) { echo '<option value="' . e($k) . '"' . ($st === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
echo '</select><button class="pf-btn pf-btn--ghost">Filtrer</button></form><span class="pf-topbar__spacer"></span><a class="pf-btn pf-btn--primary" href="/etablissements/nouveau"><i class="fas fa-plus"></i> Autoriser un établissement</a></div>';
echo '<section class="pf-card"><div class="pf-card__body pf-card__body--flush">';
if (!$rows) {
    echo '<div class="pf-empty">' . ($q || $st ? 'Aucun établissement ne correspond.' : 'Aucun établissement pour l\'instant. Autorisez le premier : la plateforme émet sa licence et vous donne la clé à lui transmettre.') . '</div>';
} else {
    echo '<table class="pf-table"><thead><tr><th>Établissement</th><th>Statut</th><th>Licence</th><th>Instance</th><th>Demandes</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        [$sl, $stt] = PF_STATUS[$r['status']];
        $inst = $park[(int) $r['license_id']][0] ?? null;
        $instCell = $inst ? pf_pill(pf_instance_tone($inst)[0], pf_instance_tone($inst)[1]) . ' <span class="pf-muted">v' . e($inst['app_version'] ?? '?') . '</span>' : '<span class="pf-muted">—</span>';
        echo '<tr><td><a href="/etablissements/' . (int) $r['id'] . '"><strong>' . e($r['name']) . '</strong></a><div class="pf-muted">' . e(trim(($r['uai'] ? $r['uai'] . ' · ' : '') . ($r['city'] ?? ''))) . '</div></td>'
           . '<td>' . pf_pill($sl, $stt) . '</td><td><span class="pf-mono">' . e($r['license_prefix'] ?? '—') . '…</span></td><td>' . $instCell . '</td>'
           . '<td>' . ((int) $r['open_tickets'] ? pf_pill((int) $r['open_tickets'] . ' ouverte(s)', 'warn') : '<span class="pf-muted">—</span>') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';
pf_footer();
