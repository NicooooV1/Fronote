<?php
declare(strict_types=1);
require_once __DIR__ . '/../API/core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user'])) {
    header("Location: ../accueil/accueil.php");
    exit;
}

// En-têtes de sécurité (page de credentials) — CSP à nonce comme login/index.php,
// afin que cette page sensible ne soit pas servie sans CSP.
$cspNonce = base64_encode(random_bytes(16));
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; font-src cdnjs.cloudflare.com; img-src 'self' data:;");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

$error = '';
$success = '';
$userType = isset($_POST['user_type']) ? $_POST['user_type'] : '';

// Traitement du formulaire de demande de réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    // Vérification CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = __('login.error.csrf');
    } else {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $userType = isset($_POST['user_type']) ? $_POST['user_type'] : '';

        // Anti-brute-force : la clé inclut le COMPTE CIBLE (identifiant) et non REMOTE_ADDR.
        // Ainsi, derrière un proxy/NAT où tous partagent la même IP, l'identifiant distingue
        // les seaux → 5 tentatives ne bloquent plus TOUS les utilisateurs (le défaut corrigé).
        // NB : RateLimiter mêle aussi l'IP client au seau stocké → la limite effective est par
        // (identifiant, IP) ; un attaquant multi-IP n'est donc que borné par IP, pas globalement.
        // Compromis acceptable ici (reset validé par un admin, faible fréquence). La clé est
        // posée AVANT toute vérification d'existence → identique que le compte existe ou non
        // (pas d'oracle d'énumération). Fail-open si le limiteur casse.
        try {
            $rl = app('rate_limiter');
            $rl->setMaxAttempts(5)->setDecayMinutes(15);
            $rlKey = 'pwd_reset.' . strtolower($username !== '' ? $username : 'unknown');
            if ($rl->tooManyAttempts($rlKey)) {
                $error = "Trop de demandes de réinitialisation pour cet identifiant. Veuillez réessayer dans quelques minutes.";
            } else {
                $rl->hit($rlKey);
            }
        } catch (\Throwable $e) { error_log('[reset_password] rate limiter indisponible: ' . $e->getMessage()); }

        // Validation de base (ignorée si déjà bloqué par le rate-limit ci-dessus)
        if ($error !== '') {
            // message de rate-limit déjà positionné → ne rien traiter
        } elseif (empty($username) || empty($email) || empty($phone)) {
            $error = "Veuillez remplir tous les champs.";
        } else if ($userType === 'administrateur') {
            $error = "Les administrateurs ne peuvent pas réinitialiser leur mot de passe par cette méthode.";
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format d'adresse email invalide.";
        } else {
            // Formater le numéro de téléphone
            $phone = formatPhoneNumber($phone);

            // Utilisation exclusive de l'API centralisée
            $user = findUserByCredentials($username, $email, $phone, $userType);

            // Anti-énumération : réponse UNIFORME que le compte existe ou non. On ne
            // révèle jamais si le triplet identifiant+email+téléphone correspond à un
            // utilisateur. La demande n'est réellement créée que si un compte matche ;
            // dans tous les cas on redirige vers la même page de confirmation neutre.
            if ($user) {
                createResetRequest($user['id'], $userType);
            }
            $_SESSION['reset_requested'] = true;
            $_SESSION['reset_username']  = $username;
            header("Location: reset_confirmation.php");
            exit;
        }
    }
}

$csrfToken = generateCSRFToken();

/**
 * Formate un numéro de téléphone au format XX XX XX XX XX
 */
function formatPhoneNumber($phone) {
    // Supprimer tous les caractères non numériques
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // Vérifier si le numéro a 10 chiffres
    if (strlen($phone) === 10) {
        return implode(' ', str_split($phone, 2));
    }

    return $phone;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de mot de passe - FRONOTE</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="auth-container">
        <!-- En-tête -->
        <div class="auth-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">FRONOTE</h1>
            <p class="app-subtitle">Demande de réinitialisation de mot de passe</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- Note d'information -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <p>Pour des raisons de sécurité, la réinitialisation de mot de passe doit être validée par un administrateur.</p>
                <p>Veuillez fournir les informations suivantes pour confirmer votre identité.</p>
            </div>
        </div>

        <!-- Formulaire de demande de réinitialisation -->
        <form method="post" action="" id="resetForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <!-- Sélecteur de profil -->
            <div class="profile-selector" role="radiogroup" aria-label="Type de profil">
                <input type="radio" id="eleve" name="user_type" value="eleve" <?= $userType === 'eleve' ? 'checked' : '' ?> required>
                <label for="eleve" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="profile-label">Élève</div>
                </label>

                <input type="radio" id="parent" name="user_type" value="parent" <?= $userType === 'parent' ? 'checked' : '' ?>>
                <label for="parent" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="profile-label">Parent</div>
                </label>

                <input type="radio" id="professeur" name="user_type" value="professeur" <?= $userType === 'professeur' ? 'checked' : '' ?>>
                <label for="professeur" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="profile-label">Professeur</div>
                </label>

                <input type="radio" id="personnel" name="user_type" value="vie_scolaire" <?= $userType === 'vie_scolaire' ? 'checked' : '' ?>>
                <label for="personnel" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="profile-label">Personnel</div>
                </label>
            </div>

            <div class="form-group">
                <label for="username" class="required-field">Identifiant</label>
                <div class="input-group">
                    <i class="input-group-icon fas fa-user"></i>
                    <input type="text" id="username" name="username" class="form-control input-with-icon" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="email" class="required-field">Adresse email</label>
                <div class="input-group">
                    <i class="input-group-icon fas fa-envelope"></i>
                    <input type="email" id="email" name="email" class="form-control input-with-icon" required>
                </div>
            </div>

            <div class="form-group">
                <label for="phone" class="required-field">Numéro de téléphone</label>
                <div class="input-group">
                    <i class="input-group-icon fas fa-phone"></i>
                    <input type="tel" id="phone" name="phone" class="form-control input-with-icon" required placeholder="Ex: 06 12 34 56 78">
                </div>
                <small class="form-text text-muted">Format: 10 chiffres (espaces optionnels)</small>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Retour
                </a>
                <button type="submit" name="request_reset" class="btn btn-primary" id="submitBtn">
                    <span class="btn-text"><i class="fas fa-paper-plane" aria-hidden="true"></i> Faire la demande</span>
                </button>
            </div>
        </form>
    </div>

    <script nonce="<?= $cspNonce ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="user_type"]');

            // S'assurer qu'une option est sélectionnée par défaut
            if (!Array.from(radioButtons).some(radio => radio.checked)) {
                radioButtons[0].checked = true;
            }

            // Formater automatiquement le numéro de téléphone
            const phoneInput = document.getElementById('phone');
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }

                if (value.length >= 2) {
                    let formattedValue = '';
                    for (let i = 0; i < value.length; i += 2) {
                        if (i + 2 <= value.length) {
                            formattedValue += value.substring(i, i + 2) + ' ';
                        } else {
                            formattedValue += value.substring(i);
                        }
                    }
                    e.target.value = formattedValue.trim();
                } else {
                    e.target.value = value;
                }
            });
        });
    </script>
</body>
</html>
