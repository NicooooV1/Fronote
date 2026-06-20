<?php
/** Portail PLATEFORME — mode maintenance (verrouille l'app établissement ; la console plateforme reste accessible). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.maintenance.manage');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$maint = app('maintenance');
$myIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform maintenance audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'activate') {
                // IP autorisées : on injecte TOUJOURS l'IP courante (anti-lockout de l'app établissement).
                $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($_POST['allowed_ips'] ?? '')))));
                if ($myIp !== '' && !in_array($myIp, $ips, true)) { $ips[] = $myIp; }
                $eta = ($_POST['eta_minutes'] ?? '') !== '' ? max(1, (int) $_POST['eta_minutes']) : null;
                $maint->activate(trim((string) ($_POST['message'] ?? '')), $ips, $eta);
                $audit('platform.maintenance.activate', ['allowed_ips' => $ips, 'eta_minutes' => $eta]);
                $msg = 'Mode maintenance ACTIVÉ.';
            } elseif ($action === 'deactivate') {
                $maint->deactivate();
                $audit('platform.maintenance.deactivate', []);
                $msg = 'Mode maintenance désactivé.';
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$active  = $maint->isActive();
$status  = $maint->getStatus();
$curIps  = $status['allowed_ips'] ?? [];
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Maintenance</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 760px; margin: 24px auto; padding: 0 16px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; margin: 14px 0; }
        label { display: block; margin: 10px 0 4px; font-size: .85rem; color: #94a3b8; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #334155; border-radius: 6px; background: #0f172a; color: #e2e8f0; }
        button { padding: 9px 16px; border: 0; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer; }
        button.on { background: #d97706; } button.off { background: #16a34a; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        .pill { display: inline-block; border-radius: 999px; padding: 4px 12px; font-weight: 700; }
        .pill.on { background: #7c2d12; color: #fdba74; } .pill.off { background: #064e3b; color: #6ee7b7; }
        .muted { color: #94a3b8; font-size: .85rem; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Maintenance</h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>

        <div class="card">
            État :
            <?php if ($active): ?>
                <span class="pill on">● MAINTENANCE ACTIVE</span>
                <p class="muted">Message : <?= $h($status['message'] ?? '') ?><?php if (!empty($curIps)): ?> · IP autorisées : <?= $h(implode(', ', $curIps)) ?><?php endif; ?></p>
                <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="deactivate">
                    <button class="off" type="submit">Désactiver la maintenance</button>
                </form>
            <?php else: ?>
                <span class="pill off">● En ligne</span>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Activer la maintenance</h2>
            <p class="muted">Verrouille l'application établissement (HTTP 503). La <strong>console plateforme reste accessible</strong>.
               Votre IP (<?= $h($myIp) ?>) est ajoutée automatiquement aux IP autorisées.</p>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="activate">
                <label>Message affiché aux utilisateurs</label>
                <textarea name="message" rows="2" placeholder="Maintenance en cours. Merci de votre patience."><?= $h($status['message'] ?? '') ?></textarea>
                <label>IP autorisées (séparées par des espaces/virgules, CIDR accepté)</label>
                <input name="allowed_ips" value="<?= $h(implode(' ', $curIps ?: [$myIp])) ?>">
                <label>Durée estimée (minutes, optionnel)</label>
                <input name="eta_minutes" type="number" min="1" value="<?= $h((string) ($status['eta_minutes'] ?? '')) ?>">
                <div style="margin-top:12px"><button class="on" type="submit">Activer la maintenance</button></div>
            </form>
        </div>
    </main>
</body>
</html>
