<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/auth.php';

$auth = new Auth($pdo);
$auth->requireLogin();

// Sécurité: Vérifier si l'utilisateur est arrivé via le processus normal d'authentification
if (!isset($_SESSION['password_change_required']) && !isset($_SESSION['user']['first_login'])) {
    // Rediriger vers index.php si l'accès est direct
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$errors = []; // Tableau pour stocker les différentes erreurs

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Vérification des règles de mot de passe
    $lengthValid = strlen($newPassword) >= 12 && strlen($newPassword) <= 50;
    $uppercaseValid = preg_match('/[A-Z]/', $newPassword);
    $lowercaseValid = preg_match('/[a-z]/', $newPassword);
    $digitValid = preg_match('/[0-9]/', $newPassword);
    $specialValid = preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword);
    $matchValid = $newPassword === $confirmPassword;
    
    // Ajouter des messages d'erreur spécifiques
    if (!$lengthValid) {
        $errors[] = 'Le mot de passe doit contenir entre 12 et 50 caractères.';
    }
    if (!$uppercaseValid) {
        $errors[] = 'Le mot de passe doit contenir au moins une lettre majuscule.';
    }
    if (!$lowercaseValid) {
        $errors[] = 'Le mot de passe doit contenir au moins une lettre minuscule.';
    }
    if (!$digitValid) {
        $errors[] = 'Le mot de passe doit contenir au moins un chiffre.';
    }
    if (!$specialValid) {
        $errors[] = 'Le mot de passe doit contenir au moins un caractère spécial (!@#$%^&*...).';
    }
    if (!$matchValid) {
        $errors[] = 'Les mots de passe ne correspondent pas.';
    }
    
    // S'il n'y a pas d'erreurs, changer le mot de passe
    if (empty($errors)) {
        if ($auth->changePassword($newPassword)) {
            // Mettre à jour le flag de session pour indiquer que le mot de passe a été changé
            $_SESSION['password_change_required'] = false;
            $success = 'Votre mot de passe a été modifié avec succès.';
        } else {
            $error = 'Une erreur est survenue lors du changement de mot de passe.';
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$user = $_SESSION['user'];
$isFirstLogin = $user['first_login'] ?? false;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - Changement de mot de passe</title>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="change-password-container">
        <div class="app-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">Pronote</h1>
        </div>
        
        <?php if (isset($user)): ?>
            <div class="avatar">
                <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                <div class="user-name"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php elseif (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form action="change_password.php" method="post" id="passwordForm">
            <?php if ($isFirstLogin || isset($_GET['reset'])): ?>
                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="new_password" name="new_password" required>
                        <button type="button" class="visibility-toggle" id="newPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-meter">
                            <div class="strength-meter-fill" id="strengthIndicator" style="width: 0%;"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Force du mot de passe</div>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <div class="requirement" id="length-requirement">
                        <i class="fas fa-circle" id="length-status"></i>
                        <span>Au moins 8 caractères</span>
                    </div>
                    <div class="requirement" id="uppercase-requirement">
                        <i class="fas fa-circle" id="uppercase-status"></i>
                        <span>Au moins une majuscule</span>
                    </div>
                    <div class="requirement" id="lowercase-requirement">
                        <i class="fas fa-circle" id="lowercase-status"></i>
                        <span>Au moins une minuscule</span>
                    </div>
                    <div class="requirement" id="digit-requirement">
                        <i class="fas fa-circle" id="digit-status"></i>
                        <span>Au moins un chiffre</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="visibility-toggle" id="confirmPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="passwordMatch" style="font-size: 12px; margin-top: 5px;"></div>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label for="current_password">Mot de passe actuel</label>
                    <div class="input-group">
                        <input type="password" id="current_password" name="current_password" required>
                        <button type="button" class="visibility-toggle" id="currentPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="new_password" name="new_password" required>
                        <button type="button" class="visibility-toggle" id="newPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-meter">
                            <div class="strength-meter-fill" id="strengthIndicator" style="width: 0%;"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Force du mot de passe</div>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <div class="requirement" id="length-requirement">
                        <i class="fas fa-circle" id="length-status"></i>
                        <span>Au moins 8 caractères</span>
                    </div>
                    <div class="requirement" id="uppercase-requirement">
                        <i class="fas fa-circle" id="uppercase-status"></i>
                        <span>Au moins une majuscule</span>
                    </div>
                    <div class="requirement" id="lowercase-requirement">
                        <i class="fas fa-circle" id="lowercase-status"></i>
                        <span>Au moins une minuscule</span>
                    </div>
                    <div class="requirement" id="digit-requirement">
                        <i class="fas fa-circle" id="digit-status"></i>
                        <span>Au moins un chiffre</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <div class="input-group">
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="visibility-toggle" id="confirmPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="passwordMatch" style="font-size: 12px; margin-top: 5px;"></div>
                </div>
            <?php endif; ?>
            
            <div class="form-actions">
                <button type="submit" class="btn-connect">Enregistrer</button>
                <?php if (!$isFirstLogin): ?>
                <a href="../accueil/accueil.php" class="btn-cancel">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const strengthIndicator = document.getElementById('strengthIndicator');
            const strengthText = document.getElementById('strengthText');
            const passwordMatchText = document.getElementById('passwordMatch');
            const newPasswordToggle = document.getElementById('newPasswordToggle');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            
            // Tableau pour stocker les statuts des indicateurs
            const statusIcons = {
                'length': document.getElementById('length-status'),
                'uppercase': document.getElementById('uppercase-status'),
                'lowercase': document.getElementById('lowercase-status'),
                'digit': document.getElementById('digit-status'),
            };
            
            // Fonction pour évaluer la force du mot de passe
            function checkPasswordStrength(password) {
                let strength = 0;
                let statuses = {
                    length: false,
                    uppercase: false,
                    lowercase: false,
                    digit: false
                };
                
                // Longueur minimale
                if (password.length >= 8) {
                    strength += 25;
                    statuses.length = true;
                }
                
                // Présence de majuscules
                if (/[A-Z]/.test(password)) {
                    strength += 25;
                    statuses.uppercase = true;
                }
                
                // Présence de minuscules
                if (/[a-z]/.test(password)) {
                    strength += 25;
                    statuses.lowercase = true;
                }
                
                // Présence de chiffres
                if (/[0-9]/.test(password)) {
                    strength += 25;
                    statuses.digit = true;
                }
                
                return { strength, statuses };
            }
            
            // Fonction pour mettre à jour l'indicateur de force
            function updateStrengthIndicator() {
                if (!newPasswordInput) return;
                
                const password = newPasswordInput.value;
                const { strength, statuses } = checkPasswordStrength(password);
                
                // Mettre à jour la barre de progression
                strengthIndicator.style.width = strength + '%';
                
                // Changer la couleur selon la force
                if (strength < 50) {
                    strengthIndicator.style.backgroundColor = '#f44336'; // Rouge
                    strengthText.textContent = 'Faible';
                    strengthText.style.color = '#f44336';
                } else if (strength < 100) {
                    strengthIndicator.style.backgroundColor = '#ff9800'; // Orange
                    strengthText.textContent = 'Moyen';
                    strengthText.style.color = '#ff9800';
                } else {
                    strengthIndicator.style.backgroundColor = '#4caf50'; // Vert
                    strengthText.textContent = 'Fort';
                    strengthText.style.color = '#4caf50';
                    strengthIndicator.style.animation = 'pulse 2s infinite';
                }
                
                // Mettre à jour les icônes de statut
                for (const [key, status] of Object.entries(statuses)) {
                    const icon = statusIcons[key];
                    if (icon) {
                        if (status) {
                            icon.classList.remove('fa-circle');
                            icon.classList.add('fa-check-circle');
                            icon.style.color = '#4caf50';
                            icon.closest('.requirement').classList.add('valid');
                        } else {
                            icon.classList.remove('fa-check-circle');
                            icon.classList.add('fa-circle');
                            icon.style.color = '#ccc';
                            icon.closest('.requirement').classList.remove('valid');
                        }
                    }
                }
            }
            
            // Fonction pour vérifier si les mots de passe correspondent
            function checkPasswordMatch() {
                if (!newPasswordInput || !confirmPasswordInput) return;
                
                const password = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword.length === 0) {
                    passwordMatchText.textContent = '';
                    return;
                }
                
                if (password === confirmPassword) {
                    passwordMatchText.textContent = 'Les mots de passe correspondent';
                    passwordMatchText.style.color = '#4caf50';
                } else {
                    passwordMatchText.textContent = 'Les mots de passe ne correspondent pas';
                    passwordMatchText.style.color = '#f44336';
                }
            }
            
            // Ajout des événements
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    updateStrengthIndicator();
                    checkPasswordMatch();
                });
            }
            
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }
            
            // Toggle pour l'affichage du mot de passe
            if (newPasswordToggle) {
                newPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility(newPasswordInput, newPasswordToggle);
                });
            }
            
            if (confirmPasswordToggle) {
                confirmPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility(confirmPasswordInput, confirmPasswordToggle);
                });
            }
            
            const currentPasswordToggle = document.getElementById('currentPasswordToggle');
            const currentPasswordInput = document.getElementById('current_password');
            if (currentPasswordToggle && currentPasswordInput) {
                currentPasswordToggle.addEventListener('click', function() {
                    togglePasswordVisibility(currentPasswordInput, currentPasswordToggle);
                });
            }
            
            function togglePasswordVisibility(inputElement, toggleElement) {
                const type = inputElement.getAttribute('type') === 'password' ? 'text' : 'password';
                inputElement.setAttribute('type', type);
                
                // Change l'icône de l'œil
                const icon = toggleElement.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
            
            // Initialisation
            if (newPasswordInput) {
                updateStrengthIndicator();
            }
        });
    </script>
</body>
</html>