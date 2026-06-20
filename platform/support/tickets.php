<?php
/** Portail PLATEFORME — Support : liste des tickets (tous établissements). */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Support\SupportTicketService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.support.ticket.view');

$accId   = (int) ($_SESSION['platform']['account_id'] ?? 0);
$tickets = new SupportTicketService(getPDO());

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    if (($_POST['action'] ?? '') === 'assign' && platformCan('platform.support.ticket.assign')) {
        $tickets->assign((int) $_POST['ticket_id'], $accId);
        $msg = 'Ticket assigné.';
    }
}

$list = $tickets->listAll(['status' => $_GET['status'] ?? null]);
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Support</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; display: flex; justify-content: space-between; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 980px; margin: 24px auto; padding: 0 16px; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; font-size: .9rem; }
        a { color: #93c5fd; } .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .75rem; }
        .alert { background: #052e16; color: #86efac; padding: 10px; border-radius: 8px; }
        button { padding: 6px 10px; border: 0; border-radius: 6px; background: #3b82f6; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <header><div><strong>⬢ Support Fronote</strong></div><div><a href="<?= $base ?>/platform/dashboard.php">Tableau de bord</a></div></header>
    <main>
        <h1>Tickets de support</h1>
        <?php if ($msg): ?><div class="alert"><?= $h($msg) ?></div><?php endif; ?>
        <table>
            <thead><tr><th>#</th><th>Établissement</th><th>Titre</th><th>Catégorie</th><th>Statut</th><th>Assigné</th><th></th></tr></thead>
            <tbody>
            <?php if (!$list): ?><tr><td colspan="7"><em>Aucun ticket.</em></td></tr><?php endif; ?>
            <?php foreach ($list as $t): ?>
                <tr>
                    <td><?= (int) $t['id'] ?></td>
                    <td><?= $h($t['establishment_name']) ?></td>
                    <td><a href="<?= $base ?>/platform/support/ticket.php?id=<?= (int) $t['id'] ?>"><?= $h($t['title']) ?></a></td>
                    <td><?= $h($t['category']) ?></td>
                    <td><span class="badge"><?= $h($t['status']) ?></span></td>
                    <td><?= $t['assigned_platform_account_id'] ? '#' . (int) $t['assigned_platform_account_id'] : '—' ?></td>
                    <td><?php if (platformCan('platform.support.ticket.assign') && !$t['assigned_platform_account_id']): ?>
                        <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>"><button type="submit">M'assigner</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
