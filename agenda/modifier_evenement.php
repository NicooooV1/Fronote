<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Définir l'environnement si non défini
if (!defined('APP_ENV')) {
    define('APP_ENV', isset($_SERVER['APP_ENV']) ? $_SERVER['APP_ENV'] : 'production');
}

// Configuration de la gestion des erreurs selon l'environnement
if (APP_ENV !== 'development') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Inclusion des fichiers nécessaires - utiliser require_once pour éviter les inclusions multiples
require_once __DIR__ . '/../API/auth_central.php'; // Utiliser le système d'authentification centralisé
require_once __DIR__ . '/includes/db.php';

// Vérifier que l'utilisateur est connecté avec le système centralisé
if (!isLoggedIn()) {
    // Journaliser la tentative d'accès non autorisée
    error_log("Tentative d'accès non autorisée à modifier_evenement.php - Utilisateur non connecté");
    header('Location: ' . LOGIN_URL); // Utiliser la constante définie dans config.php
    exit;
}

// Récupérer les informations de l'utilisateur connecté via la fonction centralisée
$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = getUserInitials();

// Vérifier que l'ID est fourni et valide en utilisant filter_input
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    // Journaliser l'erreur
    error_log("Tentative d'accès à modifier_evenement.php avec un ID invalide");
    header('Location: agenda.php?error=invalid_id');
    exit;
}

// Utiliser un bloc try-catch pour gérer les erreurs de base de données
try {
    // Récupérer les détails de l'événement avec une requête préparée
    $stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
    $stmt->execute([$id]);
    $evenement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Vérifier que l'événement existe
    if (!$evenement) {
        // Journaliser l'erreur
        error_log("Tentative d'accès à un événement inexistant (ID: $id)");
        header('Location: agenda.php?error=event_not_found');
        exit;
    }
} catch (PDOException $e) {
    // Journaliser l'erreur
    error_log("Erreur de base de données lors de la récupération de l'événement (ID: $id): " . $e->getMessage());
    header('Location: agenda.php?error=database_error');
    exit;
}

// Vérifier que l'utilisateur a le droit de modifier cet événement
$can_edit = false;

// Administrateurs et vie scolaire peuvent tout modifier
if (isAdmin() || isVieScolaire()) {
    $can_edit = true;
} 
// Les professeurs ne peuvent modifier que leurs propres événements
elseif (isTeacher() && $evenement['createur'] === $user_fullname) {
    $can_edit = true;
}

if (!$can_edit) {
    // Journaliser la tentative d'accès non autorisée
    error_log("Tentative non autorisée de modification de l'événement (ID: $id) par l'utilisateur: $user_fullname");
    header('Location: details_evenement.php?id=' . $id . '&error=unauthorized');
    exit;
}

// Préparer les données pour le formulaire
$date_debut = new DateTime($evenement['date_debut']);
$date_fin = new DateTime($evenement['date_fin']);

// Déterminer la visibilité actuelle et les classes sélectionnées
$visibilite_actuelle = $evenement['visibilite'];
$classes_selectionnees = [];

if (strpos($visibilite_actuelle, 'classes:') === 0) {
    $classes_selectionnees = explode(',', substr($visibilite_actuelle, 8));
    $visibilite_actuelle = 'classes_specifiques';
}

// Types d'événements
$types_evenements = [
    'cours' => ['nom' => 'Cours', 'icone' => 'book', 'couleur' => '#00843d'],
    'devoirs' => ['nom' => 'Devoirs', 'icone' => 'pencil', 'couleur' => '#4285f4'],
    'reunion' => ['nom' => 'Réunion', 'icone' => 'users', 'couleur' => '#ff9800'],
    'examen' => ['nom' => 'Examen', 'icone' => 'file-text', 'couleur' => '#f44336'],
    'sortie' => ['nom' => 'Sortie scolaire', 'icone' => 'map-pin', 'couleur' => '#00c853'],
    'autre' => ['nom' => 'Autre', 'icone' => 'calendar', 'couleur' => '#9e9e9e']
];

// Options de visibilité selon le rôle
$options_visibilite = [];

if (isAdmin() || isVieScolaire()) {
    $options_visibilite = [
        'public' => ['nom' => 'Public (visible par tous)', 'icone' => 'globe'],
        'professeurs' => ['nom' => 'Professeurs uniquement', 'icone' => 'user-tie'],
        'eleves' => ['nom' => 'Élèves uniquement', 'icone' => 'user-graduate'],
        'classes_specifiques' => ['nom' => 'Classes spécifiques', 'icone' => 'users'],
        'administration' => ['nom' => 'Administration uniquement', 'icone' => 'user-shield'],
        'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
    ];
} elseif (isTeacher()) {
    $options_visibilite = [
        'public' => ['nom' => 'Public (visible par tous)', 'icone' => 'globe'],
        'professeurs' => ['nom' => 'Professeurs uniquement', 'icone' => 'user-tie'],
        'eleves' => ['nom' => 'Élèves uniquement', 'icone' => 'user-graduate'],
        'classes_specifiques' => ['nom' => 'Classes spécifiques', 'icone' => 'users'],
        'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
    ];
} else {
    // Élèves et parents - seulement personnel
    $options_visibilite = [
        'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
    ];
}

// Options de statut
$options_statut = [
    'actif' => ['nom' => 'Actif', 'icone' => 'check-circle', 'couleur' => '#00843d'],
    'annulé' => ['nom' => 'Annulé', 'icone' => 'ban', 'couleur' => '#f44336'],
    'reporté' => ['nom' => 'Reporté', 'icone' => 'clock', 'couleur' => '#ff9800']
];

// Récupérer la liste des classes de façon plus sécurisée
$classes = [];
$json_file = __DIR__ . '/../login/data/etablissement.json';
try {
    if (file_exists($json_file)) {
        $json_content = file_get_contents($json_file);
        if ($json_content === false) {
            throw new Exception("Impossible de lire le fichier etablissement.json");
        }
        
        $etablissement_data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de parsing JSON dans etablissement.json: " . json_last_error_msg());
        }
        
        // Extraire les classes du secondaire
        if (!empty($etablissement_data['classes'])) {
            foreach ($etablissement_data['classes'] as $niveau => $niveaux) {
                foreach ($niveaux as $sousniveau => $classe_array) {
                    foreach ($classe_array as $classe) {
                        $classes[] = $classe;
                    }
                }
            }
        }
        
        // Extraire les classes du primaire
        if (!empty($etablissement_data['primaire'])) {
            foreach ($etablissement_data['primaire'] as $niveau => $classe_array) {
                foreach ($classe_array as $classe) {
                    $classes[] = $classe;
                }
            }
        }
    }
} catch (Exception $e) {
    // Journaliser l'erreur mais ne pas interrompre l'application
    error_log("Erreur lors du chargement des classes: " . $e->getMessage());
}

// Si c'est un professeur, récupérer sa matière
$prof_matiere = '';
try {
    if (isTeacher()) {
        $stmt_prof = $pdo->prepare('SELECT matiere FROM professeurs WHERE nom = ? AND prenom = ?');
        $stmt_prof->execute([$user['nom'], $user['prenom']]);
        $prof_data = $stmt_prof->fetch();
        $prof_matiere = $prof_data ? $prof_data['matiere'] : '';
    }
} catch (PDOException $e) {
    // Journaliser l'erreur mais ne pas interrompre l'application
    error_log("Erreur lors de la récupération de la matière du professeur: " . $e->getMessage());
}

// Générer un token CSRF pour protéger le formulaire et le stocker en session avec une expiration
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
$_SESSION['csrf_token_time'] = time(); // Ajouter un timestamp pour l'expiration

// Traitement du formulaire
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier le token CSRF et sa validité temporelle (30 minutes max)
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > 1800) {
        
        // Journaliser la tentative potentielle de CSRF
        error_log("Tentative potentielle de CSRF lors de la modification de l'événement ID: $id");
        $erreur = "Erreur de sécurité: formulaire invalide ou expiré. Veuillez actualiser la page et réessayer.";
    } else {
        // Validation des champs obligatoires avec filtrage approprié
        $titre = filter_input(INPUT_POST, 'titre', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $date_debut = filter_input(INPUT_POST, 'date_debut', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $heure_debut = filter_input(INPUT_POST, 'heure_debut', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $date_fin = filter_input(INPUT_POST, 'date_fin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $heure_fin = filter_input(INPUT_POST, 'heure_fin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $type_evenement = filter_input(INPUT_POST, 'type_evenement', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $statut = filter_input(INPUT_POST, 'statut', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'actif';
        $visibilite = filter_input(INPUT_POST, 'visibilite', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $lieu = filter_input(INPUT_POST, 'lieu', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $matieres = filter_input(INPUT_POST, 'matieres', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        
        if (!$titre || !$date_debut || !$heure_debut || !$date_fin || !$heure_fin || !$type_evenement) {
            $erreur = "Veuillez remplir tous les champs obligatoires.";
        } else {
            // Formatage des dates
            try {
                $date_debut_obj = DateTime::createFromFormat('Y-m-d H:i:s', $date_debut . ' ' . $heure_debut . ':00');
                $date_fin_obj = DateTime::createFromFormat('Y-m-d H:i:s', $date_fin . ' ' . $heure_fin . ':00');
                
                if (!$date_debut_obj || !$date_fin_obj) {
                    throw new Exception("Format de date ou d'heure invalide.");
                }
                
                $date_debut_str = $date_debut_obj->format('Y-m-d H:i:s');
                $date_fin_str = $date_fin_obj->format('Y-m-d H:i:s');
                
                // Vérifier que la date de fin est après la date de début
                if ($date_fin_obj <= $date_debut_obj) {
                    throw new Exception("La date/heure de fin doit être après la date/heure de début.");
                }
                
                // Traitement de la visibilité et des classes sélectionnées
                $visibilite_db = $visibilite;
                $classes_selectionnees = '';
                
                if ($visibilite === 'classes_specifiques' && isset($_POST['classes'])) {
                    // Validation des classes sélectionnées
                    if (is_array($_POST['classes'])) {
                        // Filtrer pour ne garder que les classes valides
                        $selected_classes = array_filter($_POST['classes'], function($class) use ($classes) {
                            return in_array($class, $classes);
                        });
                        
                        if (empty($selected_classes)) {
                            throw new Exception("Aucune classe valide n'a été sélectionnée.");
                        }
                        
                        $classes_selectionnees = implode(',', $selected_classes);
                        $visibilite_db = 'classes:' . $classes_selectionnees;
                    } else {
                        throw new Exception("Format des classes sélectionnées invalide.");
                    }
                }
                
                // Mise à jour dans la base de données avec des requêtes préparées
                $stmt = $pdo->prepare('UPDATE evenements SET 
                                      titre = ?, 
                                      description = ?, 
                                      date_debut = ?, 
                                      date_fin = ?, 
                                      type_evenement = ?, 
                                      statut = ?, 
                                      visibilite = ?, 
                                      lieu = ?, 
                                      classes = ?, 
                                      matieres = ?,
                                      date_modification = CURRENT_TIMESTAMP,
                                      modifie_par = ?
                                      WHERE id = ?');
                
                $stmt->execute([
                    $titre,
                    $description,
                    $date_debut_str,
                    $date_fin_str,
                    $type_evenement,
                    $statut,
                    $visibilite_db,
                    $lieu,
                    $visibilite === 'classes_specifiques' ? $classes_selectionnees : $evenement['classes'],
                    $matieres,
                    $user_fullname, // Ajouter qui a modifié l'événement
                    $id
                ]);
                
                // Journaliser la modification
                error_log("Événement ID=$id modifié par {$user_fullname}");
                
                $message = "L'événement a été mis à jour avec succès.";
                
                // Régénérer un token CSRF après une soumission réussie
                $csrf_token = bin2hex(random_bytes(32));
                $_SESSION['csrf_token'] = $csrf_token;
                $_SESSION['csrf_token_time'] = time();
                
                // Redirection après un court délai
                header('Location: details_evenement.php?id=' . $id . '&updated=1');
                exit;
            } catch (Exception $e) {
                // Capturer toutes les exceptions
                $erreur = $e->getMessage();
                error_log("Erreur lors de la mise à jour de l'événement ID=$id: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com">
    <title>Modifier l'événement - Agenda Pronote</title>
    <link rel="stylesheet" href="assets/css/calendar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
    /* Styles spécifiques pour la page de modification d'événement */
    .event-edit-container {
      max-width: 800px;
      margin: 20px auto;
      background-color: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    
    .event-edit-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 25px;
      border-bottom: 1px solid #eee;
      background-color: #f9f9f9;
    }
    
    .event-edit-header h1 {
      font-size: 24px;
      font-weight: 500;
      color: #333;
      margin: 0;
    }
    
    .event-edit-form {
      padding: 25px;
    }
    
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }
    
    .form-full {
      grid-column: 1 / -1;
    }
    
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    
    .form-group label {
      font-weight: 500;
      color: #444;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .form-group label i {
      color: #777;
    }
    
    .form-control {
      padding: 10px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .form-control:focus {
      border-color: #00843d;
      outline: none;
      box-shadow: 0 0 0 2px rgba(0,132,61,0.1);
    }
    
    textarea.form-control {
      min-height: 120px;
      resize: vertical;
    }
    
    .radio-group {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-top: 10px;
    }
    
    .radio-option {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 15px;
      border: 1px solid #ddd;
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .radio-option:hover {
      background-color: #f9f9f9;
    }
    
    .radio-option.selected {
      background-color: #f0f8f4;
      border-color: #00843d;
    }
    
    .radio-option input {
      display: none;
    }
    
    .radio-option-icon {
      width: 20px;
      text-align: center;
    }
    
    .section-title {
      font-size: 16px;
      font-weight: 500;
      margin: 20px 0 15px;
      padding-bottom: 8px;
      border-bottom: 1px solid #eee;
      color: #444;
    }
    
    .classes-selection {
      margin-top: 15px;
      display: none;
    }
    
    .classes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 10px;
      max-height: 300px;
      overflow-y: auto;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }
    
    .class-option {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px;
      cursor: pointer;
      border-radius: 4px;
      transition: background-color 0.2s;
    }
    
    .class-option:hover {
      background-color: #f5f5f5;
    }
    
    .class-option input {
      margin: 0;
    }
    
    .message {
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 4px;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .message.success {
      background-color: #e0f2e9;
      color: #00843d;
      border-left: 4px solid #00843d;
    }
    
    .message.error {
      background-color: #fce8e6;
      color: #d93025;
      border-left: 4px solid #d93025;
    }
    
    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 15px;
      margin-top: 30px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 4px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      border: none;
      transition: all 0.2s;
      text-decoration: none;
    }
    
    .btn-primary {
      background-color: #00843d;
      color: white;
    }
    
    .btn-primary:hover {
      background-color: #006e32;
    }
    
    .btn-secondary {
      background-color: #f1f3f4;
      color: #444;
    }
    
    .btn-secondary:hover {
      background-color: #e0e0e0;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .radio-group {
        flex-direction: column;
        gap: 10px;
      }
      
      .radio-option {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <!-- Header avec navigation vers l'agenda -->
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Agenda</div>
      </div>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="agenda.php" class="button button-secondary">
          <i class="fas fa-calendar"></i> Retour à l'agenda
        </a>
        <a href="details_evenement.php?id=<?= htmlspecialchars($id) ?>" class="button button-secondary">
          <i class="fas fa-eye"></i> Voir l'événement
        </a>
      </div>
      
      <!-- Autres modules -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Autres modules</div>
        <div class="folder-menu">
          <a href="../notes/notes.php" class="module-link">
            <i class="fas fa-chart-bar"></i> Notes
          </a>
          <a href="../messagerie/index.php" class="module-link">
            <i class="fas fa-envelope"></i> Messagerie
          </a>
          <a href="../absences/absences.php" class="module-link">
            <i class="fas fa-calendar-times"></i> Absences
          </a>
          <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
            <i class="fas fa-book"></i> Cahier de textes
          </a>
          <a href="../accueil/accueil.php" class="module-link">
            <i class="fas fa-home"></i> Accueil
          </a>
        </div>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="calendar-navigation">
          <a href="details_evenement.php?id=<?= htmlspecialchars($id) ?>" class="back-button">
            <span class="back-icon">
              <i class="fas fa-arrow-left"></i>
            </span>
            Retour aux détails
          </a>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= htmlspecialchars($user_initials) ?></div>
        </div>
      </div>
      
      <!-- Container principal -->
      <div class="calendar-container">
        <div class="event-edit-container">
          <div class="event-edit-header">
            <h1>Modifier l'événement</h1>
          </div>
          
          <div class="event-edit-form">
            <?php if ($message): ?>
              <div class="message success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($message) ?>
              </div>
            <?php endif; ?>
            
            <?php if ($erreur): ?>
              <div class="message error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($erreur) ?>
              </div>
            <?php endif; ?>
            
            <form method="post" id="event-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              
              <div class="form-grid">
                <div class="form-group form-full">
                  <label for="titre">
                    <i class="fas fa-heading"></i>
                    Titre de l'événement*
                  </label>
                  <input type="text" name="titre" id="titre" class="form-control" 
                         value="<?= htmlspecialchars($evenement['titre']) ?>" required maxlength="100">
                </div>
                
                <div class="form-group form-full">
                  <label for="description">
                    <i class="fas fa-align-left"></i>
                    Description
                  </label>
                  <textarea name="description" id="description" class="form-control" 
                            maxlength="2000"><?= htmlspecialchars($evenement['description'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                  <label for="date_debut">
                    <i class="far fa-calendar"></i>
                    Date de début*
                  </label>
                  <input type="date" name="date_debut" id="date_debut" class="form-control" value="<?= $date_debut->format('Y-m-d') ?>" required>
                </div>
                
                <div class="form-group">
                  <label for="heure_debut">
                    <i class="far fa-clock"></i>
                    Heure de début*
                  </label>
                  <input type="time" name="heure_debut" id="heure_debut" class="form-control" value="<?= $date_debut->format('H:i') ?>" required>
                </div>
                
                <div class="form-group">
                  <label for="date_fin">
                    <i class="far fa-calendar-alt"></i>
                    Date de fin*
                  </label>
                  <input type="date" name="date_fin" id="date_fin" class="form-control" value="<?= $date_fin->format('Y-m-d') ?>" required>
                </div>
                
                <div class="form-group">
                  <label for="heure_fin">
                    <i class="far fa-clock"></i>
                    Heure de fin*
                  </label>
                  <input type="time" name="heure_fin" id="heure_fin" class="form-control" value="<?= $date_fin->format('H:i') ?>" required>
                </div>
                
                <div class="form-group">
                  <label for="lieu">
                    <i class="fas fa-map-marker-alt"></i>
                    Lieu
                  </label>
                  <input type="text" name="lieu" id="lieu" class="form-control" value="<?= htmlspecialchars($evenement['lieu'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                  <label for="matieres">
                    <i class="fas fa-book"></i>
                    Matière associée
                  </label>
                  <?php if (isTeacher() && !empty($prof_matiere)): ?>
                    <input type="text" name="matieres" id="matieres" class="form-control" value="<?= htmlspecialchars($prof_matiere) ?>" readonly>
                  <?php else: ?>
                    <input type="text" name="matieres" id="matieres" class="form-control" value="<?= htmlspecialchars($evenement['matieres'] ?? '') ?>">
                  <?php endif; ?>
                </div>
                
                <div class="form-group form-full">
                  <h3 class="section-title">Type d'événement*</h3>
                  <div class="radio-group" id="type-group">
                    <?php foreach ($types_evenements as $key => $type): ?>
                      <label class="radio-option <?= ($evenement['type_evenement'] === $key) ? 'selected' : '' ?>">
                        <input type="radio" name="type_evenement" value="<?= htmlspecialchars($key) ?>" <?= ($evenement['type_evenement'] === $key) ? 'checked' : '' ?> required>
                        <span class="radio-option-icon" style="color: <?= htmlspecialchars($type['couleur']) ?>">
                          <i class="fas fa-<?= htmlspecialchars($type['icone']) ?>"></i>
                        </span>
                        <span><?= htmlspecialchars($type['nom']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                
                <div class="form-group form-full">
                  <h3 class="section-title">Statut de l'événement*</h3>
                  <div class="radio-group" id="statut-group">
                    <?php foreach ($options_statut as $key => $statut): ?>
                      <label class="radio-option <?= ($evenement['statut'] === $key) ? 'selected' : '' ?>">
                        <input type="radio" name="statut" value="<?= htmlspecialchars($key) ?>" <?= ($evenement['statut'] === $key) ? 'checked' : '' ?> required>
                        <span class="radio-option-icon" style="color: <?= htmlspecialchars($statut['couleur']) ?>">
                          <i class="fas fa-<?= htmlspecialchars($statut['icone']) ?>"></i>
                        </span>
                        <span><?= htmlspecialchars($statut['nom']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                
                <div class="form-group form-full">
                  <h3 class="section-title">Visibilité*</h3>
                  <div class="radio-group" id="visibilite-group">
                    <?php foreach ($options_visibilite as $key => $visibilite): ?>
                      <label class="radio-option <?= ($visibilite_actuelle === $key) ? 'selected' : '' ?>">
                        <input type="radio" name="visibilite" value="<?= htmlspecialchars($key) ?>" <?= ($visibilite_actuelle === $key) ? 'checked' : '' ?> required>
                        <span class="radio-option-icon">
                          <i class="fas fa-<?= htmlspecialchars($visibilite['icone']) ?>"></i>
                        </span>
                        <span><?= htmlspecialchars($visibilite['nom']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  
                  <!-- Sélection des classes (visible uniquement si "Classes spécifiques" est sélectionné) -->
                  <div class="classes-selection" id="classes-selection" <?= ($visibilite_actuelle === 'classes_specifiques') ? 'style="display: block;"' : '' ?>>
                    <div class="search-bar">
                      <input type="text" id="classes-search" class="form-control" placeholder="Rechercher une classe..." onkeyup="filterClasses()">
                    </div>
                    <div class="classes-grid">
                      <?php foreach ($classes as $classe): ?>
                        <label class="class-option">
                          <input type="checkbox" name="classes[]" value="<?= htmlspecialchars($classe) ?>" <?= in_array($classe, $classes_selectionnees) ? 'checked' : '' ?>>
                          <?= htmlspecialchars($classe) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                
                <div class="form-full">
                  <div class="form-actions">
                    <a href="details_evenement.php?id=<?= htmlspecialchars($id) ?>" class="btn btn-secondary">
                      <i class="fas fa-times"></i>
                      Annuler
                    </a>
                    <button type="submit" class="btn btn-primary">
                      <i class="fas fa-save"></i>
                      Enregistrer les modifications
                    </button>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <script>
    // Fonctions pour la sélection des options radio avec style
    document.addEventListener('DOMContentLoaded', function() {
        // Gérer les options radio
        document.querySelectorAll('.radio-option').forEach(option => {
          option.addEventListener('click', function() {
            const input = this.querySelector('input[type="radio"]');
            const group = this.closest('.radio-group');
            
            // Désélectionner toutes les options du groupe
            group.querySelectorAll('.radio-option').forEach(opt => {
              opt.classList.remove('selected');
            });
            
            // Sélectionner cette option
            this.classList.add('selected');
            input.checked = true;
            
            // Si c'est le groupe visibilité, gérer l'affichage de la sélection des classes
            if (group.id === 'visibilite-group') {
              const classesSelection = document.getElementById('classes-selection');
              if (input.value === 'classes_specifiques') {
                classesSelection.style.display = 'block';
              } else {
                classesSelection.style.display = 'none';
              }
            }
          });
        });
        
        // Synchroniser les dates de début et de fin
        document.getElementById('date_debut').addEventListener('change', function() {
          const dateFinInput = document.getElementById('date_fin');
          // Si la date de fin est vide ou si elle est avant la date de début
          if (!dateFinInput.value || dateFinInput.value < this.value) {
            dateFinInput.value = this.value;
          }
        });
        
        // Vérifier la cohérence des dates lors de la soumission du formulaire
        document.getElementById('event-form').addEventListener('submit', function(e) {
            const dateDebut = document.getElementById('date_debut').value;
            const heureDebut = document.getElementById('heure_debut').value;
            const dateFin = document.getElementById('date_fin').value;
            const heureFin = document.getElementById('heure_fin').value;
            
            const debutDateTime = new Date(`${dateDebut}T${heureDebut}:00`);
            const finDateTime = new Date(`${dateFin}T${heureFin}:00`);
            
            if (finDateTime <= debutDateTime) {
                e.preventDefault();
                alert("La date/heure de fin doit être postérieure à la date/heure de début.");
            }
        });
    });
    
    // Fonction pour filtrer les classes
    function filterClasses() {
      const searchText = document.getElementById('classes-search').value.toLowerCase();
      document.querySelectorAll('.class-option').forEach(option => {
        const text = option.textContent.trim().toLowerCase();
        option.style.display = text.includes(searchText) ? 'flex' : 'none';
      });
    }
  </script>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>