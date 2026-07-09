<?php
/**
 * Page de connexion Fronote.
 * Login unifié : aucun sélecteur de profil requis.
 * Flux : credentials → [choix profil si ambiguïté] → [2FA si activé] → accueil
 */
require_once __DIR__ . '/../API/core.php';

// Nonce CSP pour les scripts inline de cette page (durcissement : script-src sans 'unsafe-inline').
$cspNonce = base64_encode(random_bytes(16));

// Security headers
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; font-src cdnjs.cloudflare.com; img-src 'self' data:;");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

$userService = app()->make('API\Services\UserService');
$auth        = app('auth');

// Déjà connecté → accueil
if (isLoggedIn()) {
    redirect('accueil/accueil.php');
}

$error        = '';
$success      = '';
$ambiguous    = []; // Plusieurs comptes pour le même identifiant
$lastUsername = $_SESSION['last_username'] ?? '';
unset($_SESSION['last_username']);

// Messages flash
if (isset($_SESSION['success_message'])) { $success = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message']))   { $error   = $_SESSION['error_message'];   unset($_SESSION['error_message']); }

// Remember-me : tentative de restauration automatique
$_rememberCookieName = 'remember_' . (defined('INSTANCE_ID') ? INSTANCE_ID : 'token');
if (!empty($_COOKIE[$_rememberCookieName])) {
    $remembered = $userService->validateRememberToken($_COOKIE[$_rememberCookieName]);
    if ($remembered) {
        $auth->loginUser($remembered);
        redirect('accueil/accueil.php');
    }
}
unset($_rememberCookieName);

// --- Traitement du formulaire ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    // 1) Vérification CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $rememberMe = !empty($_POST['remember_me']);
        // Type + établissement imposés si l'utilisateur choisit parmi plusieurs comptes ambigus.
        // Format reçu : "<type>|<etablissement_id>" (forced_choice). Le legacy "forced_type"
        // reste accepté pour compat ascendante (mono-établissement).
        $forcedType  = $_POST['forced_type'] ?? null;
        $forcedEtab  = null;
        if (!empty($_POST['forced_choice'])) {
            $parts = explode('|', (string) $_POST['forced_choice'], 2);
            $forcedType = $parts[0] !== '' ? $parts[0] : $forcedType;
            if (isset($parts[1]) && ctype_digit($parts[1])) {
                $forcedEtab = (int) $parts[1];
            }
        }
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (empty($username) || empty($password)) {
            $error = 'Veuillez remplir votre identifiant et votre mot de passe.';
        } else {
            // 2) Rate limiting progressif (par IP et par identifiant)
            $waitMinutes = $userService->checkLoginRateLimit($ip, $username);
            if ($waitMinutes > 0) {
                $error = "Trop de tentatives. Réessayez dans {$waitMinutes} minute(s).";
                // Journaliser + alerter le verrouillage (anti-bruteforce). CRITICAL → alerte temps réel.
                try { app('audit')->logAuth('lockout', $username, false, ['ip' => $ip, 'wait_minutes' => $waitMinutes]); }
                catch (\Throwable $e) { error_log('[login] audit lockout failed: ' . $e->getMessage()); }
            } else {
                // 3) Recherche du compte — type imposé ou multi-type
                $credentials = [
                    'login'    => $username,
                    'password' => $password,
                ];
                if ($forcedType) {
                    $credentials['type'] = $forcedType;
                }
                if ($forcedEtab !== null) {
                    $credentials['etablissement_id'] = $forcedEtab;
                }

                $result = $auth->attemptAndGetUser($credentials);

                if ($result === null) {
                    // Aucun compte trouvé
                    $userService->recordFailedAttempt($ip, $username);
                    $error = 'Identifiant ou mot de passe incorrect.';
                    $_SESSION['last_username'] = $username;
                    try { app('audit')->logAuth('login_failed', (string) $username, false, []); } catch (\Throwable $e) {}

                } elseif (is_array($result) && isset($result[0]) && isset($result[0]['type'])) {
                    // Plusieurs comptes — montrer un sélecteur de profil
                    $ambiguous = $result;
                    // Transmettre le username pour le re-submit
                    $_SESSION['last_username'] = $username;

                } else {
                    // Un seul compte valide → vérifier 2FA
                    $user = $result;

                    $twoFactor   = new \API\Services\TwoFactorService(getPDO());
                    $twoFAActive = $twoFactor->isEnabled((int)$user['id'], $user['type']);

                    if ($twoFAActive) {
                        // Stocker l'état pending 2FA et rediriger
                        $_SESSION['pending_2fa'] = [
                            'user_id'    => $user['id'],
                            'user_type'  => $user['type'],
                            'remember_me' => $rememberMe,
                        ];
                        redirect('login/verify_2fa.php');
                    } else {
                        // Pas de 2FA → créer la session directement
                        $auth->loginUser($user);
                        try { app('audit')->logAuth('login', $user['type'] . ':' . $user['id'], true, []); } catch (\Throwable $e) {}

                        if ($rememberMe) {
                            $userService->createRememberToken((int)$user['id'], $user['type']);
                        }

                        // Forcer le changement de mot de passe si jamais changé.
                        // Le garde (API/module_boot.php) ré-imposera la redirection tant
                        // que ce drapeau est armé ; change_password.php agit sur le compte
                        // authentifié courant (plus de reset_user_id/reset_code self-service).
                        $fullUser = $userService->findById((int)$user['id'], $user['type']);
                        if ($fullUser && empty($fullUser['password_changed_at'])) {
                            $_SESSION['force_password_change'] = true;
                            redirect('login/change_password.php');
                        }

                        $userService->cleanOldAttempts();
                        redirect('accueil/accueil.php');
                    }
                }
            }
        }
    }
}

$csrfToken = generateCSRFToken();

// i18n — load translator for login page
$translator = app('translator');
$currentLocale = $translator->getLocale();
$supportedLocales = $translator->getSupportedLocales();
$localeNames = \API\Services\TranslationService::getLocaleNames();
$localeFlags = [
    'fr' => '🇫🇷', 'en' => '🇬🇧', 'es' => '🇪🇸', 'de' => '🇩🇪',
    'ru' => '🇷🇺', 'nl' => '🇳🇱', 'ar' => '🇸🇦', 'th' => '🇹🇭',
];

$profilLabels = [
    'administrateur' => ['label' => 'Administrateur', 'icon' => 'fa-user-shield'],
    'vie_scolaire'   => ['label' => 'Vie scolaire',   'icon' => 'fa-user-tie'],
    'professeur'     => ['label' => 'Professeur',     'icon' => 'fa-chalkboard-teacher'],
    'eleve'          => ['label' => 'Élève',           'icon' => 'fa-user-graduate'],
    'parent'         => ['label' => 'Parent',          'icon' => 'fa-users'],
];

$_loginDir = $translator->isRtl() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>" dir="<?= $_loginDir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('login.title')) ?></title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script nonce="<?= $cspNonce ?>">
    // Appliquer le thème système immédiatement
    (function() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>
    <style>
    .lang-selector { position: absolute; top: 16px; right: 16px; z-index: 10; }
    .lang-selector .lang-toggle { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 6px 12px; cursor: pointer; color: inherit; font-size: 14px; display: flex; align-items: center; gap: 6px; transition: background 0.2s; }
    .lang-selector .lang-toggle:hover { background: rgba(255,255,255,0.2); }
    .lang-selector .lang-dropdown { display: none; position: absolute; top: calc(100% + 4px); right: 0; background: var(--bg-white, #fff); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.15); min-width: 180px; overflow: hidden; z-index: 20; }
    .lang-selector.open .lang-dropdown { display: block; }
    .lang-dropdown a { display: flex; align-items: center; gap: 8px; padding: 10px 14px; text-decoration: none; color: #333; font-size: 13px; transition: background 0.15s; }
    .lang-dropdown a:hover { background: #f0f0f0; }
    .lang-dropdown a.active { background: #667eea; color: #fff; }
    [data-theme="dark"] .lang-dropdown { background: #1e1e2e; }
    [data-theme="dark"] .lang-dropdown a { color: #cdd6f4; }
    [data-theme="dark"] .lang-dropdown a:hover { background: #313244; }
    [data-theme="dark"] .lang-dropdown a.active { background: #667eea; color: #fff; }
    [dir="rtl"] .lang-selector { right: auto; left: 16px; }
    [dir="rtl"] .lang-dropdown { right: auto; left: 0; }
    </style>
</head>
<body>
    <div class="lang-selector" id="langSelector">
        <button type="button" class="lang-toggle" id="langToggle">
            <i class="fas fa-globe"></i>
            <span><?= $localeFlags[$currentLocale] ?? '' ?> <?= strtoupper($currentLocale) ?></span>
            <i class="fas fa-chevron-down" style="font-size:10px;opacity:0.7;"></i>
        </button>
        <div class="lang-dropdown">
            <?php foreach ($supportedLocales as $loc): ?>
            <a href="?lang=<?= htmlspecialchars($loc) ?>" class="<?= $loc === $currentLocale ? 'active' : '' ?>">
                <span><?= $localeFlags[$loc] ?? '' ?></span>
                <span><?= htmlspecialchars($localeNames[$loc] ?? $loc) ?></span>
                <span style="margin-left:auto;opacity:0.5;font-size:11px;"><?= strtoupper($loc) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="auth-container">
        <div class="auth-header">
            <div class="app-logo">F</div>
            <h1 class="app-title">FRONOTE</h1>
            <p class="app-subtitle"><?= htmlspecialchars(__('login.subtitle')) ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert" aria-live="assertive">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="status" aria-live="polite">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($ambiguous)): ?>
            <!-- Plusieurs comptes pour le même identifiant → choix du profil (type + établissement) -->
            <?php
            // Quand plusieurs candidats existent dans des établissements distincts, on doit
            // exposer le nom de l'établissement pour que l'utilisateur puisse trancher.
            $etabIdsInAmbig = array_unique(array_map(static fn ($c) => (int) ($c['etablissement_id'] ?? 0), $ambiguous));
            $etabNames = [];
            if (count($etabIdsInAmbig) > 1) {
                try {
                    $in = implode(',', array_fill(0, count($etabIdsInAmbig), '?'));
                    $st = getPDO()->prepare("SELECT id, nom FROM etablissements WHERE id IN ({$in})");
                    $st->execute(array_values($etabIdsInAmbig));
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $etabNames[(int) $row['id']] = $row['nom'];
                    }
                } catch (\Throwable $e) { /* table absente sur très anciennes installs : ignore */ }
            }
            ?>
            <div class="alert alert-info" role="alert">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                <div><?= htmlspecialchars(__('login.multiple_profiles')) ?></div>
            </div>
            <form method="post" action="" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="login" value="1">
                <input type="hidden" name="username" value="<?= htmlspecialchars($lastUsername) ?>">
                <input type="hidden" name="password" value="">
                <div class="profile-selector" role="radiogroup" aria-label="Choisir votre profil">
                    <?php foreach ($ambiguous as $idx => $candidate): ?>
                        <?php
                        $lbl = $profilLabels[$candidate['type']] ?? ['label' => ucfirst($candidate['type']), 'icon' => 'fa-user'];
                        $etabId = (int) ($candidate['etablissement_id'] ?? 0);
                        $etabLabel = (count($etabIdsInAmbig) > 1 && isset($etabNames[$etabId]))
                            ? ' — ' . $etabNames[$etabId]
                            : '';
                        $radioId = 'cand_' . $idx;
                        $value = $candidate['type'] . '|' . $etabId;
                        ?>
                        <input type="radio" id="<?= htmlspecialchars($radioId) ?>"
                               name="forced_choice" value="<?= htmlspecialchars($value) ?>" required>
                        <label for="<?= htmlspecialchars($radioId) ?>" class="profile-option">
                            <div class="profile-icon"><i class="fas <?= $lbl['icon'] ?>" aria-hidden="true"></i></div>
                            <div class="profile-label"><?= htmlspecialchars($lbl['label'] . $etabLabel) ?></div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="help-text"><?= htmlspecialchars(__('login.confirm_password')) ?></p>
                <div class="form-group">
                    <label for="password2" class="required-field"><?= htmlspecialchars(__('login.password_label')) ?></label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password2" name="password" class="form-control input-with-icon"
                               required autocomplete="current-password">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="loginBtn">
                        <span class="btn-text"><i class="fas fa-sign-in-alt" aria-hidden="true"></i> <?= htmlspecialchars(__('btn.confirm')) ?></span>
                    </button>
                </div>
                <div class="help-links">
                    <a href="index.php"><?= htmlspecialchars(__('btn.back')) ?></a>
                </div>
            </form>
        <?php else: ?>
            <!-- Formulaire principal -->
            <form method="post" action="" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="login" value="1">

                <div class="form-group">
                    <label for="username" class="required-field"><?= htmlspecialchars(__('login.username_label')) ?></label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-user" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" class="form-control input-with-icon"
                               value="<?= htmlspecialchars($lastUsername) ?>" required autofocus
                               autocomplete="username" placeholder="<?= htmlspecialchars(__('login.username_placeholder')) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="required-field"><?= htmlspecialchars(__('login.password_label')) ?></label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" class="form-control input-with-icon"
                               required autocomplete="current-password">
                        <button type="button" class="visibility-toggle" aria-label="<?= htmlspecialchars(__('login.toggle_password')) ?>">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-group">
                        <input type="checkbox" name="remember_me" id="remember_me">
                        <span><?= htmlspecialchars(__('login.remember_me')) ?></span>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="loginBtn">
                        <span class="btn-text"><i class="fas fa-sign-in-alt" aria-hidden="true"></i> <?= htmlspecialchars(__('login.submit')) ?></span>
                        <span class="btn-loading-text" style="display:none;"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> <?= htmlspecialchars(__('login.loading')) ?></span>
                    </button>
                </div>

                <div class="help-links">
                    <a href="reset_password.php"><?= htmlspecialchars(__('login.forgot_password')) ?></a>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script nonce="<?= $cspNonce ?>">
    // Close lang dropdown on outside click
    document.addEventListener('click', function(e) {
        var sel = document.getElementById('langSelector');
        if (sel && !sel.contains(e.target)) sel.classList.remove('open');
    });
    // Ouverture du sélecteur de langue (ex-onclick inline, retiré pour la CSP nonce).
    var langToggle = document.getElementById('langToggle');
    if (langToggle) langToggle.addEventListener('click', function() {
        var sel = document.getElementById('langSelector');
        if (sel) sel.classList.toggle('open');
    });
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        var toggle = document.querySelector('.visibility-toggle');
        var pwInput = document.getElementById('password');
        if (toggle && pwInput) {
            toggle.addEventListener('click', function() {
                var type = pwInput.type === 'password' ? 'text' : 'password';
                pwInput.type = type;
                toggle.querySelector('i').classList.toggle('fa-eye');
                toggle.querySelector('i').classList.toggle('fa-eye-slash');
            });
        }

        // Auto-focus password if username filled
        var usernameInput = document.getElementById('username');
        if (usernameInput && usernameInput.value.trim()) {
            if (pwInput) pwInput.focus();
        }

        // Loading state on submit
        var form = document.getElementById('loginForm');
        var btn  = document.getElementById('loginBtn');
        if (form && btn) {
            form.addEventListener('submit', function() {
                btn.disabled = true;
                var txt  = btn.querySelector('.btn-text');
                var load = btn.querySelector('.btn-loading-text');
                if (txt)  txt.style.display  = 'none';
                if (load) load.style.display = 'inline';
            });
        }
    });
    </script>
</body>
</html>
