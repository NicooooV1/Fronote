<?php
/** Portail PLATEFORME — mises à jour applicatives (git) via UpdateService (sauvegarde + rollback intégrés). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.updates.manage');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$upd   = app('updates');
$canApply = platformCan('platform.system.update');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform updates audit] ' . $e->getMessage()); }
};

$msg = ''; $err = ''; $check = null; $apply = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'check') {
                $check = $upd->checkForUpdate(); // git fetch + comparaison (lecture seule)
                $audit('platform.updates.check', ['behind' => $check['behind'] ?? 0]);
                $msg = $check ? "Mise à jour disponible ({$check['behind']} commit(s))." : 'Application à jour.';
            } elseif ($action === 'apply') {
                if (!$canApply) { $err = 'Réservé à platform.system.update.'; }
                elseif (($_POST['confirm'] ?? '') !== 'METTRE A JOUR') { $err = 'Saisissez « METTRE A JOUR » pour confirmer.'; }
                else {
                    @set_time_limit(300);
                    $apply = $upd->applyUpdate(); // sauvegarde + git reset + schéma/migrations + rollback auto si échec
                    $audit('platform.updates.apply', ['success' => $apply['success'] ?? false, 'old' => $apply['old_version'] ?? null, 'new' => $apply['new_version'] ?? null, 'rolled_back' => $apply['rolled_back'] ?? false]);
                    $msg = !empty($apply['success']) ? "Mise à jour appliquée ({$apply['old_version']} → {$apply['new_version']})." : ('Échec : ' . ($apply['error'] ?? 'inconnu') . (!empty($apply['rolled_back']) ? ' (restauré)' : ''));
                }
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$version = $upd->getCurrentVersion();
$branch  = $upd->getBranch();
$git     = $upd->isGitAvailable();
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Mises à jour</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 800px; margin: 24px auto; padding: 0 16px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; margin: 14px 0; }
        button { padding: 9px 16px; border: 0; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer; }
        button.check { background: #3b82f6; } button.apply { background: #dc2626; }
        input.confirm { padding: 8px; background: #0f172a; border: 1px solid #7f1d1d; color: #fca5a5; border-radius: 6px; width: 170px; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        .muted { color: #94a3b8; font-size: .85rem; } code { color: #93c5fd; }
        pre { background: #0b1220; border: 1px solid #334155; border-radius: 8px; padding: 12px; overflow:auto; font-size: .82rem; }
        .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .75rem; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Mises à jour</h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>

        <div class="card">
            <p>Version : <strong><?= $h($version) ?></strong> · Branche : <code><?= $h($branch) ?></code> ·
               Git : <?= $git ? '<span class="badge">disponible</span>' : '<span class="badge">indisponible</span>' ?></p>
            <?php if ($git): ?>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="check"><button class="check" type="submit">Vérifier les mises à jour</button></form>
            <?php else: ?><p class="muted">Git n'est pas disponible — vérification/installation impossibles.</p><?php endif; ?>
        </div>

        <?php if ($check !== null): ?>
        <div class="card">
            <h2>Mise à jour disponible</h2>
            <p><?= (int) ($check['behind'] ?? 0) ?> commit(s) en retard sur <code><?= $h($check['branch'] ?? $branch) ?></code>.</p>
            <?php if (!empty($check['commits'])): ?><pre><?php foreach ($check['commits'] as $c) { echo $h($c) . "\n"; } ?></pre><?php endif; ?>
            <?php if ($canApply): ?>
            <form method="post" onsubmit="return confirm('Appliquer la mise à jour ? L\'app passe en maintenance, une sauvegarde est créée, rollback automatique en cas d\'échec.')">
                <?= csrfField() ?><input type="hidden" name="action" value="apply">
                <input class="confirm" name="confirm" placeholder="METTRE A JOUR"> <button class="apply" type="submit">Installer maintenant</button>
            </form>
            <p class="muted">Sauvegarde + maintenance + rollback automatique gérés par UpdateService.</p>
            <?php else: ?><p class="muted">Installation réservée à la permission platform.system.update.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($apply !== null): ?>
        <div class="card">
            <h2>Résultat de l'installation — <?= !empty($apply['success']) ? 'succès' : 'échec' ?></h2>
            <?php if (!empty($apply['steps'])): ?><pre><?php foreach ($apply['steps'] as $s) { echo $h($s) . "\n"; } ?></pre><?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
