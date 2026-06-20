<?php
/** Portail PLATEFORME — Support : détail d'un ticket + demande d'accès + session. */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Support\SupportTicketService;
use API\Support\SupportAccessRequestService;
use API\Support\SupportSessionService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.support.ticket.view');

$pdo = getPDO();
$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$tickets  = new SupportTicketService($pdo);
$requests = new SupportAccessRequestService($pdo);
$sessions = new SupportSessionService($pdo);

$id = (int) ($_GET['id'] ?? 0);
$ticket = $tickets->get($id);
if (!$ticket) { http_response_code(404); echo 'Ticket introuvable.'; exit; }
$estab = (int) $ticket['establishment_id'];

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'reply' && platformCan('platform.support.ticket.reply')) {
                $tickets->reply($id, 'platform', $accId, trim((string) $_POST['message']), ['is_internal_note' => !empty($_POST['internal'])]);
                $msg = 'Réponse ajoutée.';
            } elseif ($action === 'request_access' && platformCan('platform.support.access_request.create')) {
                $payload = [];
                if (!empty($_POST['scope_payload'])) {
                    $payload = json_decode((string) $_POST['scope_payload'], true);
                    if (!is_array($payload)) { throw new \RuntimeException('Périmètre JSON invalide (ex. {"student":[100]}).'); }
                }
                $requests->create($accId, $id, $estab, (string) $_POST['level'], (string) $_POST['scope_type'], $payload,
                    trim((string) $_POST['reason']), (int) $_POST['duration'], ['detailed_reason' => $_POST['detailed_reason'] ?? null, 'planned_actions' => $_POST['planned_actions'] ?? null]);
                $msg = "Demande d'accès envoyée à la Direction.";
            } elseif ($action === 'start_session' && platformCan('platform.support.session.start')) {
                $sessions->start((int) $_POST['session_id'], $accId);
                $msg = 'Session démarrée.';
            } elseif ($action === 'end_session' && platformCan('platform.support.session.end')) {
                $sessions->endBySupport((int) $_POST['session_id'], $accId, 'Clôture support', trim((string) ($_POST['summary'] ?? '')) ?: null);
                $msg = 'Session terminée.';
            } else { $err = 'Action non autorisée.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
    $ticket = $tickets->get($id);
}
if (!empty($_GET['imp_error'])) { $err = (string) $_GET['imp_error']; }
if (!empty($_GET['impersonation_ended'])) { $msg = 'Impersonation terminée.'; }

$messages = $tickets->messages($id);
$reqs     = $requests->forTicket($id);
$sess     = $sessions->forTicket($id);
$h = fn($s) => htmlspecialchars((string) $s);
$levels = SupportAccessRequestService::LEVELS;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket #<?= (int) $id ?> — Support</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 860px; margin: 24px auto; padding: 0 16px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; margin: 14px 0; }
        .msg { border-left: 3px solid #334155; padding: 6px 12px; margin: 8px 0; } .msg.platform { border-color: #3b82f6; } .msg.tenant { border-color: #16a34a; }
        input, select, textarea { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #334155; border-radius: 6px; background: #0f172a; color: #e2e8f0; margin: 4px 0; }
        button { padding: 7px 12px; border: 0; border-radius: 6px; background: #3b82f6; color: #fff; cursor: pointer; font-weight: 600; }
        .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .75rem; } .muted { color: #94a3b8; font-size: .85rem; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 6px; border-bottom: 1px solid #273449; font-size: .85rem; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/support/tickets.php">← Tickets</a></header>
    <main>
        <h1>#<?= (int) $id ?> — <?= $h($ticket['title']) ?> <span class="badge"><?= $h($ticket['status']) ?></span></h1>
        <p class="muted">Catégorie <?= $h($ticket['category']) ?> · établissement #<?= $estab ?></p>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>

        <div class="card">
            <h2>Conversation</h2>
            <div class="msg muted"><?= $h($ticket['description']) ?></div>
            <?php foreach ($messages as $m): ?>
                <div class="msg <?= $h($m['author_type']) ?>"><?= $h($m['message']) ?>
                    <?php if (!empty($m['is_internal_note'])): ?> <span class="badge">note interne</span><?php endif; ?>
                    <div class="muted"><?= $h($m['author_type']) ?> · <?= $h($m['created_at']) ?></div></div>
            <?php endforeach; ?>
            <?php if (platformCan('platform.support.ticket.reply')): ?>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="reply">
                <textarea name="message" rows="2" placeholder="Réponse…" required></textarea>
                <label class="muted"><input type="checkbox" name="internal" style="width:auto"> note interne</label>
                <div><button type="submit">Répondre</button></div>
            </form>
            <?php endif; ?>
        </div>

        <?php if (platformCan('platform.support.access_request.create')): ?>
        <div class="card">
            <h2>Demander un accès</h2>
            <p class="muted">Niveau + périmètre + durée précis (jamais global). Validé par la Direction.</p>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="request_access">
                <select name="level"><?php foreach ($levels as $l): ?><option value="<?= $l ?>"><?= $l ?></option><?php endforeach; ?></select>
                <input name="scope_type" placeholder="Type de périmètre (student, user_account, module…)" required>
                <input name="scope_payload" placeholder='Périmètre JSON ex. {"student":[100]}'>
                <input name="reason" placeholder="Motif" required>
                <input name="duration" type="number" placeholder="Durée (minutes)" value="120" min="1" required>
                <div><button type="submit">Envoyer la demande</button></div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Demandes d'accès &amp; sessions</h2>
            <table>
                <thead><tr><th>Demande</th><th>Niveau</th><th>Statut</th></tr></thead>
                <tbody>
                <?php if (!$reqs): ?><tr><td colspan="3"><em>Aucune.</em></td></tr><?php endif; ?>
                <?php foreach ($reqs as $r): ?><tr><td>#<?= (int) $r['id'] ?></td><td><span class="badge"><?= $h($r['access_level']) ?></span></td><td><?= $h($r['status']) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
            <table style="margin-top:10px">
                <thead><tr><th>Session</th><th>Niveau</th><th>Statut</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$sess): ?><tr><td colspan="5"><em>Aucune session.</em></td></tr><?php endif; ?>
                <?php foreach ($sess as $s): ?>
                    <tr>
                        <td>#<?= (int) $s['id'] ?></td><td><span class="badge"><?= $h($s['access_level']) ?></span></td>
                        <td><?= $h($s['status']) ?></td><td class="muted"><?= $h($s['expires_at']) ?></td>
                        <td>
                            <?php if ($s['status'] === 'pending_start' && platformCan('platform.support.session.start')): ?>
                                <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="start_session"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button type="submit">Démarrer</button></form>
                            <?php elseif ($s['status'] === 'active'): ?>
                                <?php if (platformCan('platform.support.session.end')): ?>
                                    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="end_session"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><input name="summary" placeholder="synthèse de fin" style="width:140px"><button type="submit">Terminer</button></form>
                                <?php endif; ?>
                                <?php if ((SupportSessionService::LEVELS[$s['access_level']] ?? 0) >= SupportSessionService::LEVELS['impersonation']): ?>
                                    <form method="post" action="<?= $base ?>/platform/support/impersonate.php" style="display:inline" onsubmit="return confirm('Démarrer une impersonation ? Toutes les actions seront journalisées.')"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= (int) $id ?>"><input type="hidden" name="establishment_id" value="<?= (int) $s['establishment_id'] ?>"><input name="tenant_account_id" type="number" placeholder="compte cible (id)" style="width:120px" required><button type="submit">Impersonifier</button></form>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?= $base ?>/platform/support/report.php?session=<?= (int) $s['id'] ?>">Rapport</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $trail = $sessions->auditTrail((int) $s['id']); if ($trail): ?>
                        <tr><td colspan="5" class="muted">Journal : <?php foreach ($trail as $a) { echo $h($a['action']) . ' '; } ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
