<?php
declare(strict_types=1);
/** Portail ÉTABLISSEMENT — connexion (authentifie contre tenant_accounts + appartenance). */
require __DIR__ . '/_bootstrap.php';

use API\Tenant\TenantContext;

if (!empty($_SESSION['tenant']['membership_id']) && (int) $_SESSION['tenant']['establishment_id'] === (int) $establishment['id']) {
    header("Location: {$base}/tenant/dashboard.php?e=" . urlencode($slug));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $res = TenantContext::attemptLogin(getPDO(), $establishment,
            trim((string) ($_POST['login'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($res['ok']) {
            $acc = $res['account'];
            unset($_SESSION['platform']); // pas de session plateforme en parallèle

            // Shim de compatibilité : établit AUSSI la session legacy à partir de l'identité
            // d'origine du compte tenant, pour que les modules existants (qui lisent
            // $_SESSION['user'] via app('auth')) fonctionnent sous le login établissement.
            // loginUser() régénère l'ID de session et pose user_id/user_type/user/etablissement_id.
            $legacyEstablished = false;
            if (!empty($acc['legacy_type']) && !empty($acc['legacy_id'])) {
                try {
                    $legacy = app('auth.provider')->retrieveById((int) $acc['legacy_id'], (string) $acc['legacy_type']);
                    if ($legacy) { app('auth')->loginUser($legacy); $legacyEstablished = true; }
                } catch (\Throwable $e) { error_log('[tenant login shim] ' . $e->getMessage()); }
            }
            if (!$legacyEstablished) {
                session_regenerate_id(true);
                unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_type']);
            }

            $_SESSION['tenant'] = [
                'membership_id'    => (int) $res['membership']['id'],
                'establishment_id' => (int) $establishment['id'],
                'account_id'       => (int) $acc['id'],
                'username'         => $acc['username'],
                'slug'             => $slug,
            ];
            // Le contexte établissement suit l'établissement SÉLECTIONNÉ (correct en multi-établissement).
            $_SESSION['etablissement_id'] = (int) $establishment['id'];
            try { \API\Core\EstablishmentContext::set((int) $establishment['id']); } catch (\Throwable $e) {}

            try { getPDO()->prepare("UPDATE tenant_accounts SET last_login_at = NOW() WHERE id = ?")->execute([(int) $acc['id']]); }
            catch (\Throwable $e) { error_log('[tenant login] ' . $e->getMessage()); }
            header("Location: {$base}/tenant/dashboard.php?e=" . urlencode($slug));
            exit;
        }
        $error = $res['reason'] ?? 'Connexion impossible.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($establishment['nom']) ?> — Connexion</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f1f5f9; color: #1e293b; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; padding: 32px; border-radius: 12px; width: 320px; box-shadow: 0 8px 30px rgba(0,0,0,.12); }
        h1 { font-size: 1.15rem; margin: 0 0 4px; } .sub { color: #64748b; font-size: .85rem; margin-bottom: 20px; }
        input { width: 100%; box-sizing: border-box; padding: 10px; margin: 6px 0; border-radius: 8px; border: 1px solid #cbd5e1; }
        button { width: 100%; padding: 10px; margin-top: 12px; border: 0; border-radius: 8px; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
        .err { background: #fee2e2; color: #b91c1c; padding: 8px; border-radius: 8px; font-size: .85rem; }
    </style>
</head>
<body>
    <form class="card" method="post">
        <h1><?= htmlspecialchars($establishment['nom']) ?></h1>
        <div class="sub">Espace établissement</div>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?= csrfField() ?>
        <input name="login" placeholder="Identifiant ou email" autocomplete="username" required autofocus>
        <input name="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required>
        <button type="submit">Connexion</button>
    </form>
</body>
</html>
