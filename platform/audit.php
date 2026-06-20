<?php
/** Portail PLATEFORME — audit global (actions plateforme + établissements). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.audit.view');
$pdo = getPDO();
$h = fn($s) => htmlspecialchars((string) $s);

$platformLogs = [];
try {
    $platformLogs = $pdo->query(
        "SELECT l.*, pa.username AS actor FROM platform_audit_logs l
           LEFT JOIN platform_accounts pa ON pa.id = l.platform_account_id
          ORDER BY l.created_at DESC, l.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { error_log('[platform audit] ' . $e->getMessage()); }

$tenantLogs = [];
try {
    $tenantLogs = $pdo->query(
        "SELECT t.*, e.nom AS etab FROM tenant_audit_logs t
           JOIN etablissements e ON e.id = t.establishment_id
          ORDER BY t.created_at DESC, t.id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* table peut être vide */ }
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Audit global</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 1040px; margin: 24px auto; padding: 0 16px; }
        h2 { margin-top: 28px; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 7px; border-bottom: 1px solid #334155; font-size: .82rem; }
        .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .72rem; } .muted { color: #94a3b8; }
        code { color: #93c5fd; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Audit global</h1>

        <h2>Plateforme <span class="muted">(<?= count($platformLogs) ?> dernières)</span></h2>
        <table>
            <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Établ.</th><th>Détail</th></tr></thead>
            <tbody>
            <?php if (!$platformLogs): ?><tr><td colspan="5"><em>Aucune entrée.</em></td></tr><?php endif; ?>
            <?php foreach ($platformLogs as $l): ?>
                <tr><td class="muted"><?= $h($l['created_at']) ?></td><td><?= $h($l['actor'] ?? ('#' . $l['platform_account_id'])) ?></td>
                    <td><span class="badge"><?= $h($l['action']) ?></span></td><td><?= $l['establishment_id'] ? '#' . (int) $l['establishment_id'] : '—' ?></td>
                    <td><code><?= $h(mb_substr((string) ($l['new_value'] ?? ''), 0, 120)) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Établissements <span class="muted">(<?= count($tenantLogs) ?> dernières)</span></h2>
        <table>
            <thead><tr><th>Date</th><th>Établissement</th><th>Action</th><th>Cible</th><th>Détail</th></tr></thead>
            <tbody>
            <?php if (!$tenantLogs): ?><tr><td colspan="5"><em>Aucune entrée.</em></td></tr><?php endif; ?>
            <?php foreach ($tenantLogs as $l): ?>
                <tr><td class="muted"><?= $h($l['created_at']) ?></td><td><?= $h($l['etab']) ?></td>
                    <td><span class="badge"><?= $h($l['action']) ?></span></td>
                    <td class="muted"><?= $h($l['target_type'] ?? '') ?> <?= $l['target_id'] ? '#' . (int) $l['target_id'] : '' ?></td>
                    <td><code><?= $h(mb_substr((string) ($l['new_value'] ?? ''), 0, 120)) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
