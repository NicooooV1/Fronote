<?php
declare(strict_types=1);
/** Portail PLATEFORME — sauvegardes (lister / créer / supprimer / nettoyer / restaurer). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.backups.view');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$backup = app('backup');
$canRestore = platformCan('platform.backups.restore');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform backups audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_db') {
                $f = $backup->createDatabaseBackup(); $audit('platform.backup.create_db', ['file' => basename((string) $f)]);
                $msg = 'Sauvegarde base créée : ' . basename((string) $f);
            } elseif ($action === 'create_full') {
                $r = $backup->createFullBackup(); $audit('platform.backup.create_full', $r);
                $msg = 'Sauvegarde complète créée.';
            } elseif ($action === 'cleanup') {
                $n = $backup->cleanup(5); $audit('platform.backup.cleanup', ['deleted' => $n]);
                $msg = "Nettoyage : {$n} ancienne(s) sauvegarde(s) supprimée(s).";
            } elseif ($action === 'delete' && $canRestore) {
                $backup->deleteBackup((string) $_POST['filename']); $audit('platform.backup.delete', ['file' => (string) $_POST['filename']]);
                $msg = 'Sauvegarde supprimée.';
            } elseif ($action === 'restore') {
                if (!$canRestore) { $err = 'Restauration réservée à platform.backups.restore.'; }
                elseif (($_POST['confirm'] ?? '') !== 'RESTAURER') { $err = 'Saisissez RESTAURER pour confirmer.'; }
                else {
                    $fname = basename((string) $_POST['filename']);
                    $path = null;
                    foreach ($backup->listBackups() as $b) { if ($b['filename'] === $fname) { $path = $b['path']; break; } }
                    if ($path === null) { $err = 'Sauvegarde introuvable.'; }
                    else {
                        $audit('platform.backup.restore', ['file' => $fname]); // tracé AVANT (la restauration écrase la base)
                        $ok = $backup->restoreDatabase($path);
                        $msg = $ok ? "Base restaurée depuis {$fname}." : 'Échec de la restauration.';
                        if (!$ok) { $err = 'Échec de la restauration (voir logs).'; }
                    }
                }
            } else { $err = 'Action non autorisée.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$list = $backup->listBackups();
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Sauvegardes</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 900px; margin: 24px auto; padding: 0 16px; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; font-size: .85rem; }
        button { padding: 6px 11px; border: 0; border-radius: 6px; background: #3b82f6; color: #fff; cursor: pointer; font-weight: 600; }
        button.warn { background: #d97706; } button.danger { background: #dc2626; } button.sm { padding: 4px 8px; font-weight: 400; font-size: .8rem; background: #334155; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        .actions { margin: 16px 0; display: flex; gap: 8px; flex-wrap: wrap; } form.inline { display: inline; } .muted { color: #94a3b8; font-size: .82rem; }
        input.confirm { width: 100px; padding: 4px; background: #0f172a; border: 1px solid #7f1d1d; color: #fca5a5; border-radius: 6px; }
        .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .72rem; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Sauvegardes</h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>
        <div class="actions">
            <form class="inline" method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_db"><button type="submit">Sauvegarde base</button></form>
            <form class="inline" method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_full"><button type="submit">Sauvegarde complète</button></form>
            <form class="inline" method="post" onsubmit="return confirm('Supprimer les anciennes (garder 5/type) ?')"><?= csrfField() ?><input type="hidden" name="action" value="cleanup"><button class="warn" type="submit">Nettoyer (garder 5)</button></form>
        </div>
        <table>
            <thead><tr><th>Fichier</th><th>Type</th><th>Taille</th><th>Créé</th><th></th></tr></thead>
            <tbody>
            <?php if (!$list): ?><tr><td colspan="5"><em>Aucune sauvegarde.</em></td></tr><?php endif; ?>
            <?php foreach ($list as $b): ?>
                <tr>
                    <td><?= $h($b['filename']) ?></td>
                    <td><span class="badge"><?= $h($b['type']) ?></span></td>
                    <td class="muted"><?= $h($b['size_mb']) ?> Mo</td>
                    <td class="muted"><?= $h($b['created_at']) ?></td>
                    <td>
                        <?php if ($canRestore && $b['type'] === 'database'): ?>
                            <form class="inline" method="post" onsubmit="return confirm('RESTAURER écrase la base actuelle. Continuer ?')"><?= csrfField() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="filename" value="<?= $h($b['filename']) ?>"><input class="confirm" name="confirm" placeholder="RESTAURER"><button class="danger sm" type="submit">Restaurer</button></form>
                        <?php endif; ?>
                        <?php if ($canRestore): ?>
                            <form class="inline" method="post" onsubmit="return confirm('Supprimer cette sauvegarde ?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="filename" value="<?= $h($b['filename']) ?>"><button class="sm" type="submit">Suppr.</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin-top:16px">Stockées hors web dans storage/backups. Restauration réservée à platform.backups.restore (SuperAdmin/Maintenance), confirmation « RESTAURER » exigée.</p>
    </main>
</body>
</html>
