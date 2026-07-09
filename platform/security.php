<?php
declare(strict_types=1);
/** Portail PLATEFORME — sécurité (comptes internes, sessions support, audit sensible). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\PlatformAccountService;
use API\Support\SupportSessionService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.security.view');

$pdo   = getPDO();
$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$paSvc = new PlatformAccountService($pdo);
$ssSvc = new SupportSessionService($pdo);
$canManage = platformCan('platform.security.manage');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform security audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    elseif (!$canManage) { $err = "Action réservée à la permission platform.security.manage."; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'disable_account') {
                $tid = (int) $_POST['account_id'];
                if ($tid === $accId) { $err = "Vous ne pouvez pas désactiver votre propre compte."; }
                else { $paSvc->disableAccount($tid); $audit('platform.account.disable', ['account' => $tid]); $msg = 'Compte plateforme désactivé.'; }
            } elseif ($action === 'force_stop') {
                $ssSvc->securityStop((int) $_POST['session_id'], $accId, trim((string) ($_POST['reason'] ?? 'Arrêt de sécurité')));
                $msg = 'Session support arrêtée (sécurité).';
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$accounts = $pdo->query(
    "SELECT pa.id, pa.username, pa.email, pa.status, pa.last_login_at,
            (SELECT GROUP_CONCAT(pr.role_key) FROM platform_account_roles par JOIN platform_roles pr ON pr.id = par.platform_role_id WHERE par.platform_account_id = pa.id AND par.is_active = 1) AS roles
       FROM platform_accounts pa ORDER BY pa.username"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeSessions = $ssSvc->allActive();
$sensitive = [];
try {
    $sensitive = $pdo->query(
        "SELECT l.created_at, l.action, l.platform_account_id, pa.username AS actor FROM platform_audit_logs l
           LEFT JOIN platform_accounts pa ON pa.id = l.platform_account_id
          WHERE l.action LIKE '%purge%' OR l.action LIKE '%disable%' OR l.action LIKE '%security%' OR l.action LIKE '%restore%'
          ORDER BY l.id DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}
$h = fn($s) => htmlspecialchars((string) $s);
$sb = ['active' => '#16a34a', 'inactive' => '#64748b', 'locked' => '#d97706', 'archived' => '#dc2626'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Sécurité</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 980px; margin: 24px auto; padding: 0 16px; } h2 { margin-top: 26px; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 7px; border-bottom: 1px solid #334155; font-size: .85rem; }
        .badge { color: #fff; border-radius: 6px; padding: 2px 8px; font-size: .72rem; } .muted { color: #94a3b8; }
        button { padding: 5px 9px; border: 0; border-radius: 6px; background: #dc2626; color: #fff; cursor: pointer; font-size: .8rem; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        form.inline { display: inline; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Sécurité</h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>

        <h2>Comptes internes Fronote</h2>
        <table>
            <thead><tr><th>Identifiant</th><th>Email</th><th>Rôles</th><th>Statut</th><th>Dernière connexion</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
                <tr>
                    <td><?= $h($a['username']) ?><?= (int) $a['id'] === $accId ? ' <span class="muted">(vous)</span>' : '' ?></td>
                    <td class="muted"><?= $h($a['email']) ?></td>
                    <td><?= $h($a['roles'] ?? '—') ?></td>
                    <td><span class="badge" style="background:<?= $sb[$a['status']] ?? '#334155' ?>"><?= $h($a['status']) ?></span></td>
                    <td class="muted"><?= $h($a['last_login_at'] ?? '—') ?></td>
                    <td><?php if ($canManage && $a['status'] === 'active' && (int) $a['id'] !== $accId): ?>
                        <form class="inline" method="post" onsubmit="return confirm('Désactiver ce compte interne ?')"><?= csrfField() ?><input type="hidden" name="action" value="disable_account"><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>"><button type="submit">Désactiver</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Sessions support actives (tous établissements)</h2>
        <table>
            <thead><tr><th>Établissement</th><th>Niveau</th><th>Expire</th><th></th></tr></thead>
            <tbody>
            <?php if (!$activeSessions): ?><tr><td colspan="4"><em>Aucune session active.</em></td></tr><?php endif; ?>
            <?php foreach ($activeSessions as $s): ?>
                <tr><td><?= $h($s['establishment_name']) ?></td><td><span class="badge" style="background:#3b82f6"><?= $h($s['access_level']) ?></span></td><td class="muted"><?= $h($s['expires_at']) ?></td>
                    <td><?php if ($canManage): ?>
                        <form class="inline" method="post" onsubmit="return confirm('Arrêt de sécurité de cette session ?')"><?= csrfField() ?><input type="hidden" name="action" value="force_stop"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button type="submit">Arrêt sécurité</button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Événements sensibles récents</h2>
        <table>
            <thead><tr><th>Date</th><th>Acteur</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$sensitive): ?><tr><td colspan="3"><em>Aucun.</em></td></tr><?php endif; ?>
            <?php foreach ($sensitive as $l): ?><tr><td class="muted"><?= $h($l['created_at']) ?></td><td><?= $h($l['actor'] ?? ('#' . $l['platform_account_id'])) ?></td><td><?= $h($l['action']) ?></td></tr><?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
