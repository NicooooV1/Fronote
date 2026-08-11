<?php
declare(strict_types=1);
/** Portail ÉTABLISSEMENT — connexion (authentifie contre tenant_accounts + appartenance). */
require __DIR__ . '/_bootstrap.php';

use API\Tenant\TenantContext;
use API\Platform\PlatformAccountService;

if (!empty($_SESSION['tenant']['membership_id']) && (int) $_SESSION['tenant']['establishment_id'] === (int) $establishment['id']) {
    header("Location: {$base}/tenant/dashboard.php?e=" . urlencode($slug));
    exit;
}

/**
 * Établit la session établissement complète (legacy shim + session tenant) et redirige
 * vers le tableau de bord. Point de passage UNIQUE (login direct sans 2FA ET après 2FA)
 * pour ne pas dupliquer la logique de session.
 */
$establishTenantSession = function (array $establishment, string $base, string $slug,
    int $accountId, string $username, string $legacyType, int $legacyId, int $membershipId): void {

    unset($_SESSION['platform']); // pas de session plateforme en parallèle

    // Shim de compatibilité : établit AUSSI la session legacy à partir de l'identité
    // d'origine du compte tenant, pour que les modules existants (qui lisent
    // $_SESSION['user'] via app('auth')) fonctionnent sous le login établissement.
    // loginUser() régénère l'ID de session et pose user_id/user_type/user/etablissement_id.
    $legacyEstablished = false;
    if ($legacyType !== '' && $legacyId > 0) {
        try {
            $legacy = app('auth.provider')->retrieveById($legacyId, $legacyType);
            if ($legacy) { app('auth')->loginUser($legacy); $legacyEstablished = true; }
        } catch (\Throwable $e) { error_log('[tenant login shim] ' . $e->getMessage()); }
    }
    if (!$legacyEstablished) {
        session_regenerate_id(true);
        unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_type']);
    }

    $_SESSION['tenant'] = [
        'membership_id'    => $membershipId,
        'establishment_id' => (int) $establishment['id'],
        'account_id'       => $accountId,
        'username'         => $username,
        'slug'             => $slug,
    ];
    // Le contexte établissement suit l'établissement SÉLECTIONNÉ (correct en multi-établissement).
    $_SESSION['etablissement_id'] = (int) $establishment['id'];
    try { \API\Core\EstablishmentContext::set((int) $establishment['id']); } catch (\Throwable $e) {}

    // Ancre le cycle de vie de session (idle/absolu + révocation) — cf. bootstrap 3-mondes.
    $_SESSION['last_activity'] = time();
    $_SESSION['session_started'] = time();
    // Le chemin legacy (loginUser) a déjà écrit session_security ; sinon on l'enregistre en tant que tenant.
    if (!$legacyEstablished) {
        \API\Auth\SessionGuard::recordActiveSession('tenant', $accountId);
    }

    try { getPDO()->prepare("UPDATE tenant_accounts SET last_login_at = NOW() WHERE id = ?")->execute([$accountId]); }
    catch (\Throwable $e) { error_log('[tenant login] ' . $e->getMessage()); }
    header("Location: {$base}/tenant/dashboard.php?e=" . urlencode($slug));
    exit;
};

$error   = '';
$show2fa = false; // bascule l'affichage vers le formulaire du second facteur

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $error = 'Session expirée, veuillez réessayer.';
        // Si l'on était au milieu de l'étape 2FA, rester sur ce formulaire.
        $show2fa = !empty($_SESSION['tenant_pending_2fa'])
            && (int) ($_SESSION['tenant_pending_2fa']['establishment_id'] ?? 0) === (int) $establishment['id'];
    } elseif (!empty($_SESSION['tenant_pending_2fa'])
        && (int) ($_SESSION['tenant_pending_2fa']['establishment_id'] ?? 0) === (int) $establishment['id']) {
        // ─── Étape 2 : vérification du second facteur (2FA activé sur l'identité applicative) ───
        $show2fa = true;
        $pending = $_SESSION['tenant_pending_2fa'];
        if (isset($_POST['cancel'])) {
            unset($_SESSION['tenant_pending_2fa'], $_SESSION['tenant_twofa_fails']);
            header("Location: {$base}/tenant/login.php?e=" . urlencode($slug));
            exit;
        }
        $legType     = (string) $pending['legacy_type'];
        $legId       = (int) $pending['legacy_id'];
        $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userService = app()->make('API\Services\UserService');
        $twoFactor   = new \API\Services\TwoFactorService(getPDO());
        // Anti-brute-force du 2e facteur — PERSISTANT (login_attempts) + compteur de session,
        // calqué sur login/verify_2fa.php (clé propre au compte 2fa:type:id).
        $twofaKey = '2fa:' . $legType . ':' . $legId;
        if ($userService->checkLoginRateLimit($ip, $twofaKey) > 0
            || (int) ($_SESSION['tenant_twofa_fails'] ?? 0) >= 5) {
            unset($_SESSION['tenant_pending_2fa'], $_SESSION['tenant_twofa_fails']);
            $show2fa = false;
            $error = 'Trop de tentatives. Veuillez vous reconnecter plus tard.';
        } else {
            $backupCode  = trim((string) ($_POST['backup_code'] ?? ''));
            $usingBackup = $backupCode !== '';
            $valid       = false;
            if ($usingBackup) {
                $valid = $twoFactor->verifyBackupCode($legId, $legType, $backupCode);
            } else {
                $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
                if (strlen($code) === 6) {
                    $valid = $twoFactor->validateLogin($legId, $legType, $code);
                }
            }
            if (!$valid) {
                $userService->recordFailedAttempt($ip, $twofaKey);
                $_SESSION['tenant_twofa_fails'] = ((int) ($_SESSION['tenant_twofa_fails'] ?? 0)) + 1;
                try { app('audit')->logAuth('2fa_failed', (string) $legId, false, ['user_type' => $legType, 'scope' => 'tenant']); } catch (\Throwable $e) {}
                $error = 'Code de vérification invalide.';
            } else {
                unset($_SESSION['tenant_pending_2fa'], $_SESSION['tenant_twofa_fails']);
                try { app('audit')->logAuth('login', $legType . ':' . $legId, true, ['2fa' => true, 'scope' => 'tenant']); } catch (\Throwable $e) {}
                $establishTenantSession($establishment, $base, $slug,
                    (int) $pending['account_id'], (string) $pending['username'], $legType, $legId, (int) $pending['membership_id']);
            }
        }
    } else {
        // ─── Étape 1 : identifiants (logique de TenantContext::attemptLogin inlinée pour
        //     insérer le path constant-time sur identifiant inconnu + le portail 2FA) ───
        $login    = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (in_array($establishment['status'] ?? 'active', ['suspended'], true)) {
            $error = 'Établissement suspendu — connexions bloquées.';
        } else {
            $account = TenantContext::findAccountByLogin(getPDO(), $login);
            if (!$account || empty($account['password_hash']) || !password_verify($password, (string) $account['password_hash'])) {
                // Identifiant inconnu : vérification bcrypt factice (constant-time) pour ne pas
                // révéler l'existence du compte par le temps de réponse (anti-énumération).
                if (!$account) {
                    PlatformAccountService::dummyVerify();
                }
                $error = 'Identifiants invalides.';
            } else {
                $membership = TenantContext::membershipFor(getPDO(), (int) $establishment['id'], (int) $account['id']);
                if (!$membership) {
                    $error = "Vous n'avez pas accès à cet établissement.";
                } else {
                    // Porte 2FA : si l'identité applicative a le second facteur activé, on
                    // n'établit PAS la session complète avant validation du code (comme login/index.php).
                    $legType = (string) ($account['legacy_type'] ?? '');
                    $legId   = (int) ($account['legacy_id'] ?? 0);
                    $needs2fa = false;
                    if ($legType !== '' && $legId > 0) {
                        try { $needs2fa = (new \API\Services\TwoFactorService(getPDO()))->isEnabled($legId, $legType); }
                        catch (\Throwable $e) { $needs2fa = false; }
                    }
                    if ($needs2fa) {
                        $_SESSION['tenant_pending_2fa'] = [
                            'account_id'       => (int) $account['id'],
                            'membership_id'    => (int) $membership['id'],
                            'establishment_id' => (int) $establishment['id'],
                            'slug'             => $slug,
                            'username'         => (string) $account['username'],
                            'legacy_type'      => $legType,
                            'legacy_id'        => $legId,
                        ];
                        unset($_SESSION['tenant_twofa_fails']);
                        $show2fa = true;
                    } else {
                        $establishTenantSession($establishment, $base, $slug,
                            (int) $account['id'], (string) $account['username'], $legType, $legId, (int) $membership['id']);
                    }
                }
            }
        }
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
    <?php if ($show2fa): ?>
    <form class="card" method="post">
        <h1><?= htmlspecialchars($establishment['nom']) ?></h1>
        <div class="sub">Vérification en deux étapes</div>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?= csrfField() ?>
        <p class="sub">Saisissez le code à 6 chiffres de votre application d'authentification, ou un code de secours.</p>
        <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Code à 6 chiffres" autocomplete="one-time-code" autofocus style="letter-spacing:.2em; text-align:center;">
        <input name="backup_code" placeholder="Code de secours (facultatif)" maxlength="11" autocomplete="off" style="text-transform:uppercase;">
        <button type="submit">Vérifier</button>
        <button type="submit" name="cancel" value="1" formnovalidate style="background:transparent; color:#64748b; border:1px solid #cbd5e1; margin-top:8px;">Retour</button>
    </form>
    <?php else: ?>
    <form class="card" method="post">
        <h1><?= htmlspecialchars($establishment['nom']) ?></h1>
        <div class="sub">Espace établissement</div>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?= csrfField() ?>
        <input name="login" placeholder="Identifiant ou email" autocomplete="username" required autofocus>
        <input name="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required>
        <button type="submit">Connexion</button>
    </form>
    <?php endif; ?>
</body>
</html>
