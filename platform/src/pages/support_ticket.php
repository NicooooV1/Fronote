<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$db = pf_db();
$get = function () use ($db, $ID) {
    $s = $db->prepare("SELECT t.*, COALESCE(e.name, l.label, l.hostname, 'Instance inconnue') etab, l.app_url, l.app_version, l.hostname
        FROM tickets t JOIN instance_links l ON l.id = t.link_id LEFT JOIN establishments e ON e.id = t.establishment_id WHERE t.id = ?");
    $s->execute([$ID]);
    return $s->fetch() ?: null;
};
$t = $get();
if (!$t) { http_response_code(404); pf_header('support', 'Demande introuvable'); echo '<div class="pf-empty">Demande introuvable.</div>'; pf_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_check_csrf();
    $body = trim((string) ($_POST['body'] ?? ''));
    $internal = !empty($_POST['internal']);
    $newStatus = (string) ($_POST['status'] ?? '');
    if ($body !== '') {
        $db->prepare("INSERT INTO ticket_messages (ticket_id, side, author, body, internal) VALUES (?, 'plateforme', ?, ?, ?)")
           ->execute([$ID, $op['name'], mb_substr($body, 0, 20000), $internal ? 1 : 0]);
        if (!$internal && $newStatus === '') { $newStatus = 'attente_etablissement'; }
    }
    if (isset(PF_TICKET_STATUS[$newStatus]) && $newStatus !== $t['status']) {
        $db->prepare('UPDATE tickets SET status = ?, assigned_to = COALESCE(assigned_to, ?) WHERE id = ?')->execute([$newStatus, $op['user'], $ID]);
    } else {
        $db->prepare('UPDATE tickets SET updated_at = NOW(), assigned_to = COALESCE(assigned_to, ?) WHERE id = ?')->execute([$op['user'], $ID]);
    }
    pf_audit($op['user'], 'support.repondre', '#' . $ID, ['statut' => $newStatus ?: $t['status'], 'interne' => $internal]);
    pf_flash($internal ? 'Note interne ajoutée.' : ($body !== '' ? 'Réponse envoyée : l\'établissement la recevra à sa prochaine synchronisation (quelques minutes).' : 'Statut mis à jour.'));
    pf_redirect('/support/' . $ID);
}
if ($t['status'] === 'nouveau') {
    $db->prepare("UPDATE tickets SET status = 'en_cours', assigned_to = ? WHERE id = ?")->execute([$op['user'], $ID]);
    $t = $get();
}
$msgs = $db->prepare('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id');
$msgs->execute([$ID]);
$ctx = json_decode((string) $t['context_json'], true) ?: [];
[$sl, $stt] = PF_TICKET_STATUS[$t['status']];

pf_header('support', $t['subject'], 'Demande #' . $ID . ' — ' . $t['etab'], pf_badges());
echo '<div class="pf-row pf-head-meta">' . pf_pill($sl, $stt) . ' ' . pf_pill('priorité ' . $t['priority'], in_array($t['priority'], ['haute', 'urgente'], true) ? 'crit' : 'muted') . ' <span class="pf-muted">' . e($t['category']) . ' · ouverte ' . e(pf_ago($t['created_at'])) . ($t['establishment_id'] ? ' · <a href="/etablissements/' . (int) $t['establishment_id'] . '">fiche établissement</a>' : '') . '</span><span class="pf-topbar__spacer"></span><a class="pf-btn pf-btn--ghost pf-btn--sm" href="/support"><i class="fas fa-arrow-left"></i> Demandes</a></div>';
echo '<div class="pf-grid pf-grid--wide"><div class="pf-thread">';
foreach ($msgs as $m) {
    $mine = $m['side'] === 'plateforme';
    echo '<article class="pf-msg' . ($mine ? ' pf-msg--out' : '') . ($m['internal'] ? ' pf-msg--internal' : '') . '"><header><strong>' . e($m['author']) . '</strong> <span class="pf-muted">' . ($mine ? ($m['internal'] ? 'note interne' : 'plateforme') : e($t['etab'])) . ' · ' . e(date('d/m/Y H:i', strtotime($m['created_at']))) . '</span></header><div class="pf-msg__body">' . nl2br(e($m['body'])) . '</div></article>';
}
echo '<form method="post" class="pf-card pf-reply">' . pf_csrf_field() . '<div class="pf-card__body"><label class="pf-field"><span class="pf-field__label">Répondre à l\'équipe de l\'établissement</span><textarea class="pf-input" name="body" rows="5" placeholder="Votre réponse…"></textarea></label>'
   . '<div class="pf-row"><label class="pf-check"><input type="checkbox" name="internal" value="1"> Note interne (invisible pour l\'établissement)</label><span class="pf-topbar__spacer"></span>'
   . '<select class="pf-input pf-input--sm" name="status"><option value="">Statut : inchangé (ou « en attente » après réponse)</option>';
foreach (PF_TICKET_STATUS as $k => [$l]) { echo '<option value="' . $k . '">' . e($l) . '</option>'; }
echo '</select><button class="pf-btn pf-btn--primary"><i class="fas fa-paper-plane"></i> Envoyer</button></div></div></form></div>';
echo '<aside class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-circle-info"></i> Contexte</h2></div><div class="pf-card__body"><dl class="pf-dl">'
   . '<dt>Établissement</dt><dd>' . e($t['etab']) . '</dd>'
   . '<dt>Transmise par</dt><dd>' . e($t['sent_by'] ?? '—') . '</dd>'
   . '<dt>Instance</dt><dd>' . e($t['hostname'] ?? '—') . '</dd>'
   . '<dt>Version</dt><dd>' . e($ctx['app_version'] ?? $t['app_version'] ?? '—') . '</dd>'
   . '<dt>Adresse</dt><dd>' . ($t['app_url'] ? '<a href="' . e($t['app_url']) . '" target="_blank" rel="noopener">' . e($t['app_url']) . '</a>' : '—') . '</dd>'
   . (isset($ctx['page']) ? '<dt>Page concernée</dt><dd class="pf-mono">' . e($ctx['page']) . '</dd>' : '')
   . '<dt>Référence instance</dt><dd class="pf-mono">' . e($t['instance_ref']) . '</dd>'
   . '<dt>Pris en charge par</dt><dd>' . e($t['assigned_to'] ?? '—') . '</dd></dl></div></aside></div>';
pf_footer();
