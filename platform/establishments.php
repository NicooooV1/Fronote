<?php
/** Portail PLATEFORME — gestion du parc d'établissements (suspend/archive/delete/purge). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\PlatformEstablishmentService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.establishments.view');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$svc = new PlatformEstablishmentService(getPDO());

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'suspend'  && platformCan('platform.establishments.suspend')) { $svc->suspend($id, $accId); $msg = 'Établissement suspendu.'; }
            elseif ($action === 'activate' && platformCan('platform.establishments.suspend')) { $svc->activate($id, $accId); $msg = 'Établissement réactivé.'; }
            elseif ($action === 'archive' && platformCan('platform.establishments.archive')) { $svc->archive($id, $accId); $msg = 'Établissement archivé (lecture seule).'; }
            elseif ($action === 'delete'  && platformCan('platform.establishments.delete')) { $svc->softDelete($id, $accId); $msg = 'Établissement supprimé (logique).'; }
            elseif ($action === 'purge'   && platformCan('platform.establishments.purge')) {
                if (($_POST['confirm'] ?? '') !== 'PURGER') { $err = 'Saisissez PURGER pour confirmer la purge définitive.'; }
                else { $r = $svc->purge($id, $accId); $msg = "Établissement purgé ({$r['purged_memberships']} appartenances, {$r['purged_accounts']} comptes, {$r['purged_tickets']} tickets)."; }
            } else { $err = "Action non autorisée."; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$list = $svc->listAll();
$canPurge = platformCan('platform.establishments.purge');
$h = fn($s) => htmlspecialchars((string) $s);
$badge = ['active' => '#16a34a', 'suspended' => '#d97706', 'archived' => '#64748b', 'deleted' => '#dc2626', 'purged' => '#7f1d1d', 'onboarding' => '#2563eb', 'draft' => '#64748b'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Établissements</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 1040px; margin: 24px auto; padding: 0 16px; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; font-size: .88rem; vertical-align: middle; }
        .badge { color: #fff; border-radius: 6px; padding: 2px 8px; font-size: .72rem; }
        button { padding: 5px 9px; border: 0; border-radius: 6px; background: #334155; color: #e2e8f0; cursor: pointer; font-size: .8rem; }
        button.warn { background: #d97706; } button.danger { background: #dc2626; } button.ok { background: #16a34a; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        form.inline { display: inline; } input.confirm { width: 80px; padding: 4px; background: #0f172a; border: 1px solid #7f1d1d; color: #fca5a5; border-radius: 6px; }
        .muted { color: #94a3b8; font-size: .8rem; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Parc d'établissements <span class="muted">(<?= count($list) ?>)</span></h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>
        <table>
            <thead><tr><th>#</th><th>Nom</th><th>Slug</th><th>Type</th><th>Statut</th><th>Membres</th><th>Support actif</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$list): ?><tr><td colspan="8"><em>Aucun établissement.</em></td></tr><?php endif; ?>
            <?php foreach ($list as $e): $st = $e['status']; ?>
                <tr>
                    <td><?= (int) $e['id'] ?></td>
                    <td><?= $h($e['nom']) ?></td>
                    <td class="muted"><?= $h($e['slug']) ?></td>
                    <td class="muted"><?= $h($e['type']) ?></td>
                    <td><span class="badge" style="background:<?= $badge[$st] ?? '#334155' ?>"><?= $h($st) ?></span></td>
                    <td><?= (int) $e['members'] ?></td>
                    <td><?= (int) $e['support_sessions'] ?></td>
                    <td>
                        <?php if ($st !== 'purged'): ?>
                            <?php if ($st === 'suspended'): ?>
                                <?php if (platformCan('platform.establishments.suspend')): ?>
                                <form class="inline" method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="activate"><button class="ok" type="submit">Réactiver</button></form>
                                <?php endif; ?>
                            <?php elseif (platformCan('platform.establishments.suspend')): ?>
                                <form class="inline" method="post" onsubmit="return confirm('Suspendre (bloque les connexions) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="suspend"><button class="warn" type="submit">Suspendre</button></form>
                            <?php endif; ?>
                            <?php if (platformCan('platform.establishments.archive') && $st !== 'archived'): ?>
                                <form class="inline" method="post" onsubmit="return confirm('Archiver (lecture seule) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="archive"><button type="submit">Archiver</button></form>
                            <?php endif; ?>
                            <?php if (platformCan('platform.establishments.delete') && $st !== 'deleted'): ?>
                                <form class="inline" method="post" onsubmit="return confirm('Supprimer (logique) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="delete"><button class="danger" type="submit">Suppr.</button></form>
                            <?php endif; ?>
                            <?php if ($canPurge): ?>
                                <form class="inline" method="post" onsubmit="return confirm('PURGE DÉFINITIVE — irréversible. Continuer ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="purge"><input class="confirm" name="confirm" placeholder="PURGER"><button class="danger" type="submit">Purger</button></form>
                            <?php endif; ?>
                        <?php else: ?><span class="muted">purgé</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin-top:16px">Suspendre = connexions bloquées · Archiver = lecture seule · Supprimer = logique (réversible) · Purger = définitif (SuperAdmin), supprime les données 3-mondes de l'établissement.</p>
    </main>
</body>
</html>
