<?php
declare(strict_types=1);
/**
 * Portail ÉTABLISSEMENT — centre de Support (côté Direction).
 * Voir/ouvrir des tickets, DÉCIDER des demandes d'accès du Support Fronote
 * (approuver / limiter / refuser), et RÉVOQUER une session active.
 */
require __DIR__ . '/_bootstrap.php';
tenantRequireAuth($establishment, $base, $slug);

use API\Support\SupportTicketService;
use API\Support\SupportAccessRequestService;
use API\Support\SupportAccessApprovalService;
use API\Support\SupportSessionService;

if (!tenantCan('tenant.support.ticket.view')) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:40px">Accès Support refusé. '
       . '<a href="' . $base . '/tenant/dashboard.php?e=' . urlencode($slug) . '">Retour</a></p>';
    exit;
}

$pdo   = getPDO();
$estab = (int) $_SESSION['tenant']['establishment_id'];
$mId   = (int) $_SESSION['tenant']['membership_id'];
$accId = (int) $_SESSION['tenant']['account_id'];
$tickets  = new SupportTicketService($pdo);
$requests = new SupportAccessRequestService($pdo);
$approval = new SupportAccessApprovalService($pdo);
$sessions = new SupportSessionService($pdo);

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $err = 'Session expirée.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_ticket' && tenantCan('tenant.support.ticket.create')) {
                $tickets->create($estab, $accId, $mId, trim((string) $_POST['title']), (string) ($_POST['category'] ?? 'other'), trim((string) $_POST['description']));
                $msg = 'Ticket ouvert.';
            } elseif ($action === 'approve' && tenantCan('tenant.support.access_request.approve')) {
                $opts = [];
                if (!empty($_POST['limit_minutes'])) { $opts['approved_duration_minutes'] = (int) $_POST['limit_minutes']; }
                $approval->approve($mId, (int) $_POST['request_id'], $opts);
                $msg = empty($opts) ? 'Demande approuvée.' : 'Demande approuvée (durée limitée).';
            } elseif ($action === 'refuse' && tenantCan('tenant.support.access_request.refuse')) {
                $approval->refuse($mId, (int) $_POST['request_id'], trim((string) ($_POST['reason'] ?? 'Refusé')));
                $msg = 'Demande refusée.';
            } elseif ($action === 'revoke' && tenantCan('tenant.support.session.revoke')) {
                $sessions->revokeByDirection((int) $_POST['session_id'], $mId, trim((string) ($_POST['reason'] ?? 'Révoqué par la Direction')));
                $msg = 'Session révoquée.';
            } else {
                $err = "Action non autorisée.";
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$ticketList = $tickets->listForEstablishment($estab);
$pending    = $requests->pendingForEstablishment($estab);
$live       = $sessions->liveForEstablishment($estab);
$history    = $sessions->historyForEstablishment($estab);
$canApprove = tenantCan('tenant.support.access_request.approve');
$canRevoke  = tenantCan('tenant.support.session.revoke');
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($establishment['nom']) ?> — Support</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; }
        header { background: #1e293b; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 8px; border-bottom: 1px solid #eef2f7; font-size: .9rem; }
        input, select, textarea { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; }
        button { padding: 7px 12px; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; font-weight: 600; }
        button.danger { background: #dc2626; } button.ok { background: #16a34a; }
        .alert { padding: 10px; border-radius: 8px; margin: 12px 0; } .ok-a { background: #dcfce7; color: #166534; } .err-a { background: #fee2e2; color: #b91c1c; }
        .muted { color: #64748b; font-size: .85rem; } .badge { background: #eef2ff; color: #3730a3; border-radius: 6px; padding: 2px 8px; font-size: .75rem; }
        form.inline { display: inline; }
    </style>
</head>
<body>
    <header>
        <div><strong><?= $h($establishment['nom']) ?></strong> — Support</div>
        <div><a href="<?= $base ?>/tenant/admin.php?e=<?= urlencode($slug) ?>">Administration</a></div>
    </header>
    <main>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>

        <div class="card">
            <h2>Demandes d'accès Support en attente</h2>
            <p class="muted">Le Support Fronote demande un accès précis ; vous accordez, limitez (durée) ou refusez.</p>
            <table>
                <thead><tr><th>Ticket</th><th>Niveau</th><th>Périmètre</th><th>Durée</th><th>Motif</th><th>Décision</th></tr></thead>
                <tbody>
                <?php if (!$pending): ?><tr><td colspan="6"><em>Aucune demande en attente.</em></td></tr><?php endif; ?>
                <?php foreach ($pending as $r): ?>
                    <tr>
                        <td><?= $h($r['ticket_title']) ?></td>
                        <td><span class="badge"><?= $h($r['access_level']) ?></span></td>
                        <td class="muted"><?= $h($r['requested_scope_type']) ?> <?= $h($r['requested_scope_payload']) ?></td>
                        <td><?= (int) $r['requested_duration_minutes'] ?> min</td>
                        <td class="muted"><?= $h($r['reason']) ?></td>
                        <td>
                            <?php if ($canApprove): ?>
                            <form class="inline" method="post" onsubmit="return confirm('Approuver cette demande ?')">
                                <?= csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                <input type="number" name="limit_minutes" placeholder="limite min" style="width:80px" min="1">
                                <button class="ok" type="submit">Approuver</button>
                            </form>
                            <form class="inline" method="post" onsubmit="return confirm('Refuser ?')">
                                <?= csrfField() ?><input type="hidden" name="action" value="refuse"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                <button class="danger" type="submit">Refuser</button>
                            </form>
                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Sessions Support en cours</h2>
            <table>
                <thead><tr><th>Niveau</th><th>Statut</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$live): ?><tr><td colspan="4"><em>Aucune session active.</em></td></tr><?php endif; ?>
                <?php foreach ($live as $s): ?>
                    <tr>
                        <td><span class="badge"><?= $h($s['access_level']) ?></span></td>
                        <td><?= $h($s['status']) ?></td>
                        <td class="muted"><?= $h($s['expires_at']) ?></td>
                        <td><?php if ($canRevoke): ?>
                            <form class="inline" method="post" onsubmit="return confirm('Révoquer immédiatement cette session ?')">
                                <?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>">
                                <button class="danger" type="submit">Révoquer</button>
                            </form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Historique des interventions</h2>
            <p class="muted">Chaque intervention terminée du Support donne droit à un rapport (transparence).</p>
            <table>
                <thead><tr><th>#</th><th>Niveau</th><th>Statut final</th><th>Terminée</th><th></th></tr></thead>
                <tbody>
                <?php if (!$history): ?><tr><td colspan="5"><em>Aucune intervention passée.</em></td></tr><?php endif; ?>
                <?php foreach ($history as $s): ?>
                    <tr>
                        <td><?= (int) $s['id'] ?></td>
                        <td><span class="badge"><?= $h($s['access_level']) ?></span></td>
                        <td><?= $h($s['status']) ?></td>
                        <td class="muted"><?= $h($s['ended_at'] ?? '') ?></td>
                        <td><a href="<?= $base ?>/tenant/support_report.php?e=<?= urlencode($slug) ?>&session=<?= (int) $s['id'] ?>">Rapport</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Tickets de l'établissement</h2>
            <?php if (tenantCan('tenant.support.ticket.create')): ?>
            <form method="post" style="margin-bottom:16px; display:grid; gap:8px; max-width:560px">
                <?= csrfField() ?><input type="hidden" name="action" value="create_ticket">
                <input name="title" placeholder="Titre du problème" required>
                <select name="category">
                    <?php foreach (SupportTicketService::CATEGORIES as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                </select>
                <textarea name="description" rows="3" placeholder="Description" required></textarea>
                <div><button type="submit">Ouvrir un ticket</button></div>
            </form>
            <?php endif; ?>
            <table>
                <thead><tr><th>#</th><th>Titre</th><th>Catégorie</th><th>Statut</th><th>Créé</th></tr></thead>
                <tbody>
                <?php if (!$ticketList): ?><tr><td colspan="5"><em>Aucun ticket.</em></td></tr><?php endif; ?>
                <?php foreach ($ticketList as $t): ?>
                    <tr><td><?= (int) $t['id'] ?></td><td><?= $h($t['title']) ?></td><td class="muted"><?= $h($t['category']) ?></td><td><span class="badge"><?= $h($t['status']) ?></span></td><td class="muted"><?= $h($t['created_at']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
