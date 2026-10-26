<?php
declare(strict_types=1);
/**
 * API des INSTANCES (public via Caddy, sans SSO) — escalade du support.
 *
 * Seule exception au principe « l'instance n'initie jamais vers la plateforme » : l'équipe d'un
 * établissement transmet une demande. L'instance garde une file d'attente et réessaie tant que
 * la plateforme ne répond pas ; elle relève les réponses par /updates.
 *
 *   GET  /api/instance/ping                       → vérifie la liaison
 *   POST /api/instance/tickets                    {ref, subject, category, priority, body, sent_by, context}
 *   POST /api/instance/tickets/{ref}/messages     {body, author, close?}
 *   GET  /api/instance/updates?since=<id>         → réponses de la plateforme + statuts
 *
 * Authentification : X-Fronote-Install (jeton d'installation, vérifié auprès de dist) ou
 * X-Fronote-Key (clé d'API émise par la plateforme pour une instance hors distribution).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_out(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_link(): array
{
    $db = pf_db();
    $key = (string) ($_SERVER['HTTP_X_FRONOTE_KEY'] ?? '');
    $tok = (string) ($_SERVER['HTTP_X_FRONOTE_INSTALL'] ?? '');
    if ($key !== '') {
        $s = $db->prepare('SELECT * FROM instance_links WHERE api_key_hash = ? AND revoked = 0');
        $s->execute([hash('sha256', $key)]);
        $link = $s->fetch();
        if (!$link) { api_out(401, ['ok' => false, 'error' => 'unknown_key']); }
        return $link;
    }
    if (strlen($tok) < 16 || strlen($tok) > 256) { api_out(401, ['ok' => false, 'error' => 'missing_credentials']); }
    $h = hash('sha256', $tok);
    $s = $db->prepare('SELECT * FROM instance_links WHERE token_hash = ?');
    $s->execute([$h]);
    $link = $s->fetch() ?: null;
    if ($link && $link['revoked']) { api_out(403, ['ok' => false, 'error' => 'link_revoked']); }
    if ($link && $link['verified_at'] && strtotime($link['verified_at']) > time() - 3600) { return $link; }
    // Vérification auprès du serveur de distribution (au plus une fois par heure et par instance).
    [$code, $r] = Dist::call('POST', '/admin/verify-install', ['install_token' => $tok], 6);
    if ($code === 404) { api_out(401, ['ok' => false, 'error' => 'unknown_install']); }
    if ($code !== 200) { api_out(503, ['ok' => false, 'error' => 'verification_unavailable']); }
    if (!empty($r['revoked'])) { api_out(403, ['ok' => false, 'error' => 'license_revoked']); }
    $etab = $db->prepare('SELECT id FROM establishments WHERE license_id = ?');
    $etab->execute([(int) $r['license_id']]);
    $eid = $etab->fetchColumn() ?: null;
    $vals = [(int) $r['activation_id'], (int) $r['license_id'], $r['hostname'] ?? null, $r['app_url'] ?? null, $r['app_version'] ?? null, $eid];
    if ($link) {
        $db->prepare('UPDATE instance_links SET activation_id = ?, license_id = ?, hostname = ?, app_url = ?, app_version = ?, establishment_id = COALESCE(?, establishment_id), verified_at = NOW() WHERE id = ?')
           ->execute([...$vals, (int) $link['id']]);
    } else {
        $db->prepare('INSERT INTO instance_links (activation_id, license_id, hostname, app_url, app_version, establishment_id, token_hash, label, verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
           ->execute([...$vals, $h, $r['note'] ?? null]);
        pf_audit('instance', 'instance.liaison', $r['hostname'] ?? ('activation ' . $r['activation_id']), ['license_id' => $r['license_id']]);
    }
    $s->execute([$h]);
    return $s->fetch();
}

function api_json(): array
{
    $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
    $j = json_decode($raw, true);
    if (!is_array($j)) { api_out(400, ['ok' => false, 'error' => 'bad_json']); }
    return $j;
}

function api_rate(int $linkId, string $sql, int $max): void
{
    $s = pf_db()->prepare($sql);
    $s->execute([$linkId]);
    if ((int) $s->fetchColumn() >= $max) { api_out(429, ['ok' => false, 'error' => 'rate_limited']); }
}

$db = pf_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sub = substr($path, strlen('/api/instance'));
$link = api_link();
$db->prepare('UPDATE instance_links SET last_seen = NOW() WHERE id = ?')->execute([(int) $link['id']]);
$lid = (int) $link['id'];
$clean = static fn($s, int $max) => mb_substr(trim(str_replace("\0", '', (string) $s)), 0, $max);

if ($sub === '/ping' && $method === 'GET') {
    api_out(200, ['ok' => true, 'establishment_linked' => (bool) $link['establishment_id']]);
}

if ($sub === '/tickets' && $method === 'POST') {
    $in = api_json();
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['ref'] ?? ''));
    $subject = $clean($in['subject'] ?? '', 255);
    $body = $clean($in['body'] ?? '', 20000);
    if ($ref === '' || strlen($ref) > 64 || $subject === '' || $body === '') { api_out(400, ['ok' => false, 'error' => 'missing_fields']); }
    // Idempotent : l'instance peut renvoyer la même demande après une coupure.
    $ex = $db->prepare('SELECT id, status FROM tickets WHERE link_id = ? AND instance_ref = ?');
    $ex->execute([$lid, $ref]);
    if ($row = $ex->fetch()) { api_out(200, ['ok' => true, 'id' => (int) $row['id'], 'status' => $row['status'], 'duplicate' => true]); }
    api_rate($lid, 'SELECT COUNT(*) FROM tickets WHERE link_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', 20);
    $prio = in_array($in['priority'] ?? '', ['basse', 'normale', 'haute', 'urgente'], true) ? $in['priority'] : 'normale';
    $cat = preg_replace('/[^a-z_]/', '', (string) ($in['category'] ?? 'technique')) ?: 'technique';
    $ctx = is_array($in['context'] ?? null) ? array_map(fn($v) => is_scalar($v) ? $clean($v, 300) : null, $in['context']) : [];
    $db->beginTransaction();
    $db->prepare('INSERT INTO tickets (link_id, establishment_id, instance_ref, subject, category, priority, sent_by, context_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$lid, $link['establishment_id'], $ref, $subject, $cat, $prio, $clean($in['sent_by'] ?? '', 160) ?: null, json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
    $tid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO ticket_messages (ticket_id, side, author, body) VALUES (?, 'etablissement', ?, ?)")
       ->execute([$tid, $clean($in['sent_by'] ?? 'Établissement', 160) ?: 'Établissement', $body]);
    $db->commit();
    pf_audit('instance', 'support.recue', '#' . $tid, ['link' => $lid, 'ref' => $ref]);
    api_out(201, ['ok' => true, 'id' => $tid, 'status' => 'nouveau']);
}

if (preg_match('#^/tickets/([A-Za-z0-9_-]{1,64})/messages$#', $sub, $m) && $method === 'POST') {
    $in = api_json();
    $t = $db->prepare('SELECT id, status FROM tickets WHERE link_id = ? AND instance_ref = ?');
    $t->execute([$lid, $m[1]]);
    $ticket = $t->fetch();
    if (!$ticket) { api_out(404, ['ok' => false, 'error' => 'unknown_ticket']); }
    api_rate($lid, "SELECT COUNT(*) FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id WHERE t.link_id = ? AND tm.side = 'etablissement' AND tm.created_at > NOW() - INTERVAL 1 HOUR", 200);
    $body = $clean($in['body'] ?? '', 20000);
    // Idempotence : un message identique envoyé deux fois (rejeu après coupure) n'est gardé qu'une fois.
    $dup = $db->prepare("SELECT id FROM ticket_messages WHERE ticket_id = ? AND side = 'etablissement' AND body = ? AND created_at > NOW() - INTERVAL 1 DAY");
    $dup->execute([(int) $ticket['id'], $body]);
    if ($body !== '' && !$dup->fetchColumn()) {
        $db->prepare("INSERT INTO ticket_messages (ticket_id, side, author, body) VALUES (?, 'etablissement', ?, ?)")
           ->execute([(int) $ticket['id'], $clean($in['author'] ?? 'Établissement', 160) ?: 'Établissement', $body]);
    }
    $status = !empty($in['close']) ? 'ferme' : ($body !== '' && in_array($ticket['status'], ['attente_etablissement', 'resolu'], true) ? 'en_cours' : $ticket['status']);
    $db->prepare('UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, (int) $ticket['id']]);
    api_out(200, ['ok' => true, 'status' => $status]);
}

if ($sub === '/updates' && $method === 'GET') {
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $msgs = $db->prepare("SELECT tm.id, t.instance_ref AS ref, tm.author, tm.body, tm.created_at
        FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id
        WHERE t.link_id = ? AND tm.side = 'plateforme' AND tm.internal = 0 AND tm.id > ? ORDER BY tm.id LIMIT 200");
    $msgs->execute([$lid, $since]);
    $msgs = $msgs->fetchAll();
    $st = $db->prepare('SELECT instance_ref AS ref, status, updated_at FROM tickets WHERE link_id = ? AND updated_at > NOW() - INTERVAL 30 DAY');
    $st->execute([$lid]);
    $cursor = $since;
    foreach ($msgs as $mm) { $cursor = max($cursor, (int) $mm['id']); }
    api_out(200, ['ok' => true, 'messages' => $msgs, 'statuses' => $st->fetchAll(), 'cursor' => $cursor]);
}

api_out(404, ['ok' => false, 'error' => 'not_found']);
