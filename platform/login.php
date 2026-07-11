<?php
declare(strict_types=1);
/**
 * Portail PLATEFORME — connexion (réservé à l'équipe interne Fronote).
 * Garde : authentifie contre platform_accounts (jamais les comptes établissement).
 * N'utilise PAS le header/menu établissement (mondes séparés, cf. §8.1).
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\PlatformAccountService;

$base = defined('BASE_URL') ? BASE_URL : '';

if (!empty($_SESSION['platform']['account_id'])) {
    header("Location: {$base}/platform/dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $login    = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $acc = (new PlatformAccountService(getPDO()))->findActiveByLogin($login);
        if ($acc && !empty($acc['password_hash']) && password_verify($password, $acc['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['platform'] = ['account_id' => (int) $acc['id'], 'username' => $acc['username']];
            unset($_SESSION['user'], $_SESSION['tenant']); // jamais de session établissement en parallèle
            // Ancre le cycle de vie de session (idle/absolu + révocation) — cf. bootstrap 3-mondes.
            $_SESSION['last_activity'] = time();
            $_SESSION['session_started'] = time();
            \API\Auth\SessionGuard::recordActiveSession('platform', (int) $acc['id']);
            try { getPDO()->prepare("UPDATE platform_accounts SET last_login_at = NOW() WHERE id = ?")->execute([(int) $acc['id']]); }
            catch (\Throwable $e) { error_log('[platform login] ' . $e->getMessage()); }
            header("Location: {$base}/platform/dashboard.php");
            exit;
        }
        $error = 'Identifiants invalides.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fronote — Plateforme</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #1e293b; padding: 32px; border-radius: 12px; width: 320px; box-shadow: 0 8px 30px rgba(0,0,0,.4); }
        h1 { font-size: 1.2rem; margin: 0 0 4px; } .sub { color: #94a3b8; font-size: .85rem; margin-bottom: 20px; }
        input { width: 100%; box-sizing: border-box; padding: 10px; margin: 6px 0; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; }
        button { width: 100%; padding: 10px; margin-top: 12px; border: 0; border-radius: 8px; background: #3b82f6; color: #fff; font-weight: 600; cursor: pointer; }
        .err { background: #450a0a; color: #fca5a5; padding: 8px; border-radius: 8px; font-size: .85rem; }
    </style>
</head>
<body>
    <form class="card" method="post">
        <h1>Plateforme Fronote</h1>
        <div class="sub">Espace interne — équipe Fronote</div>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?= csrfField() ?>
        <input name="login" placeholder="Email ou identifiant" autocomplete="username" required autofocus>
        <input name="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required>
        <button type="submit">Connexion</button>
    </form>
</body>
</html>
