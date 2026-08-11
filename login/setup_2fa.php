<?php
declare(strict_types=1);
/**
 * Enrôlement 2FA FORCÉ (rôle à responsabilité sans second facteur configuré).
 * Prérequis : $_SESSION['pending_2fa'] avec 'enroll' = true.
 */
require_once __DIR__ . '/../API/core.php';

$cspNonce = base64_encode(random_bytes(16));
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

if (isLoggedIn()) { redirect('accueil/accueil.php'); }
if (empty($_SESSION['pending_2fa'])) { redirect('login/index.php'); }

$pending    = $_SESSION['pending_2fa'];
$userId     = (int) $pending['user_id'];
$userType   = (string) $pending['user_type'];
$rememberMe = $pending['remember_me'] ?? false;

$userService = app()->make('API\Services\UserService');
$auth        = app('auth');
$twoFactor   = new \API\Services\TwoFactorService(getPDO());

// Déjà configuré (ex. deux onglets) → basculer vers la vérification.
if ($twoFactor->isEnabled($userId, $userType)) { redirect('login/verify_2fa.php'); }

// Secret persistant en session tant que l'enrôlement n'est pas confirmé.
if (empty($_SESSION['enroll_secret'])) { $_SESSION['enroll_secret'] = $twoFactor->generateSecret(); }
$secret  = (string) $_SESSION['enroll_secret'];
$otpauth = $twoFactor->getOtpauthUri($secret, $userType . ':' . $userId);

$error = '';
$backupCodes = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = __('login.error.csrf');
    } elseif (isset($_POST['cancel'])) {
        unset($_SESSION['pending_2fa'], $_SESSION['enroll_secret']);
        redirect('login/index.php');
    } else {
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (strlen($code) !== 6 || !$twoFactor->verifyCode($secret, $code)) {
            $error = 'Code invalide. Vérifiez que l\'heure de votre téléphone est synchronisée puis réessayez.';
        } elseif (!$twoFactor->enable($userId, $userType, $secret, $code)) {
            $error = 'Impossible d\'activer le 2FA. Réessayez.';
        } else {
            // Activé : générer les codes de secours, ouvrir la session, poser la confiance 1h.
            $backupCodes = $twoFactor->generateBackupCodes($userId, $userType);
            unset($_SESSION['pending_2fa'], $_SESSION['enroll_secret']);
            $user = $userService->findById($userId, $userType);
            if ($user) {
                $auth->loginUser($user);
                \API\Security\TwoFactorTrust::grant($userId, $userType);
                if ($rememberMe) { $userService->createRememberToken($userId, $userType); }
                try { app('audit')->logAuth('2fa_enrolled', $userType . ':' . $userId, true, []); } catch (\Throwable $e) {}
                $done = true; // afficher les codes de secours puis continuer
            } else {
                $error = 'Compte introuvable.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();
$secretGroups = trim(chunk_split($secret, 4, ' '));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activation 2FA - FRONOTE</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="../assets/lib/fontawesome/css/all.min.css">
    <script nonce="<?= $cspNonce ?>">(function(){if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches){document.documentElement.setAttribute('data-theme','dark');}})();</script>
    <style nonce="<?= $cspNonce ?>">
      .kv-secret{font-family:ui-monospace,monospace;font-size:1.25rem;letter-spacing:.15em;background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.35);border-radius:10px;padding:.7rem 1rem;text-align:center;user-select:all;word-break:break-all}
      .otpauth{font-family:ui-monospace,monospace;font-size:.72rem;color:var(--text-muted,#6b7280);word-break:break-all;margin-top:.5rem}
      .backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;font-family:ui-monospace,monospace;font-size:1rem;margin:.75rem 0}
      .backup-grid span{background:rgba(0,0,0,.04);border-radius:8px;padding:.45rem;text-align:center;user-select:all}
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="app-logo" style="background:#7c3aed"><i class="fas fa-shield-halved" style="font-size:1.4rem"></i></div>
            <h1 class="app-title">FRONOTE</h1>
            <p class="app-subtitle"><?= $done ? 'Authentification à deux facteurs activée' : 'Sécurisez votre compte' ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert" aria-live="assertive"><i class="fas fa-exclamation-circle"></i><div><?= htmlspecialchars($error) ?></div></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert alert-success" role="status"><i class="fas fa-check-circle"></i><div>2FA activée. Conservez vos <strong>codes de secours</strong> : ils permettent de vous connecter si vous perdez votre téléphone. Ils ne seront plus affichés.</div></div>
            <div class="backup-grid">
                <?php foreach ($backupCodes as $bc): ?><span><?= htmlspecialchars($bc) ?></span><?php endforeach; ?>
            </div>
            <div class="form-actions">
                <a href="<?= htmlspecialchars((defined('BASE_URL') && BASE_URL ? BASE_URL : '') . '/accueil/accueil.php') ?>" class="btn btn-primary"><i class="fas fa-arrow-right"></i> J'ai noté mes codes, continuer</a>
            </div>
        <?php else: ?>
            <div class="alert alert-info" role="note" style="margin-bottom:1.25rem"><i class="fas fa-lock"></i><div>Votre rôle donne accès à des données : l'authentification à deux facteurs est <strong>obligatoire</strong>. Elle vous sera redemandée au plus une fois par heure et par appareil.</div></div>
            <p class="help-text" style="margin-bottom:.4rem">1. Ajoutez ce compte dans votre application (Google Authenticator, Authy…) via cette clé :</p>
            <div class="kv-secret"><?= htmlspecialchars($secretGroups) ?></div>
            <details style="margin:.4rem 0 1rem"><summary style="cursor:pointer;color:var(--text-muted,#6b7280);font-size:.85rem">Lien otpauth (avancé)</summary><div class="otpauth"><?= htmlspecialchars($otpauth) ?></div></details>
            <form method="post" action="" id="setupForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="form-group">
                    <label for="code" class="required-field">2. Saisissez le code à 6 chiffres affiché</label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-key"></i>
                        <input type="text" id="code" name="code" class="form-control input-with-icon" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus autocomplete="one-time-code" style="letter-spacing:.3em;font-size:1.4rem;text-align:center">
                    </div>
                </div>
                <div class="form-actions" style="gap:.6rem;display:flex;flex-direction:column">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle"></i> Activer et se connecter</button>
                    <button type="submit" name="cancel" value="1" formnovalidate class="btn btn-secondary" style="background:transparent;border:1px solid currentColor;color:var(--text-muted,#6b7280)"><i class="fas fa-arrow-left"></i> Annuler</button>
                </div>
            </form>
            <script nonce="<?= $cspNonce ?>">
            document.getElementById('code').addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,6);});
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
