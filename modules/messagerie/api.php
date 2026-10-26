<?php
declare(strict_types=1);
/**
 * Messagerie — API JSON (utilisée par assets/js/messagerie.js).
 *
 * GET  ?action=conversations&box=inbox|unread|archived&q=&before=
 * GET  ?action=conversation&id=          GET ?action=messages&id=&before=&after=
 * GET  ?action=directory&q=              GET ?action=unread
 * POST action=send       (to[]=type:id, groups[]=clé, subject, body, status, files[]) → nouvelle conversation
 * POST action=reply      (id, body, status, files[])
 * POST action=read       (id)            POST action=flag (id, flag=archived|pinned|muted|deleted, on=0|1)
 * POST action=private    (id)            POST action=delete_message (message_id)
 *
 * Écritures : jeton CSRF en en-tête X-CSRF-Token (vérifié sans être consommé : une page
 * de messagerie enchaîne de nombreux appels) + contrôle d'origine (Origin/Referer = hôte).
 */
require_once __DIR__ . '/../../API/bootstrap.php';

use API\Services\Messaging\MessagingService;
use API\Services\Messaging\People;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function msg_out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) { msg_out(401, ['ok' => false, 'error' => 'Session expirée — reconnectez-vous.']); }
try { enforceModuleAccess('messagerie'); } catch (\Throwable $e) { msg_out(403, ['ok' => false, 'error' => 'Accès refusé.']); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($method === 'POST') {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $oh = strtolower((string) parse_url($origin, PHP_URL_HOST)) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
    if ($origin === '' || ($oh !== $host && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host))) {
        msg_out(403, ['ok' => false, 'error' => 'Origine refusée.']);
    }
    $tok = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    if (!app('csrf')->check($tok)) { msg_out(419, ['ok' => false, 'error' => 'Page expirée — rechargez la messagerie.']); }
}

try {
    $svc = MessagingService::forCurrentUser();
} catch (\Throwable $e) {
    msg_out(403, ['ok' => false, 'error' => 'La messagerie n\'est pas disponible pour ce compte.']);
}
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

try {
    switch ($method . ':' . $action) {
        case 'GET:conversations':
            msg_out(200, ['ok' => true] + $svc->conversations([
                'box' => (string) ($_GET['box'] ?? 'inbox'), 'q' => (string) ($_GET['q'] ?? ''), 'before' => (string) ($_GET['before'] ?? ''),
            ]) + ['unread_total' => $svc->unreadTotal()]);

        case 'GET:conversation':
            msg_out(200, ['ok' => true, 'conversation' => $svc->conversation($id)]);

        case 'GET:messages':
            $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
            $after = isset($_GET['after']) ? (int) $_GET['after'] : null;
            msg_out(200, ['ok' => true] + $svc->messages($id, $before ?: null, $after));

        case 'GET:unread':
            msg_out(200, ['ok' => true, 'unread_total' => $svc->unreadTotal()]);

        case 'GET:directory':
            $q = mb_substr((string) ($_GET['q'] ?? ''), 0, 60);
            msg_out(200, ['ok' => true, 'groups' => $svc->policy()->directory($q), 'broadcast' => $q === '' ? $svc->policy()->broadcastGroups() : []]);

        case 'POST:send':
            $to = [];
            foreach ((array) ($_POST['to'] ?? []) as $t) {
                if (preg_match('/^([a-z_]+):(\d+)$/', (string) $t, $m) && People::valid($m[1])) { $to[] = ['id' => (int) $m[2], 'type' => $m[1]]; }
            }
            $cid = $svc->create($to, array_map('strval', (array) ($_POST['groups'] ?? [])), (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['body'] ?? ''), (string) ($_POST['status'] ?? 'normal'), $_FILES['files'] ?? []);
            msg_out(201, ['ok' => true, 'id' => $cid]);

        case 'POST:reply':
            $mid = $svc->reply($id, (string) ($_POST['body'] ?? ''), (string) ($_POST['status'] ?? 'normal'), $_FILES['files'] ?? []);
            msg_out(201, ['ok' => true, 'message_id' => $mid]);

        case 'POST:read':
            $svc->markRead($id);
            msg_out(200, ['ok' => true, 'unread_total' => $svc->unreadTotal()]);

        case 'POST:flag':
            $svc->setFlag($id, (string) ($_POST['flag'] ?? ''), !empty($_POST['on']));
            msg_out(200, ['ok' => true]);

        case 'POST:private':
            msg_out(200, ['ok' => true, 'id' => $svc->privateReply($id)]);

        case 'POST:delete_message':
            $svc->deleteMessage((int) ($_POST['message_id'] ?? 0));
            msg_out(200, ['ok' => true]);
    }
    msg_out(400, ['ok' => false, 'error' => 'Action inconnue.']);
} catch (\RuntimeException $e) {
    msg_out(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[messagerie/api] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    msg_out(500, ['ok' => false, 'error' => 'Erreur interne — réessayez.']);
}
