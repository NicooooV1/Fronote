<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$st = (string) ($_GET['statut'] ?? 'ouvertes');
$where = match ($st) {
    'ouvertes' => "t.status IN ('nouveau','en_cours')",
    'attente' => "t.status = 'attente_etablissement'",
    'closes' => "t.status IN ('resolu','ferme')",
    default => '1',
};
$rows = pf_db()->query("SELECT t.*, COALESCE(e.name, l.label, l.hostname, 'Instance inconnue') etab,
        (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.internal = 0) nb,
        (SELECT m.side FROM ticket_messages m WHERE m.ticket_id = t.id AND m.internal = 0 ORDER BY m.id DESC LIMIT 1) last_side
    FROM tickets t JOIN instance_links l ON l.id = t.link_id LEFT JOIN establishments e ON e.id = t.establishment_id
    WHERE {$where} ORDER BY FIELD(t.priority,'urgente','haute','normale','basse'), t.updated_at DESC LIMIT 200")->fetchAll();
pf_header('support', 'Demandes escaladées', 'Transmises par les équipes des établissements', pf_badges());
echo '<div class="pf-toggle-set pf-row">';
foreach (['ouvertes' => 'À traiter', 'attente' => 'En attente de l\'établissement', 'closes' => 'Résolues', 'toutes' => 'Toutes'] as $k => $l) {
    echo '<a class="pf-toggle' . ($st === $k ? ' is-active' : '') . '" href="?statut=' . $k . '">' . e($l) . '</a>';
}
echo '</div><section class="pf-card"><div class="pf-card__body pf-card__body--flush">';
if (!$rows) { echo '<div class="pf-empty">Aucune demande ici.</div>'; }
else {
    echo '<table class="pf-table"><thead><tr><th>Demande</th><th>Établissement</th><th>Priorité</th><th>Statut</th><th>Mise à jour</th></tr></thead><tbody>';
    foreach ($rows as $t) {
        [$sl, $stt] = PF_TICKET_STATUS[$t['status']];
        $unread = $t['last_side'] === 'etablissement' && in_array($t['status'], ['nouveau', 'en_cours'], true);
        echo '<tr' . ($unread ? ' class="is-unread"' : '') . '><td><a href="/support/' . (int) $t['id'] . '"><strong>' . e($t['subject']) . '</strong></a><div class="pf-muted">' . e($t['category']) . ' · ' . (int) $t['nb'] . ' message(s)' . ($unread ? ' · réponse attendue' : '') . '</div></td>'
           . '<td>' . e($t['etab']) . '<div class="pf-muted">' . e($t['sent_by'] ?? '') . '</div></td><td>' . pf_pill($t['priority'], in_array($t['priority'], ['haute', 'urgente'], true) ? 'crit' : 'muted') . '</td><td>' . pf_pill($sl, $stt) . '</td><td class="pf-muted">' . e(pf_ago($t['updated_at'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';
pf_footer();
