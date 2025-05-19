<?php
// Démarrage de la session
session_start();

// Inclusion des fichiers nécessaires
require_once '../../API/config/config.php';
require_once '../../API/core/Security.php';
require_once '../src/auth.php';

// Initialiser l'objet d'authentification
$auth = new Auth();

// Variables pour le formulaire
$profil = filter_input(INPUT_POST, 'profil', FILTER_SANITIZE_STRING) ?: '';
$identifiant = filter_input(INPUT_POST, 'identifiant', FILTER_SANITIZE_STRING) ?: '';
$password = $_POST['password'] ?? ''; // Ne pas filtrer le mot de passe pour permettre des caractères spéciaux

// Message d'erreur
$error = '';

// Vérifier si l'utilisateur est déjà connecté
if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    // Redirection vers l'accueil
    header('Location: ' . HOME_URL);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login($profil, $identifiant, $password)) {
        // La connexion a réussi, redirection vers l'accueil
        header('Location: ' . HOME_URL);
        exit;
    } else {
        // La connexion a échoué
        $error = 'Identifiant ou mot de passe incorrect';
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
    <title>Pronote - Connexion</title>
    <link rel="stylesheet" href="assets/css/pronote-login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="app-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">Pronote</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= \Pronote\Security\xss_clean($error) ?></span>
            </div>
        <?php endif; ?>
        
        <form method="post" action="" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="profile-selector">
                <input type="radio" name="profil" id="profile-student" value="eleve" <?= ($profil === 'eleve') ? 'checked' : '' ?>>
                <label for="profile-student" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="profile-label">Élève</div>
                </label>
                
                <input type="radio" name="profil" id="profile-parent" value="parent" <?= ($profil === 'parent') ? 'checked' : '' ?>>
                <label for="profile-parent" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="profile-label">Parent</div>
                </label>
                
                <input type="radio" name="profil" id="profile-teacher" value="professeur" <?= ($profil === 'professeur') ? 'checked' : '' ?>>
                <label for="profile-teacher" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="profile-label">Professeur</div>
                </label>
                
                <input type="radio" name="profil" id="profile-admin" value="administrateur" <?= ($profil === 'administrateur' || $profil === 'vie_scolaire') ? 'checked' : '' ?>>
                <label for="profile-admin" class="profile-option">
                    <div class="profile-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="profile-label">Personnel</div>
                </label>
            </div>
            
            <!-- Un sous-menu pour choisir entre Administrateur et Vie Scolaire -->
            <div id="personnel-submenu" class="personnel-options" style="display: none;">
                <label>
                    <input type="radio" name="profil" value="administrateur" <?= ($profil === 'administrateur') ? 'checked' : '' ?>>
                    <span>Administrateur</span>
                </label>
                <label>
                    <input type="radio" name="profil" value="vie_scolaire" <?= ($profil === 'vie_scolaire') ? 'checked' : '' ?>>
                    <span>Vie Scolaire</span>
                </label>
            </div>
            
            <div class="form-group">
                <label for="identifiant" class="required-field">Identifiant</label>
                <div class="input-group">
                    <input type="text" id="identifiant" name="identifiant" value="<?= \Pronote\Security\xss_clean($identifiant) ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password" class="required-field">Mot de passe</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" required>
                    <button type="button" class="visibility-toggle" aria-label="Afficher/Masquer le mot de passe">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn btn-connect">Se connecter</button>
            
            <div class="help-links">
                <a href="#" onclick="alert('Contactez votre administrateur pour réinitialiser votre mot de passe.');">
                    <i class="fas fa-key fa-sm"></i> Mot de passe oublié ?
                </a>
                <!-- Le lien d'inscription a été supprimé pour sécuriser l'accès -->
            </div>
        </form>
    </div>
    
    <script>
        // Gestion du sous-menu Personnel
        document.addEventListener('DOMContentLoaded', function() {
            const adminRadio = document.getElementById('profile-admin');
            const personnelSubmenu = document.getElementById('personnel-submenu');
            
            // Fonction pour afficher/masquer le sous-menu Personnel
            function togglePersonnelSubmenu() {
                if (adminRadio.checked) {
                    personnelSubmenu.style.display = 'block';
                    
                    // Si aucune option n'est sélectionnée dans le sous-menu, sélectionner la première par défaut
                    const selectedSubOption = personnelSubmenu.querySelector('input[type="radio"]:checked');
                    if (!selectedSubOption) {
                        const firstSubOption = personnelSubmenu.querySelector('input[type="radio"]');
                        if (firstSubOption) firstSubOption.checked = true;
                    }
                } else {
                    personnelSubmenu.style.display = 'none';
                }
            }
            
            // Vérifier l'état initial
            togglePersonnelSubmenu();
            
            // Ajouter l'écouteur d'événements pour tous les boutons radio de profil
            document.querySelectorAll('.profile-selector input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', togglePersonnelSubmenu);
            });
        });
        
        // Validation du formulaire côté client
        document.querySelector('form').addEventListener('submit', function(event) {
            const profil = document.querySelector('input[name="profil"]:checked');
            const identifiant = document.getElementById('identifiant').value.trim();
            const password = document.getElementById('password').value;
            
            let hasError = false;
            
            // Vérifier qu'un profil est sélectionné
            if (!profil) {
                alert('Veuillez sélectionner un profil.');
                hasError = true;
            }
            
            // Vérifier que l'identifiant n'est pas vide
            if (identifiant === '') {
                alert('Veuillez entrer votre identifiant.');
                hasError = true;
            }
            
            // Vérifier que le mot de passe n'est pas vide
            if (password === '') {
                alert('Veuillez entrer votre mot de passe.');
                hasError = true;
            }
            
            if (hasError) {
                event.preventDefault();
            }
        });

        // Toggle password visibility
        document.querySelector('.visibility-toggle').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
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
    </script>
</body>
</html>