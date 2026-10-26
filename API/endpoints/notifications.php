<?php
declare(strict_types=1);
/**
 * Centre de notifications — API JSON (cloche, page des notifications, préférences).
 *
 * GET  ?action=summary[&fp=<empreinte>]  → { count, fp, changed } (lecture périodique très légère)
 * GET  ?action=list&unread=0|1&category=&before=&limit=
 * POST action=read   ids[]=… | all=1          POST action=delete id=… | read=1 (supprime les lues)
 * GET  ?action=prefs                          POST action=prefs  prefs[cat][web|email|push]=1
 * Écritures : X-CSRF-Token (vérifié sans être consommé) + contrôle d'origine.
 */
require_once __DIR__ . '/../bootstrap.php';

use API\Services\Notifications\NotificationCenter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function notif_out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) { notif_out(401, ['ok' => false, 'error' => 'Session expirée.']); }
$uid = (int) getUserId();
$utype = (string) getUserRole();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($method === 'POST') {
    $host = preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($origin === '' || strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) { notif_out(403, ['ok' => false, 'error' => 'Origine refusée.']); }
    if (!app('csrf')->check((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ''))) { notif_out(419, ['ok' => false, 'error' => 'Page expirée — rechargez.']); }
}

$nc = NotificationCenter::make();
try {
    switch ($method . ':' . $action) {
        case 'GET:summary':
            $fp = $nc->fingerprint($uid, $utype);
            notif_out(200, ['ok' => true, 'count' => $nc->unreadCount($uid, $utype), 'fp' => $fp, 'changed' => ($_GET['fp'] ?? '') !== $fp]);

        case 'GET:list':
            $r = $nc->list($uid, $utype, [
                'unread' => !empty($_GET['unread']), 'category' => (string) ($_GET['category'] ?? ''), 'before' => (int) ($_GET['before'] ?? 0),
            ], (int) ($_GET['limit'] ?? 20));
            notif_out(200, ['ok' => true] + $r + ['count' => $nc->unreadCount($uid, $utype), 'fp' => $nc->fingerprint($uid, $utype)]);

        case 'POST:read':
            $n = !empty($_POST['all']) ? $nc->markRead($uid, $utype, null) : $nc->markRead($uid, $utype, (array) ($_POST['ids'] ?? []));
            notif_out(200, ['ok' => true, 'updated' => $n, 'count' => $nc->unreadCount($uid, $utype)]);

        case 'POST:delete':
            $n = !empty($_POST['read']) ? $nc->delete($uid, $utype, null, true) : $nc->delete($uid, $utype, (int) ($_POST['id'] ?? 0) ?: -1);
            notif_out(200, ['ok' => true, 'deleted' => $n, 'count' => $nc->unreadCount($uid, $utype)]);

        case 'GET:prefs':
            notif_out(200, ['ok' => true, 'prefs' => $nc->preferences($uid, $utype), 'categories' => NotificationCenter::CATEGORIES]);

        case 'POST:prefs':
            $in = (array) ($_POST['prefs'] ?? []);
            $clean = [];
            foreach (NotificationCenter::CATEGORIES as $c => $_) {
                $clean[$c] = ['web' => !empty($in[$c]['web']), 'email' => !empty($in[$c]['email']), 'push' => !empty($in[$c]['push'])];
            }
            $nc->savePreferences($uid, $utype, $clean);
            notif_out(200, ['ok' => true, 'prefs' => $nc->preferences($uid, $utype)]);
    }
    notif_out(400, ['ok' => false, 'error' => 'Action inconnue.']);
} catch (\Throwable $e) {
    error_log('[notifications/api] ' . $e->getMessage());
    notif_out(500, ['ok' => false, 'error' => 'Erreur interne.']);
}
