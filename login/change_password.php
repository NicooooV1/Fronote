<?php
/**
 * Changement de mot de passe — Version refactorisée.
 * +CSRF, +password-strength.js externe, +countdown JS, -header("refresh").
 */
require_once __DIR__ . '/../API/core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cette page sert UNIQUEMENT le changement de mot de passe imposé au premier login :
// l'utilisateur est authentifié et $_SESSION['force_password_change'] est armé
// (cf. login/index.php & login/verify_2fa.php). La réinitialisation self-service par
// code à usage unique n'est pas émise (parcours admin-validé) : on ne s'appuie donc
// plus sur reset_user_id/reset_code côté self-service, qui était un chemin mort.
$forcedChange = isLoggedIn() && !empty($_SESSION['force_password_change']);

if (!$forcedChange) {
    // Aucun changement imposé en cours → rien à faire ici.
    if (isLoggedIn()) {
        header('Location: ../accueil/accueil.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// Cible du changement : l'utilisateur authentifié lui-même (jamais une valeur de
// session arbitraire) → impossible de viser un autre compte.
$currentUser   = getCurrentUser();
$targetUserId  = (int) ($currentUser['id'] ?? 0);
$targetType    = $currentUser['type'] ?? $currentUser['profil'] ?? null;

$error   = '';
$success = false;

// Charger la politique de mot de passe (instance configurée via le binding)
$passwordPolicy = app('password_policy');
$passwordRules  = $passwordPolicy->getRules();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Vérification CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($password) || empty($confirmPassword)) {
            $error = 'Veuillez remplir tous les champs.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            // Validation via PasswordPolicy centralisée
            $policyResult = $passwordPolicy->validate($password);
            if (!$policyResult['valid']) {
                $error = implode('<br>', $policyResult['errors']);
            } else {
                // SÉCURITÉ : transmettre le type d'utilisateur. Sans lui, UserService
                // itère toutes les tables et écrase le mot de passe du PREMIER id trouvé
                // (collision d'id inter-tables → prise de contrôle d'un compte tiers).
                // La cible est toujours l'utilisateur authentifié courant.
                $changeResult = changePassword(
                    $targetUserId,
                    $password,
                    $targetType
                );

                if ($changeResult['success']) {
                    $success = true;
                    // Lever le garde : le mot de passe n'est plus « jamais changé ».
                    unset(
                        $_SESSION['force_password_change'],
                        $_SESSION['reset_user_id'],
                        $_SESSION['reset_user_type'],
                        $_SESSION['reset_code'],
                        $_SESSION['reset_username']
                    );
                    $_SESSION['success_message'] = 'Votre mot de passe a été modifié avec succès.';
                } else {
                    $error = $changeResult['message'];
                }
            }
        }
    }
}

$csrfToken = generateCSRFToken();
$username  = $currentUser['identifiant']
    ?? (trim(($currentUser['prenom'] ?? '') . ' ' . ($currentUser['nom'] ?? '')) ?: 'votre compte');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changer de mot de passe - FRONOTE</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">FRONOTE</h1>
            <p class="app-subtitle">Nouveau mot de passe</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert" aria-live="assertive">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status" aria-live="polite">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <div>
                    <p>Votre mot de passe a été modifié avec succès !</p>
                    <p class="countdown" aria-live="polite">Redirection dans <span id="timer">5</span> secondes…</p>
                </div>
            </div>

            <script>
            (function() {
                var seconds = 5;
                var timerEl = document.getElementById('timer');
                var interval = setInterval(function() {
                    seconds--;
                    if (timerEl) timerEl.textContent = seconds;
                    if (seconds <= 0) {
                        clearInterval(interval);
                        window.location.href = '../accueil/accueil.php';
                    }
                }, 1000);
            })();
            </script>
        <?php else: ?>
            <form method="post" action="" id="changePasswordForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <div>
                        <p>Définissez un nouveau mot de passe pour le compte <strong><?= htmlspecialchars($username) ?></strong>.</p>
                        <ul style="margin: 8px 0 0 16px; font-size: 0.9em;">
                            <?php foreach ($passwordRules as $rule): ?>
                                <li><?= htmlspecialchars($rule) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="required-field">Nouveau mot de passe</label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" class="form-control input-with-icon"
                               required autofocus autocomplete="new-password">
                        <button type="button" class="visibility-toggle" aria-label="Afficher ou masquer le mot de passe">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="password-strength-meter">
                        <div class="strength-indicator" id="strength-indicator"></div>
                    </div>
                    <div class="password-strength-text" id="strength-text"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="required-field">Confirmer le mot de passe</label>
                    <div class="input-group">
                        <i class="input-group-icon fas fa-lock" aria-hidden="true"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control input-with-icon"
                               required autocomplete="new-password">
                        <button type="button" class="visibility-toggle" aria-label="Afficher ou masquer le mot de passe">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn btn-primary" id="submitBtn">
                        <span class="btn-text"><i class="fas fa-save" aria-hidden="true"></i> Changer le mot de passe</span>
                    </button>
                </div>
            </form>

            <script src="assets/js/password-strength.js"></script>
        <?php endif; ?>
    </div>
</body>
</html>
