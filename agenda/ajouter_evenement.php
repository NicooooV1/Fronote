<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier que l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Date par défaut (aujourd'hui ou la date passée en paramètre)
$date_par_defaut = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$heure_debut_defaut = date('H:i');
$heure_fin_defaut = date('H:i', strtotime('+1 hour'));

// Déterminer les types d'événements disponibles selon le rôle de l'utilisateur
$types_evenements = [];

if (isAdmin() || isVieScolaire()) {
    // Administrateurs et vie scolaire ont accès à tous les types
    $types_evenements = [
        'cours' => 'Cours',
        'devoirs' => 'Devoirs',
        'reunion' => 'Réunion',
        'examen' => 'Examen',
        'sortie' => 'Sortie scolaire',
        'autre' => 'Autre'
    ];
} elseif (isTeacher()) {
    // Les professeurs peuvent créer des cours, devoirs et examens
    $types_evenements = [
        'cours' => 'Cours',
        'devoirs' => 'Devoirs',
        'examen' => 'Examen',
        'autre' => 'Autre'
    ];
} else {
    // Élèves et parents ne peuvent créer que des événements personnels
    $types_evenements = [
        'autre' => 'Événement personnel'
    ];
}

// Options de visibilité selon le rôle
$options_visibilite = [];

if (isAdmin() || isVieScolaire()) {
    $options_visibilite = [
        'public' => 'Public (visible par tous)',
        'professeurs' => 'Professeurs uniquement',
        'eleves' => 'Élèves uniquement',
        'personnel' => 'Personnel administratif uniquement',
        'administration' => 'Administration uniquement',
        'classes_specifiques' => 'Classes spécifiques',
        'personnel' => 'Personnel (visible uniquement par moi)'
    ];
} elseif (isTeacher()) {
    $options_visibilite = [
        'public' => 'Public (visible par tous)',
        'professeurs' => 'Professeurs uniquement',
        'eleves' => 'Élèves uniquement',
        'classes_specifiques' => 'Classes spécifiques',
        'personnel' => 'Personnel (visible uniquement par moi)'
    ];
} else {
    // Élèves et parents - seulement personnel
    $options_visibilite = [
        'personnel' => 'Personnel (visible uniquement par moi)'
    ];
}

// Récupérer la liste des classes
$classes = [];
$json_file = __DIR__ . '/../login/data/etablissement.json';
if (file_exists($json_file)) {
    $etablissement_data = json_decode(file_get_contents($json_file), true);
    
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

// Récupérer la liste des personnels (professeurs, administration, vie scolaire)
$personnels = [];
try {
    // Récupérer les professeurs - Assurons-nous que la table existe avant de faire la requête
    $tableExists = false;
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'professeurs'");
    $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
    
    if ($tableExists) {
        $stmt_profs = $pdo->query('SELECT id, nom, prenom, matiere FROM professeurs ORDER BY nom, prenom');
        $professeurs = $stmt_profs->fetchAll();
        foreach ($professeurs as $personne) {
            $personnels[] = [
                'id' => $personne['id'],
                'nom' => $personne['nom'],
                'prenom' => $personne['prenom'],
                'type' => 'Professeur',
                'matiere' => $personne['matiere'] ?? ''
            ];
        }
    }
    
    // Si nous n'avons pas trouvé de personnel, créer des exemples temporaires 
    // pour éviter que la liste soit vide (à supprimer en production)
    if (empty($personnels)) {
        $personnels = [
            ['id' => 1, 'nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'Professeur', 'matiere' => 'Mathématiques'],
            ['id' => 2, 'nom' => 'Martin', 'prenom' => 'Sophie', 'type' => 'Professeur', 'matiere' => 'Français'],
            ['id' => 3, 'nom' => 'Durand', 'prenom' => 'Michel', 'type' => 'Administration', 'fonction' => 'Proviseur'],
            ['id' => 4, 'nom' => 'Petit', 'prenom' => 'Claire', 'type' => 'Vie scolaire', 'fonction' => 'CPE']
        ];
    }
} catch (PDOException $e) {
    // En cas d'erreur, utiliser des exemples temporaires
    $personnels = [
        ['id' => 1, 'nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'Professeur', 'matiere' => 'Mathématiques'],
        ['id' => 2, 'nom' => 'Martin', 'prenom' => 'Sophie', 'type' => 'Professeur', 'matiere' => 'Français'],
        ['id' => 3, 'nom' => 'Durand', 'prenom' => 'Michel', 'type' => 'Administration', 'fonction' => 'Proviseur'],
        ['id' => 4, 'nom' => 'Petit', 'prenom' => 'Claire', 'type' => 'Vie scolaire', 'fonction' => 'CPE']
    ];
}

// Si c'est un professeur, récupérer sa matière
$prof_matiere = '';
if (isTeacher()) {
    $stmt_prof = $pdo->prepare('SELECT matiere FROM professeurs WHERE nom = ? AND prenom = ?');
    $stmt_prof->execute([$user['nom'], $user['prenom']]);
    $prof_data = $stmt_prof->fetch();
    $prof_matiere = $prof_data ? $prof_data['matiere'] : '';
}

// Traitement du formulaire
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des champs obligatoires
    if (empty($_POST['titre']) || empty($_POST['date_debut']) || empty($_POST['heure_debut']) || 
        empty($_POST['date_fin']) || empty($_POST['heure_fin']) || empty($_POST['type_evenement'])) {
        $erreur = "Veuillez remplir tous les champs obligatoires.";
    } else {
        // Vérifier que le type d'événement est autorisé pour ce rôle
        if (!array_key_exists($_POST['type_evenement'], $types_evenements)) {
            $erreur = "Le type d'événement sélectionné n'est pas autorisé pour votre profil.";
        } else {
            // Formatage des dates
            $date_debut = $_POST['date_debut'] . ' ' . $_POST['heure_debut'] . ':00';
            $date_fin = $_POST['date_fin'] . ' ' . $_POST['heure_fin'] . ':00';
            
            // Vérifier que la date de fin est après la date de début
            if (strtotime($date_fin) <= strtotime($date_debut)) {
                $erreur = "La date/heure de fin doit être après la date/heure de début.";
            } else {
                // Traitement de la visibilité et des classes sélectionnées
                $visibilite = $_POST['visibilite'];
                $classes_selectionnees = '';
                
                if ($visibilite === 'classes_specifiques' && !empty($_POST['classes'])) {
                    $classes_selectionnees = is_array($_POST['classes']) ? implode(',', $_POST['classes']) : $_POST['classes'];
                    $visibilite = 'classes:' . $classes_selectionnees;
                }
                
                // Traitement des personnes concernées
                $personnes_concernees = '';
                if (!empty($_POST['personnes'])) {
                    $personnes_concernees = is_array($_POST['personnes']) ? implode(',', $_POST['personnes']) : $_POST['personnes'];
                }
                
                // Type personnalisé pour les événements de type "autre"
                $type_personnalise = '';
                if ($_POST['type_evenement'] === 'autre' && !empty($_POST['type_personnalise'])) {
                    $type_personnalise = $_POST['type_personnalise'];
                }
                
                // Insertion dans la base de données
                try {
                    $stmt = $pdo->prepare('INSERT INTO evenements (titre, description, date_debut, date_fin, type_evenement, type_personnalise, statut, createur, visibilite, personnes_concernees, lieu, classes, matieres) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    
                    $stmt->execute([
                        $_POST['titre'],
                        $_POST['description'] ?? '',
                        $date_debut,
                        $date_fin,
                        $_POST['type_evenement'],
                        $type_personnalise,
                        'actif', // Statut par défaut
                        $user_fullname,
                        $visibilite,
                        $personnes_concernees,
                        $_POST['lieu'] ?? '',
                        $classes_selectionnees,
                        $_POST['matieres'] ?? $prof_matiere
                    ]);
                    
                    $message = "L'événement a été ajouté avec succès.";
                    
                    // Redirection après un court délai
                    header('refresh:2;url=agenda.php');
                } catch (PDOException $e) {
                    $erreur = "Erreur lors de l'ajout de l'événement : " . $e->getMessage();
                }
            }
        }
    }
}

// Ajouter un gestionnaire AJAX pour récupérer les personnes selon la visibilité
if (isset($_GET['action']) && $_GET['action'] === 'get_persons' && isset($_GET['visibility'])) {
    header('Content-Type: application/json');
    $visibility = $_GET['visibility'];
    $persons = [];
    
    try {
        // Vérifier si l'utilisateur a les droits nécessaires
        if (!isAdmin() && !isTeacher() && !isVieScolaire()) {
            throw new Exception('Accès non autorisé');
        }
        
        switch ($visibility) {
            case 'eleves':
                $stmt = $pdo->query("SELECT id, nom, prenom, classe FROM eleves ORDER BY nom, prenom");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id' => $row['id'],
                        'name' => $row['prenom'] . ' ' . $row['nom'],
                        'info' => $row['classe'],
                        'type' => 'eleve'
                    ];
                }
                break;
                
            case 'professeurs':
                $stmt = $pdo->query("SELECT id, nom, prenom, matiere FROM professeurs ORDER BY nom, prenom");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id' => $row['id'],
                        'name' => $row['prenom'] . ' ' . $row['nom'],
                        'info' => $row['matiere'] ?? 'Professeur',
                        'type' => 'professeur'
                    ];
                }
                break;
                
            case 'parents':
                $stmt = $pdo->query("SELECT p.id, p.nom, p.prenom, 
                                    GROUP_CONCAT(DISTINCT e.prenom SEPARATOR ', ') AS enfants 
                                    FROM parents p 
                                    LEFT JOIN parents_eleves pe ON p.id = pe.id_parent 
                                    LEFT JOIN eleves e ON pe.id_eleve = e.id 
                                    GROUP BY p.id 
                                    ORDER BY p.nom, p.prenom");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id' => $row['id'],
                        'name' => $row['prenom'] . ' ' . $row['nom'],
                        'info' => 'Parent de : ' . ($row['enfants'] ?: 'Non défini'),
                        'type' => 'parent'
                    ];
                }
                break;
                
            case 'vie_scolaire':
                $stmt = $pdo->query("SELECT id, nom, prenom, fonction FROM personnels 
                                    WHERE fonction LIKE '%scolaire%' OR service = 'vie scolaire' 
                                    ORDER BY nom, prenom");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id' => $row['id'],
                        'name' => $row['prenom'] . ' ' . $row['nom'],
                        'info' => $row['fonction'] ?? 'Vie scolaire',
                        'type' => 'personnel'
                    ];
                }
                break;
                
            case 'administration':
                $stmt = $pdo->query("SELECT id, nom, prenom, fonction FROM personnels 
                                    WHERE fonction LIKE '%admin%' OR service = 'administration' 
                                    ORDER BY nom, prenom");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id' => $row['id'],
                        'name' => $row['prenom'] . ' ' . $row['nom'],
                        'info' => $row['fonction'] ?? 'Administration',
                        'type' => 'personnel'
                    ];
                }
                break;
                
            // Pour les classes spécifiques (au format 'classes:NomClasse')
            default:
                if (strpos($visibility, 'classes:') === 0) {
                    $classe = substr($visibility, 8); // Récupère ce qui vient après "classes:"
                    $stmt = $pdo->prepare("SELECT id, nom, prenom FROM eleves WHERE classe = ? ORDER BY nom, prenom");
                    $stmt->execute([$classe]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $persons[] = [
                            'id' => $row['id'],
                            'name' => $row['prenom'] . ' ' . $row['nom'],
                            'info' => $classe,
                            'type' => 'eleve'
                        ];
                    }
                }
                break;
        }
        
        // Si aucune personne trouvée, générer quelques exemples pour éviter une liste vide
        if (empty($persons)) {
            switch ($visibility) {
                case 'eleves':
                    $persons = [
                        ['id' => 'e1', 'name' => 'Louis Martin', 'info' => '2nde A', 'type' => 'eleve'],
                        ['id' => 'e2', 'name' => 'Emma Bernard', 'info' => '2nde B', 'type' => 'eleve'],
                        ['id' => 'e3', 'name' => 'Lucas Petit', 'info' => '1ère S', 'type' => 'eleve']
                    ];
                    break;
                case 'professeurs':
                    $persons = [
                        ['id' => 'p1', 'name' => 'Marie Dubois', 'info' => 'Mathématiques', 'type' => 'professeur'],
                        ['id' => 'p2', 'name' => 'Jean Dupont', 'info' => 'Français', 'type' => 'professeur'],
                        ['id' => 'p3', 'name' => 'Sophie Moreau', 'info' => 'Histoire-Géographie', 'type' => 'professeur']
                    ];
                    break;
                case 'parents':
                    $persons = [
                        ['id' => 'pa1', 'name' => 'Philippe Martin', 'info' => 'Parent de : Louis Martin', 'type' => 'parent'],
                        ['id' => 'pa2', 'name' => 'Christine Bernard', 'info' => 'Parent de : Emma Bernard', 'type' => 'parent']
                    ];
                    break;
                case 'vie_scolaire':
                    $persons = [
                        ['id' => 'vs1', 'name' => 'Valérie Lefevre', 'info' => 'CPE', 'type' => 'personnel'],
                        ['id' => 'vs2', 'name' => 'Thomas Roux', 'info' => 'Assistant d\'éducation', 'type' => 'personnel']
                    ];
                    break;
                case 'administration':
                    $persons = [
                        ['id' => 'a1', 'name' => 'Michel Durand', 'info' => 'Proviseur', 'type' => 'personnel'],
                        ['id' => 'a2', 'name' => 'Claire Petit', 'info' => 'Secrétaire', 'type' => 'personnel']
                    ];
                    break;
                default:
                    if (strpos($visibility, 'classes_specifiques') === 0) {
                        $classe = "Classe spécifique";
                        $persons = [
                            ['id' => 'ec1', 'name' => 'Alice Dumont', 'info' => $classe, 'type' => 'eleve'],
                            ['id' => 'ec2', 'name' => 'Hugo Lefebvre', 'info' => $classe, 'type' => 'eleve']
                        ];
                    }
                    break;
            }
        }
        
        echo json_encode(['success' => true, 'persons' => $persons]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ajouter un événement - Agenda Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <style>
    /* Styles spécifiques pour la page de création d'événement */
    body {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', Arial, sans-serif;
      background-color: #f5f5f5;
      color: #333;
    }
    
    /* Container principal */
    .event-creation-container {
      max-width: 800px;
      margin: 20px auto;
      background-color: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      padding: 0;
    }
    
    /* En-tête avec bouton retour */
    .event-creation-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px 20px;
      border-bottom: 1px solid #eee;
    }
    
    .event-creation-header h2 {
      margin: 0;
      color: #333;
      font-weight: 500;
      font-size: 20px;
    }
    
    .role-indicator {
      padding: 4px 8px;
      background-color: #e0f2e9;
      color: #00843d;
      border-radius: 4px;
      font-size: 14px;
      font-weight: 500;
    }
    
    .back-button {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      background-color: #f5f5f5;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
      color: #555;
      text-decoration: none;
      transition: background-color 0.2s;
    }
    
    .back-button:hover {
      background-color: #e0e0e0;
    }
    
    /* Formulaire */
    .event-creation-form {
      padding: 20px;
    }
    
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
    }
    
    .form-full {
      grid-column: 1 / -1;
    }
    
    .form-group {
      margin-bottom: 15px;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
      color: #555;
      font-size: 14px;
    }
    
    .form-group input[type="text"],
    .form-group input[type="date"],
    .form-group input[type="time"],
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
      transition: border-color 0.3s, box-shadow 0.3s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: #00843d;
      outline: none;
      box-shadow: 0 0 0 2px rgba(0,132,61,0.1);
    }
    
    /* Type personnalisé */
    .type-personnalise {
      margin-top: 10px;
      display: none;
    }
    
    /* Actions du formulaire */
    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    
    .btn-cancel {
      padding: 10px 15px;
      background-color: #f5f5f5;
      color: #333;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      transition: background-color 0.2s;
    }
    
    .btn-cancel:hover {
      background-color: #e0e0e0;
    }
    
    .btn-submit {
      padding: 10px 15px;
      background-color: #00843d;
      color: white;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
      transition: background-color 0.2s;
    }
    
    .btn-submit:hover {
      background-color: #006e32;
    }
    
    /* Messages */
    .message {
      padding: 10px 15px;
      margin-bottom: 20px;
      border-radius: 4px;
    }
    
    .message.success {
      background-color: #e0f2e9;
      color: #00843d;
      border: 1px solid #00843d;
    }
    
    .message.error {
      background-color: #ffe6e6;
      color: #f44336;
      border: 1px solid #f44336;
    }
    
    /* Classes multiselect */
    .multiselect-container {
      border: 1px solid #ddd;
      border-radius: 4px;
      overflow: hidden;
      max-height: 300px;
      display: flex;
      flex-direction: column;
    }
    
    .multiselect-search {
      padding: 10px;
      border-bottom: 1px solid #eee;
    }
    
    .multiselect-search input {
      width: 100%;
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .multiselect-actions {
      padding: 10px;
      display: flex;
      gap: 10px;
      border-bottom: 1px solid #eee;
    }
    
    .multiselect-action {
      background: none;
      border: none;
      color: #00843d;
      cursor: pointer;
      font-size: 12px;
      padding: 0;
    }
    
    .multiselect-action:hover {
      text-decoration: underline;
    }
    
    .multiselect-options {
      padding: 10px;
      overflow-y: auto;
      flex: 1;
      max-height: 200px;
    }
    
    .multiselect-option {
      margin-bottom: 5px;
    }
    
    .multiselect-option label {
      display: flex;
      align-items: center;
      font-weight: normal;
      cursor: pointer;
      padding: 4px 0;
    }
    
    .multiselect-option input[type="checkbox"] {
      margin-right: 8px;
      width: auto;
    }
    
    /* Styles pour le sélecteur de personnes */
    .persons-selector {
      margin-top: 15px;
    }
    
    .persons-list {
      max-height: 300px;
      overflow-y: auto;
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 10px;
      margin-top: 10px;
    }
    
    .person-item {
      display: flex;
      align-items: center;
      padding: 5px 0;
      border-bottom: 1px solid #eee;
    }
    
    .person-item:last-child {
      border-bottom: none;
    }
    
    .person-checkbox {
      margin-right: 10px;
    }
    
    .person-name {
      font-weight: 500;
    }
    
    .person-info {
      font-size: 0.85em;
      color: #666;
      margin-left: 10px;
    }
    
    .persons-search {
      width: 100%;
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
      margin-bottom: 10px;
    }
    
    .persons-actions {
      display: flex;
      justify-content: space-between;
      margin-bottom: 10px;
    }
    
    .persons-action {
      background: none;
      border: none;
      color: #00843d;
      cursor: pointer;
      padding: 5px;
    }
    
    .persons-count {
      margin-top: 10px;
      font-size: 0.9em;
      color: #666;
    }
    
    .loading-indicator {
      text-align: center;
      padding: 15px;
      font-style: italic;
      color: #666;
    }
    
    .no-persons {
      padding: 15px;
      text-align: center;
      color: #666;
      font-style: italic;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .event-creation-container {
        margin: 0;
        border-radius: 0;
        max-width: none;
      }
    }
  </style>
</head>
<body>
  <div class="event-creation-container">
    <div class="event-creation-header">
      <a href="agenda.php" class="back-button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M10 19L3 12L10 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M3 12H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Retour
      </a>
      <h2>Ajouter un événement</h2>
      <?php if ($user_role === 'eleve' || $user_role === 'parent'): ?>
        <div class="role-indicator">Événement personnel</div>
      <?php endif; ?>
    </div>
    
    <div class="event-creation-form">
      <?php if ($message): ?>
        <div class="message success"><?= $message ?></div>
      <?php endif; ?>
      
      <?php if ($erreur): ?>
        <div class="message error"><?= $erreur ?></div>
      <?php endif; ?>
      
      <form method="post">
        <div class="form-grid">
          <div class="form-group form-full">
            <label for="titre">Titre*</label>
            <input type="text" name="titre" id="titre" required placeholder="Titre de l'événement">
          </div>
          
          <div class="form-group">
            <label for="type_evenement">Type d'événement*</label>
            <select name="type_evenement" id="type_evenement" required onchange="toggleTypePersonnalise()">
              <?php if (count($types_evenements) > 1): ?>
                <option value="">Sélectionnez un type</option>
              <?php endif; ?>
              <?php foreach ($types_evenements as $code => $nom): ?>
                <option value="<?= $code ?>"><?= $nom ?></option>
              <?php endforeach; ?>
            </select>
            
            <!-- Champ pour le type personnalisé (affiché seulement si "Autre" est sélectionné) -->
            <div id="type_personnalise_container" class="type-personnalise">
              <label for="type_personnalise">Précisez le type</label>
              <input type="text" name="type_personnalise" id="type_personnalise" placeholder="Type d'événement personnalisé">
            </div>
          </div>
          
          <div class="form-group">
            <label for="visibilite">Visibilité*</label>
            <select id="visibilite" name="visibilite" required>
              <option value="public">Public - Visible par tous</option>
              <option value="professeurs">Professeurs uniquement</option>
              <option value="eleves">Élèves uniquement</option>
              <option value="parents">Parents uniquement</option>
              <option value="vie_scolaire">Vie scolaire uniquement</option>
              <option value="administration">Administration uniquement</option>
              
              <?php foreach ($classes as $classe): ?>
                  <option value="classes:<?= htmlspecialchars($classe) ?>">Classe: <?= htmlspecialchars($classe) ?></option>
              <?php endforeach; ?>
            </select>
            <small>Détermine qui peut voir cet événement.</small>
          </div>
          
          <div class="form-group">
            <label for="date_debut">Date de début*</label>
            <input type="date" name="date_debut" id="date_debut" value="<?= $date_par_defaut ?>" required>
          </div>
          
          <div class="form-group">
            <label for="heure_debut">Heure de début*</label>
            <input type="time" name="heure_debut" id="heure_debut" value="<?= $heure_debut_defaut ?>" required>
          </div>
          
          <div class="form-group">
            <label for="date_fin">Date de fin*</label>
            <input type="date" name="date_fin" id="date_fin" value="<?= $date_par_defaut ?>" required>
          </div>
          
          <div class="form-group">
            <label for="heure_fin">Heure de fin*</label>
            <input type="time" name="heure_fin" id="heure_fin" value="<?= $heure_fin_defaut ?>" required>
          </div>
          
          <!-- Section pour les classes (visible uniquement si "Classes spécifiques" est sélectionné) -->
          <div id="section_classes" class="form-group form-full" style="display: none;">
            <label>Classes concernées</label>
            <div class="multiselect-container">
              <div class="multiselect-search">
                <input type="text" id="classes_search" placeholder="Rechercher une classe" oninput="filterOptions('classes_search', 'class-option')">
              </div>
              <div class="multiselect-actions">
                <button type="button" class="multiselect-action" onclick="selectAll('class-checkbox')">Tout sélectionner</button>
                <button type="button" class="multiselect-action" onclick="deselectAll('class-checkbox')">Tout désélectionner</button>
              </div>
              <div class="multiselect-options">
                <?php foreach ($classes as $classe): ?>
                  <div class="multiselect-option class-option">
                    <label>
                      <input type="checkbox" name="classes[]" class="class-checkbox" value="<?= $classe ?>">
                      <?= $classe ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          
          <!-- Personnes concernées -->
          <div class="form-group form-full" id="personnesContainer">
            <label for="personnes">Personnes concernées</label>
            <div class="persons-selector">
              <div class="persons-actions">
                <button type="button" class="persons-action" id="selectAllPersons">Tout sélectionner</button>
                <button type="button" class="persons-action" id="deselectAllPersons">Tout désélectionner</button>
              </div>
              <input type="text" id="searchPersons" class="persons-search" placeholder="Rechercher...">
              <div class="persons-list" id="personsList">
                <div class="loading-indicator">Chargement des personnes concernées...</div>
              </div>
              <div class="persons-count" id="personsCount">0 personne(s) sélectionnée(s)</div>
            </div>
            <small>Sélectionnez les personnes spécifiquement concernées par cet événement.</small>
          </div>
          
          <div class="form-group">
            <label for="lieu">Lieu</label>
            <input type="text" name="lieu" id="lieu" placeholder="Salle, bâtiment, etc.">
          </div>
          
          <?php if (isTeacher()): ?>
            <div class="form-group">
              <label for="matieres">Matière associée</label>
              <input type="text" name="matieres" id="matieres" value="<?= htmlspecialchars($prof_matiere) ?>" readonly>
            </div>
          <?php else: ?>
            <div class="form-group">
              <label for="matieres">Matière associée</label>
              <input type="text" name="matieres" id="matieres" placeholder="Matière concernée">
            </div>
          <?php endif; ?>
          
          <div class="form-group form-full">
            <label for="description">Description</label>
            <textarea name="description" id="description" rows="4" placeholder="Détails de l'événement..."></textarea>
          </div>
          
          <div class="form-full">
            <div class="form-actions">
              <a href="agenda.php" class="btn-cancel">Annuler</a>
              <button type="submit" class="btn-submit">Créer l'événement</button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
  
  <script>
    // Synchroniser les dates de début et de fin
    document.getElementById('date_debut').addEventListener('change', function() {
      const dateFinInput = document.getElementById('date_fin');
      // Si la date de fin est vide ou si elle est avant la date de début
      if (!dateFinInput.value || dateFinInput.value < this.value) {
        dateFinInput.value = this.value;
      }
    });
    
    // Afficher/masquer la section des classes selon la visibilité sélectionnée
    function toggleClassesSection() {
      const visibiliteSelect = document.getElementById('visibilite');
      const sectionClasses = document.getElementById('section_classes');
      
      if (visibiliteSelect.value === 'classes_specifiques') {
        sectionClasses.style.display = 'block';
      } else {
        sectionClasses.style.display = 'none';
      }
    }
    
    // Afficher/masquer le champ de type personnalisé
    function toggleTypePersonnalise() {
      const typeSelect = document.getElementById('type_evenement');
      const typePersonnaliseContainer = document.getElementById('type_personnalise_container');
      
      if (typeSelect.value === 'autre') {
        typePersonnaliseContainer.style.display = 'block';
      } else {
        typePersonnaliseContainer.style.display = 'none';
      }
    }
    
    // Fonction pour filtrer les options dans un multiselect
    function filterOptions(searchId, optionClass) {
      const searchText = document.getElementById(searchId).value.toLowerCase();
      document.querySelectorAll('.' + optionClass).forEach(option => {
        const text = option.textContent.toLowerCase();
        option.style.display = text.includes(searchText) ? 'block' : 'none';
      });
    }
    
    // Sélectionner toutes les options
    function selectAll(checkboxClass) {
      document.querySelectorAll('.' + checkboxClass).forEach(checkbox => {
        checkbox.checked = true;
      });
    }
    
    // Désélectionner toutes les options
    function deselectAll(checkboxClass) {
      document.querySelectorAll('.' + checkboxClass).forEach(checkbox => {
        checkbox.checked = false;
      });
    }
    
    // Fonction pour gérer le chargement des personnes en fonction de la visibilité
    document.addEventListener('DOMContentLoaded', function() {
      const visibiliteSelect = document.getElementById('visibilite');
      const personnesContainer = document.getElementById('personnesContainer');
      const personsList = document.getElementById('personsList');
      const searchInput = document.getElementById('searchPersons');
      const selectAllBtn = document.getElementById('selectAllPersons');
      const deselectAllBtn = document.getElementById('deselectAllPersons');
      const personsCount = document.getElementById('personsCount');
      
      let selectedPersons = [];
      
      // Fonction pour charger les personnes selon la visibilité
      function loadPersons(visibility) {
        personsList.innerHTML = '<div class="loading-indicator">Chargement des personnes concernées...</div>';
        
        // Définir la visibilité du conteneur en fonction du type de visibilité
        if (visibility === 'public') {
          personnesContainer.style.display = 'none';
        } else {
          personnesContainer.style.display = 'block';
        }
        
        // Appel AJAX pour récupérer les personnes
        fetch(`ajouter_evenement.php?action=get_persons&visibility=${encodeURIComponent(visibility)}`)
          .then(response => response.json())
          .then(data => {
            if (data.success && data.persons.length > 0) {
              renderPersons(data.persons);
            } else {
              personsList.innerHTML = '<div class="no-persons">Aucune personne trouvée pour cette visibilité.</div>';
            }
          })
          .catch(error => {
            console.error('Erreur lors du chargement des personnes:', error);
            personsList.innerHTML = '<div class="no-persons">Erreur lors du chargement des personnes.</div>';
          });
      }
      
      // Fonction pour afficher les personnes
      function renderPersons(persons) {
        personsList.innerHTML = '';
        
        persons.forEach(person => {
          const personItem = document.createElement('div');
          personItem.className = 'person-item';
          
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.className = 'person-checkbox';
          checkbox.name = 'personnes_concernees[]';
          checkbox.value = `${person.type}:${person.id}`;
          checkbox.id = `person-${person.type}-${person.id}`;
          checkbox.addEventListener('change', updateSelectedCount);
          
          const label = document.createElement('label');
          label.htmlFor = checkbox.id;
          label.className = 'person-label';
          
          const nameSpan = document.createElement('span');
          nameSpan.className = 'person-name';
          nameSpan.textContent = person.name;
          
          const infoSpan = document.createElement('span');
          infoSpan.className = 'person-info';
          infoSpan.textContent = person.info || '';
          
          label.appendChild(nameSpan);
          label.appendChild(infoSpan);
          
          personItem.appendChild(checkbox);
          personItem.appendChild(label);
          
          personsList.appendChild(personItem);
        });
        
        updateSelectedCount();
      }
      
      // Fonction pour filtrer les personnes
      function filterPersons() {
        const searchTerm = searchInput.value.toLowerCase();
        const personItems = document.querySelectorAll('.person-item');
        
        personItems.forEach(item => {
          const name = item.querySelector('.person-name').textContent.toLowerCase();
          const info = item.querySelector('.person-info').textContent.toLowerCase();
          
          if (name.includes(searchTerm) || info.includes(searchTerm)) {
            item.style.display = 'flex';
          } else {
            item.style.display = 'none';
          }
        });
      }
      
      // Fonction pour mettre à jour le compteur de sélection
      function updateSelectedCount() {
        const checkedBoxes = document.querySelectorAll('.person-checkbox:checked');
        personsCount.textContent = checkedBoxes.length + ' personne(s) sélectionnée(s)';
      }
      
      // Initialisation des écouteurs d'événements
      visibiliteSelect.addEventListener('change', function() {
        loadPersons(this.value);
      });
      
      searchInput.addEventListener('input', filterPersons);
      
      selectAllBtn.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.person-checkbox').forEach(checkbox => {
          checkbox.checked = true;
        });
        updateSelectedCount();
      });
      
      deselectAllBtn.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.person-checkbox').forEach(checkbox => {
          checkbox.checked = false;
        });
        updateSelectedCount();
      });
      
      // Charger les personnes au chargement initial selon la valeur par défaut
      loadPersons(visibiliteSelect.value);
    });
    
    // Initialiser les sections cachées au chargement
    window.addEventListener('load', function() {
      toggleClassesSection();
      toggleTypePersonnalise();
    });
  </script>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>