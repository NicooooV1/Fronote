<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/auth.php';
$auth = new Auth($pdo);

// Vérification simple : si l'utilisateur est déjà connecté, on le redirige
// Sauf si on vient de register.php avec un message de succès
if ($auth->isLoggedIn() && !isset($_GET['from_register'])) {
    // Redirection vers accueil.php
    header('Location: /~u22405372/SAE/Pronote/accueil/accueil.php');
    exit;
}

// Si l'utilisateur vient de register.php, on n'a PAS besoin de le déconnecter
// Car il n'est PAS encore connecté - il doit saisir ses identifiants

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profil      = isset($_POST['profil']) ? $_POST['profil'] : '';
    $identifiant = isset($_POST['identifiant']) ? $_POST['identifiant'] : '';
    $password    = isset($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : '';

    if ($auth->login($profil, $identifiant, $password)) {
        // Vérification si c'est la première connexion (mot de passe par défaut)
        if ($auth->isDefaultPassword()) {
            // Définir un drapeau de session pour autoriser l'accès à change_password.php
            $_SESSION['password_change_required'] = true;
            header('Location: change_password.php');
            exit;
        }
        // On utilise JavaScript avec le script session_checker.js pour la redirection
        echo '
        <script src="/~u22405372/SAE/Pronote/login/public/assets/js/session_checker.js"></script>
        <script>
            window.location.href = "/~u22405372/SAE/Pronote/accueil/accueil.php";
        </script>';
        exit;
    } else {
        $error = 'Identifiant ou mot de passe invalides.';
    }
}

// Déterminer quel avatar afficher en fonction du profil sélectionné
$profil = isset($_POST['profil']) ? $_POST['profil'] : 'eleve';
$avatars = [
    'eleve' => 'student.png',
    'parent' => 'parent.png',
    'professeur' => 'teacher.png',
    'vie_scolaire' => 'staff.png',
    'administrateur' => 'admin.png'
];
$avatarImg = $avatars[$profil] ?? 'student.png';
$espaceTitle = 'Espace ' . ucfirst($profil) . 's';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - Connexion</title>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
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
            border-radius: 8px;
            width: 80px;
            transition: all 0.2s;
        }
        
        .profile-option:hover {
            background-color: #f5f5f5;
        }
        
        .profile-option.selected {
            background-color: #e0f2e9;
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-bottom: 8px;
        }
        
        .profile-avatar i {
            font-size: 20px;
            color: white;
        }
        
        .profile-name {
            font-size: 12px;
            text-align: center;
            color: #555;
        }
        
        .profile-option.selected .profile-name {
            color: #009b72;
            font-weight: 500;
        }
        
        .avatar-eleve { background-color: #4285f4; }
        .avatar-parent { background-color: #0f9d58; }
        .avatar-professeur { background-color: #f4b400; }
        .avatar-vie_scolaire { background-color: #db4437; }
        .avatar-administrateur { background-color: #4527a0; }
        
        .selected-profile-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 8px;
        }
        
        .selected-profile-avatar {
            width: 40px;
            height: 40px;
            background-color: #009b72;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .selected-profile-text {
            flex: 1;
        }
        
        .selected-profile-title {
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }
        
        .selected-profile-description {
            font-size: 12px;
            color: #666;
        }
    </style>
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
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form action="index.php<?php echo (isset($_GET['from_register']) && isset($_GET['success'])) ? '?from_register=1&success=1' : ''; ?>" method="post">
            <div class="profile-selector">
                <div class="profile-option" data-profile="eleve">
                    <div class="profile-avatar avatar-eleve">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="profile-name">Élève</div>
                </div>
                <div class="profile-option" data-profile="parent">
                    <div class="profile-avatar avatar-parent">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="profile-name">Parent</div>
                </div>
                <div class="profile-option" data-profile="professeur">
                    <div class="profile-avatar avatar-professeur">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="profile-name">Professeur</div>
                </div>
                <div class="profile-option" data-profile="vie_scolaire">
                    <div class="profile-avatar avatar-vie_scolaire">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="profile-name">Vie scolaire</div>
                </div>
                <div class="profile-option" data-profile="administrateur">
                    <div class="profile-avatar avatar-administrateur">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="profile-name">Admin</div>
                </div>
            </div>
            
            <div class="selected-profile-info">
                <div id="selectedProfileAvatar" class="selected-profile-avatar avatar-eleve">
                    <i id="selectedProfileIcon" class="fas fa-user-graduate"></i>
                </div>
                <div class="selected-profile-text">
                    <div id="selectedProfileTitle" class="selected-profile-title">Espace Élèves</div>
                    <div class="selected-profile-description">Connectez-vous avec votre identifiant et mot de passe</div>
                </div>
            </div>
            
            <input type="hidden" id="profil" name="profil" value="eleve">
            
            <div class="form-group">
                <label for="username">Identifiant</label>
                <input type="text" id="username" name="identifiant" required>
            </div>
            
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <div class="input-group">
                    <input type="password" id="password" name="mot_de_passe" required>
                    <button type="button" class="visibility-toggle" id="passwordToggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-connect">Se connecter</button>
            </div>
        </form>
        
        <div class="additional-links" style="margin-top: 20px; text-align: center;">
            <a href="forgot_password.php" style="color: #009b72; font-size: 14px; margin-right: 15px;">Mot de passe oublié ?</a>
            <a href="register.php" style="color: #009b72; font-size: 14px;">S'inscrire</a>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('passwordToggle');
            const profileOptions = document.querySelectorAll('.profile-option');
            const profileInput = document.getElementById('profil');
            const selectedProfileTitle = document.getElementById('selectedProfileTitle');
            const selectedProfileAvatar = document.getElementById('selectedProfileAvatar');
            const selectedProfileIcon = document.getElementById('selectedProfileIcon');
            
            // Toggle password visibility
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Change l'icône de l'œil
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
            
            // Profile selection
            profileOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remove selected class from all options
                    profileOptions.forEach(opt => opt.classList.remove('selected'));
                    
                    // Add selected class to clicked option
                    this.classList.add('selected');
                    
                    // Update hidden input value
                    const profile = this.getAttribute('data-profile');
                    profileInput.value = profile;
                    
                    // Update profile info display
                    const titleMapping = {
                        'eleve': 'Espace Élèves',
                        'parent': 'Espace Parents',
                        'professeur': 'Espace Professeurs',
                        'vie_scolaire': 'Espace Vie Scolaire',
                        'administrateur': 'Espace Administration'
                    };
                    
                    const iconMapping = {
                        'eleve': 'fa-user-graduate',
                        'parent': 'fa-user-friends',
                        'professeur': 'fa-chalkboard-teacher',
                        'vie_scolaire': 'fa-clipboard-list',
                        'administrateur': 'fa-user-shield'
                    };
                    
                    selectedProfileTitle.textContent = titleMapping[profile] || 'Connexion';
                    
                    // Update avatar background
                    selectedProfileAvatar.className = 'selected-profile-avatar';
                    selectedProfileAvatar.classList.add('avatar-' + profile);
                    
                    // Update icon
                    selectedProfileIcon.className = 'fas';
                    selectedProfileIcon.classList.add(iconMapping[profile]);
                });
            });
            
            // Set default selected profile
            const defaultProfile = profileInput.value || 'eleve';
            const defaultOption = document.querySelector(`.profile-option[data-profile="${defaultProfile}"]`);
            if (defaultOption) {
                defaultOption.click();
            }
        });
    </script>
</body>
</html>