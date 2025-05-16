<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/user.php';

$auth = new Auth($pdo);
$user = new User($pdo);
$error = '';
$success = '';
$generatedPassword = '';
$identifiant = '';

// Chargement des données d'établissement (classes et matières)
$etablissementData = $user->getEtablissementData();

// Traitement du formulaire d'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $profil = isset($_POST['profil']) ? $_POST['profil'] : '';
    
    // Configuration des champs requis par profil
    $requiredFields = [
        'eleve' => ['nom', 'prenom', 'date_naissance', 'lieu_naissance', 'classe', 'adresse', 'mail'],
        'parent' => ['nom', 'prenom', 'mail', 'adresse'],
        'professeur' => ['nom', 'prenom', 'mail', 'adresse', 'matiere'],
        'vie_scolaire' => ['nom', 'prenom', 'mail'],
        'administrateur' => ['nom', 'prenom', 'mail']
    ];
    
    // Champs optionnels par profil
    $optionalFields = [
        'eleve' => ['telephone'],
        'parent' => ['telephone', 'metier', 'est_parent_eleve'],
        'professeur' => ['telephone', 'professeur_principal'],
        'vie_scolaire' => ['telephone', 'est_CPE', 'est_infirmerie'],
        'administrateur' => ['telephone']
    ];

    if (!isset($requiredFields[$profil])) {
        $error = 'Profil invalide.';
    } else {
        $data = [];
        
        // Validation des champs requis
        foreach ($requiredFields[$profil] as $field) {
            if (empty($_POST[$field])) {
                $error = "Le champ '$field' est obligatoire.";
                break;
            }
            $data[$field] = trim($_POST[$field]);
        }
        
        // Si pas d'erreur, on ajoute les champs optionnels
        if (!$error) {
            foreach ($optionalFields[$profil] as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $data[$field] = trim($_POST[$field]);
                }
            }
            
            // Tentative de création de l'utilisateur
            if ($user->create($profil, $data)) {
                $generatedPassword = $user->getGeneratedPassword();
                $identifiant = $user->getGeneratedIdentifier();
                
                $success = "Compte créé avec succès!";
                
                // Si c'est un administrateur, on s'assure de ne pas le connecter automatiquement
                if ($profil === 'administrateur') {
                    // Déconnecter si connecté automatiquement
                    if ($auth->isLoggedIn()) {
                        $auth->logout();
                    }
                }
                
                // Réinitialiser les données du formulaire après création réussie
                unset($data);
                $_POST = [];
                $profil = 'eleve'; // Réinitialiser le profil sélectionné
            } else {
                // Récupérer le message d'erreur spécifique
                $error = $user->getErrorMessage();
                if (empty($error)) {
                    $error = "Échec de l'enregistrement pour une raison inconnue. Veuillez réessayer.";
                }
            }
        }
    }
}

// Détermination du profil sélectionné (pour le formulaire)
$profil = isset($_POST['profil']) ? $_POST['profil'] : 'eleve';

// Déterminer quel avatar afficher en fonction du profil sélectionné
$avatars = [
    'eleve' => 'student.png',
    'parent' => 'parent.png',
    'professeur' => 'teacher.png',
    'vie_scolaire' => 'staff.png',
    'administrateur' => 'admin.png'
];
$avatarImg = $avatars[$profil] ?? 'student.png';
$espaceTitle = 'Création compte ' . ucfirst($profil);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - Inscription</title>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="register-container">
        <div class="app-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">Pronote - Inscription</h1>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <p><?php echo htmlspecialchars($success); ?></p>
                
                <div class="credentials-info">
                    <strong>Identifiant :</strong> <?php echo htmlspecialchars($identifiant); ?><br>
                    <strong>Mot de passe temporaire :</strong> <?php echo htmlspecialchars($generatedPassword); ?>
                    <p class="warning">Notez ces informations, elles ne seront plus affichées.</p>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="index.php" class="btn-connect">Se connecter</a>
                <a href="register.php" class="btn-secondary">Nouvelle inscription</a>
            </div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form action="register.php" method="post" class="register-form">
                <div class="form-group">
                    <label for="profil">Profil</label>
                    <select id="profil" name="profil" required>
                        <option value="">Sélectionnez votre profil</option>
                        <option value="eleve" <?php echo ($profil === 'eleve') ? 'selected' : ''; ?>>Élève</option>
                        <option value="parent" <?php echo ($profil === 'parent') ? 'selected' : ''; ?>>Parent d'élève</option>
                        <option value="professeur" <?php echo ($profil === 'professeur') ? 'selected' : ''; ?>>Professeur</option>
                        <option value="vie_scolaire" <?php echo ($profil === 'vie_scolaire') ? 'selected' : ''; ?>>Vie scolaire</option>
                        <option value="administrateur" <?php echo ($profil === 'administrateur') ? 'selected' : ''; ?>>Administrateur</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="<?php echo htmlspecialchars(isset($_POST['nom']) ? $_POST['nom'] : ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" value="<?php echo htmlspecialchars(isset($_POST['prenom']) ? $_POST['prenom'] : ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="mail">Email</label>
                    <input type="email" id="mail" name="mail" value="<?php echo htmlspecialchars(isset($_POST['mail']) ? $_POST['mail'] : ''); ?>" required>
                </div>
                
                <!-- Champs dynamiques qui s'affichent en fonction du profil -->
                <div id="dynamicFields"></div>
                
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Annuler</a>
                    <button type="submit" name="submit" value="1" class="btn-connect">S'inscrire</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Définir les champs spécifiques à chaque profil
            const profileFields = {
                'eleve': `
                    <div class="form-group">
                        <label for="date_naissance">Date de naissance</label>
                        <input type="date" id="date_naissance" name="date_naissance" required>
                    </div>
                    <div class="form-group">
                        <label for="lieu_naissance">Lieu de naissance</label>
                        <input type="text" id="lieu_naissance" name="lieu_naissance" required>
                        <div id="villesSuggestions" class="suggestions-container"></div>
                    </div>
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select id="classe" name="classe" required>
                            <option value="">Sélectionner une classe</option>
                            <!-- Options générées dynamiquement -->
                        </select>
                    </div>
                `,
                'parent': `
                    <div class="form-group">
                        <label for="adresse">Adresse</label>
                        <input type="text" id="adresse" name="adresse" required>
                        <div id="adressesSuggestions" class="suggestions-container"></div>
                    </div>
                    <div class="form-group">
                        <label for="telephone">Téléphone</label>
                        <input type="text" id="telephone" name="telephone" required>
                    </div>
                    <div class="form-group">
                        <label for="profession">Profession</label>
                        <input type="text" id="profession" name="profession">
                    </div>
                `,
                'professeur': `
                    <div class="form-group">
                        <label for="matiere">Matière enseignée</label>
                        <select id="matiere" name="matiere" required>
                            <option value="">Sélectionner une matière</option>
                            <!-- Options générées dynamiquement -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="telephone">Téléphone</label>
                        <input type="text" id="telephone" name="telephone" required>
                    </div>
                `,
                'vie_scolaire': `
                    <div class="form-group">
                        <label for="est_CPE">CPE</label>
                        <select id="est_CPE" name="est_CPE">
                            <option value="oui">Oui</option>
                            <option value="non">Non</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="est_infirmerie">Infirmerie</label>
                        <select id="est_infirmerie" name="est_infirmerie">
                            <option value="oui">Oui</option>
                            <option value="non">Non</option>
                        </select>
                    </div>
                `,
                'administrateur': `
                    <!-- Aucun champ supplémentaire nécessaire -->
                `
            };
            
            // Fonction pour mettre à jour les champs du formulaire
            function updateFormFields() {
                const profil = document.getElementById('profil').value;
                const dynamicFields = document.getElementById('dynamicFields');
                
                if (profil && profileFields[profil]) {
                    dynamicFields.innerHTML = profileFields[profil];
                    
                    // Initialiser les champs spécifiques
                    if (profil === 'eleve') {
                        loadClasses();
                        setupVilleAutocomplete();
                    } else if (profil === 'professeur') {
                        loadMatieres();
                    } else if (profil === 'parent') {
                        setupAdresseAutocomplete();
                    }
                } else {
                    dynamicFields.innerHTML = '';
                }
            }
            
            // Fonction pour charger les classes
            function loadClasses() {
                const classeSelect = document.getElementById('classe');
                if (classeSelect) {
                    // Simuler le chargement des classes (à remplacer par un appel AJAX)
                    const classes = [
                        { niveau: 'Collège', classes: ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B'] },
                        { niveau: 'Lycée', classes: ['2ndA', '2ndB', '1èreA', '1èreB', 'TermA', 'TermB'] }
                    ];
                    
                    classes.forEach(niveau => {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = niveau.niveau;
                        
                        niveau.classes.forEach(classe => {
                            const option = document.createElement('option');
                            option.value = classe;
                            option.textContent = classe;
                            optgroup.appendChild(option);
                        });
                        
                        classeSelect.appendChild(optgroup);
                    });
                }
            }
            
            // Fonction pour charger les matières
            function loadMatieres() {
                const matiereSelect = document.getElementById('matiere');
                if (matiereSelect) {
                    // Simuler le chargement des matières (à remplacer par un appel AJAX)
                    const matieres = [
                        'Mathématiques', 
                        'Français', 
                        'Histoire-Géographie', 
                        'Sciences Physiques', 
                        'SVT', 
                        'Anglais', 
                        'Espagnol', 
                        'Allemand', 
                        'EPS', 
                        'Arts Plastiques', 
                        'Musique', 
                        'Technologie', 
                        'Sciences Économiques'
                    ];
                    
                    matieres.forEach(matiere => {
                        const option = document.createElement('option');
                        option.value = matiere;
                        option.textContent = matiere;
                        matiereSelect.appendChild(option);
                    });
                }
            }
            
            // Configuration de l'autocomplétion pour les villes
            function setupVilleAutocomplete() {
                const lieuNaissanceInput = document.getElementById('lieu_naissance');
                const villesSuggestions = document.getElementById('villesSuggestions');
                
                if (lieuNaissanceInput && villesSuggestions) {
                    lieuNaissanceInput.addEventListener('input', function() {
                        const query = this.value.trim();
                        if (query.length < 2) {
                            villesSuggestions.style.display = 'none';
                            return;
                        }
                        
                        // Simuler la recherche (à remplacer par un appel AJAX)
                        setTimeout(() => {
                            // Exemples de villes
                            const villes = [
                                'Paris', 'Marseille', 'Lyon', 'Toulouse', 'Nice', 'Nantes', 
                                'Strasbourg', 'Montpellier', 'Bordeaux', 'Lille'
                            ];
                            
                            const filteredVilles = villes.filter(ville => 
                                ville.toLowerCase().includes(query.toLowerCase())
                            );
                            
                            if (filteredVilles.length > 0) {
                                villesSuggestions.innerHTML = '';
                                filteredVilles.forEach(ville => {
                                    const div = document.createElement('div');
                                    div.className = 'suggestion-item';
                                    div.textContent = ville;
                                    div.addEventListener('click', function() {
                                        lieuNaissanceInput.value = ville;
                                        villesSuggestions.style.display = 'none';
                                    });
                                    villesSuggestions.appendChild(div);
                                });
                                villesSuggestions.style.display = 'block';
                            } else {
                                villesSuggestions.style.display = 'none';
                            }
                        }, 300);
                    });
                }
            }
            
            // Configuration de l'autocomplétion pour les adresses
            function setupAdresseAutocomplete() {
                const adresseInput = document.getElementById('adresse');
                const adressesSuggestions = document.getElementById('adressesSuggestions');
                
                if (adresseInput && adressesSuggestions) {
                    adresseInput.addEventListener('input', function() {
                        const query = this.value.trim();
                        if (query.length < 3) {
                            adressesSuggestions.style.display = 'none';
                            return;
                        }
                        
                        // Simuler la recherche (à remplacer par un appel AJAX)
                        setTimeout(() => {
                            // Exemples d'adresses
                            const adresses = [
                                '1 Rue de la Paix, 75002 Paris',
                                '15 Avenue des Champs-Élysées, 75008 Paris',
                                '25 Rue du Faubourg Saint-Honoré, 75008 Paris',
                                '10 Place de la Concorde, 75001 Paris',
                                '7 Boulevard Haussmann, 75009 Paris'
                            ];
                            
                            const filteredAdresses = adresses.filter(adresse => 
                                adresse.toLowerCase().includes(query.toLowerCase())
                            );
                            
                            if (filteredAdresses.length > 0) {
                                adressesSuggestions.innerHTML = '';
                                filteredAdresses.forEach(adresse => {
                                    const div = document.createElement('div');
                                    div.className = 'suggestion-item';
                                    div.textContent = adresse;
                                    div.addEventListener('click', function() {
                                        adresseInput.value = adresse;
                                        adressesSuggestions.style.display = 'none';
                                    });
                                    adressesSuggestions.appendChild(div);
                                });
                                adressesSuggestions.style.display = 'block';
                            } else {
                                adressesSuggestions.style.display = 'none';
                            }
                        }, 300);
                    });
                }
            }
            
            function initAutocompletion() {
                document.addEventListener('click', function(e) {
                    if (villesSuggestions && lieuNaissanceInput && !lieuNaissanceInput.contains(e.target) && !villesSuggestions.contains(e.target)) {
                        villesSuggestions.style.display = 'none';
                    }
                    
                    if (adressesSuggestions && adresseInput && !adresseInput.contains(e.target) && !adressesSuggestions.contains(e.target)) {
                        adressesSuggestions.style.display = 'none';
                    }
                });
            }
            
            // Écouter les changements sur le sélecteur de profil
            document.getElementById('profil').addEventListener('change', updateFormFields);
            
            // Initialiser le formulaire
            updateFormFields();
        });
    </script>
</body>
</html>