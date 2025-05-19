<?php
// Démarrage de la session
session_start();

// Inclusion des fichiers nécessaires
require_once '../../API/config/config.php';
require_once '../../API/core/Security.php';
require_once '../src/auth.php';
require_once '../src/user.php';

// Initialiser les objets
$auth = new Auth();
$user = new User();

// Variables pour le formulaire
$error = '';
$success = '';
$token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_STRING) ?: '';

// Si un token est fourni, vérifier sa validité
$tokenValid = false;
$userId = null;

if ($token) {
    $tokenData = $user->verifyResetToken($token);
    if ($tokenData && $tokenData['valid']) {
        $tokenValid = true;
        $userId = $tokenData['user_id'];
    } else {
        $error = 'Le lien de réinitialisation n\'est plus valide ou a expiré.';
    }
}

// Traitement du formulaire de réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $formToken = filter_input(INPUT_POST, 'token', FILTER_SANITIZE_STRING);
    
    // Vérification du token de formulaire
    if ($token !== $formToken) {
        $error = 'Erreur de validation du formulaire.';
    } 
    // Vérification de la correspondance des mots de passe
    elseif ($password !== $password_confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } 
    // Vérification de la complexité du mot de passe
    elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } 
    else {
        // Réinitialisation du mot de passe
        $result = $user->resetPassword($userId, $password, $token);
        if ($result) {
            $success = 'Votre mot de passe a été réinitialisé avec succès.';
            // Invalidate token
            $user->invalidateToken($token);
        } else {
            $error = 'Une erreur est survenue lors de la réinitialisation du mot de passe.';
        }
    }
}

// Génération d'un token CSRF pour la sécurité du formulaire
$csrfToken = \Pronote\Security\generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - Réinitialisation du mot de passe</title>
    <link rel="stylesheet" href="assets/css/pronote-login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="app-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">Réinitialisation</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            
            <div class="form-actions" style="margin-top: 30px;">
                <a href="index.php" class="btn btn-primary">Se connecter</a>
            </div>
        <?php elseif ($tokenValid): ?>
            <form method="post" action="" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <div class="form-group">
                    <label for="password" class="required-field">Nouveau mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" required minlength="8">
                        <button type="button" class="visibility-toggle toggle-password" aria-label="Afficher/Masquer le mot de passe">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="password-strength-meter">
                    <div class="strength-indicator"></div>
                </div>
                <p class="password-strength-text">Force: <span id="strength-text">Faible</span></p>
                
                <div class="form-group">
                    <label for="password_confirm" class="required-field">Confirmer le mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                        <button type="button" class="visibility-toggle toggle-password" aria-label="Afficher/Masquer le mot de passe">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <div class="requirement-item">
                        <span class="requirement-status" id="length-check"><i class="fas fa-times invalid"></i></span>
                        <span>Au moins 8 caractères</span>
                    </div>
                    <div class="requirement-item">
                        <span class="requirement-status" id="uppercase-check"><i class="fas fa-times invalid"></i></span>
                        <span>Au moins une majuscule</span>
                    </div>
                    <div class="requirement-item">
                        <span class="requirement-status" id="number-check"><i class="fas fa-times invalid"></i></span>
                        <span>Au moins un chiffre</span>
                    </div>
                </div>
                
                <button type="submit" name="reset_password" class="btn btn-primary" style="margin-top: 20px;">Réinitialiser le mot de passe</button>
            </form>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <span>Pour réinitialiser votre mot de passe, veuillez contacter votre administrateur ou utiliser le lien reçu par email.</span>
            </div>
            
            <div class="form-actions" style="margin-top: 30px;">
                <a href="index.php" class="btn btn-primary">Retour à la connexion</a>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($tokenValid): ?>
    <script>
        // Password visibility toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const passwordField = this.closest('.input-group').querySelector('input');
                const icon = this.querySelector('i');
                
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
        
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthIndicator = document.querySelector('.strength-indicator');
        const strengthText = document.getElementById('strength-text');
        
        // Password requirements check
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const numberCheck = document.getElementById('number-check');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            // Check length
            if (password.length >= 8) {
                strength += 1;
                lengthCheck.innerHTML = '<i class="fas fa-check valid"></i>';
            } else {
                lengthCheck.innerHTML = '<i class="fas fa-times invalid"></i>';
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                strength += 1;
                uppercaseCheck.innerHTML = '<i class="fas fa-check valid"></i>';
            } else {
                uppercaseCheck.innerHTML = '<i class="fas fa-times invalid"></i>';
            }
            
            // Check number
            if (/[0-9]/.test(password)) {
                strength += 1;
                numberCheck.innerHTML = '<i class="fas fa-check valid"></i>';
            } else {
                numberCheck.innerHTML = '<i class="fas fa-times invalid"></i>';
            }
            
            // Update strength meter
            if (strength === 0) {
                strengthIndicator.style.width = '0%';
                strengthIndicator.style.backgroundColor = '#e74c3c';
                strengthText.textContent = 'Faible';
            } else if (strength === 1) {
                strengthIndicator.style.width = '33%';
                strengthIndicator.style.backgroundColor = '#e74c3c';
                strengthText.textContent = 'Faible';
            } else if (strength === 2) {
                strengthIndicator.style.width = '66%';
                strengthIndicator.style.backgroundColor = '#f39c12';
                strengthText.textContent = 'Moyen';
            } else {
                strengthIndicator.style.width = '100%';
                strengthIndicator.style.backgroundColor = '#2ecc71';
                strengthText.textContent = 'Fort';
                strengthIndicator.style.animation = 'pulse 2s infinite';
            }
        });
        
        // Form validation
        document.getElementById('resetForm').addEventListener('submit', function(event) {
            const password = passwordInput.value;
            const confirmPassword = document.getElementById('password_confirm').value;
            
            if (password.length < 8) {
                event.preventDefault();
                alert('Le mot de passe doit contenir au moins 8 caractères.');
                return false;
            }
            
            if (password !== confirmPassword) {
                event.preventDefault();
                alert('Les mots de passe ne correspondent pas.');
                return false;
            }
            
            return true;
        });
    </script>
    <?php endif; ?>
</body>
</html>
