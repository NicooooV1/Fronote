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
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Styles pour la page de connexion */
        .profile-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: center;
        }
        
        .profile-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            padding: 10px;
        }
        
        .profile-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .profile-icon i {
            font-size: 24px;
            color: #333;
        }
        
        .profile-label {
            font-size: 14px;
            color: #333;
        }
        
        /* Style pour l'option sélectionnée */
        input[type="radio"]:checked + .profile-option .profile-icon {
            background-color: #00843d;
        }
        
        input[type="radio"]:checked + .profile-option .profile-icon i {
            color: white;
        }
        
        input[type="radio"]:checked + .profile-option .profile-label {
            font-weight: bold;
        }
        
        /* Cacher le radio button */
        .profile-selector input[type="radio"] {
            display: none;
        }
        
        .login-form {
            max-width: 400px;
            margin: 0 auto;
        }
        
        .error-message {
            background-color: #ffdddd;
            border-left: 4px solid #f44336;
            padding: 10px;
            margin-bottom: 20px;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">P</div>
            <h1>Pronote</h1>
        </div>
        
        <div class="login-form">
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?= \Pronote\Security\xss_clean($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
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
                    
                    <input type="radio" name="profil" id="profile-admin" value="administrateur" <?= ($profil === 'administrateur') ? 'checked' : '' ?>>
                    <label for="profile-admin" class="profile-option">
                        <div class="profile-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="profile-label">Personnel</div>
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="identifiant">Identifiant</label>
                    <input type="text" id="identifiant" name="identifiant" value="<?= \Pronote\Security\xss_clean($identifiant) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="login-button">Se connecter</button>
            </form>
            
            <div class="help-links">
                <a href="#" onclick="alert('Contactez votre administrateur pour réinitialiser votre mot de passe.');">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>
    
    <script>
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
    </script>
</body>
</html>