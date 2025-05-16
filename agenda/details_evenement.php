<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier que l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/login.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: agenda.php');
    exit;
}

$id = $_GET['id'];

// Vérifier si la colonne 'personnes_concernees' existe
try {
    $stmt_check_column = $pdo->query("SHOW COLUMNS FROM evenements LIKE 'personnes_concernees'");
    $personnes_concernees_exists = $stmt_check_column && $stmt_check_column->rowCount() > 0;
} catch (PDOException $e) {
    // La colonne n'existe probablement pas
    $personnes_concernees_exists = false;
}

// Récupérer les détails de l'événement
try {
    $stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
    $stmt->execute([$id]);
    $evenement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Vérifier que l'événement existe
    if (!$evenement) {
        header('Location: agenda.php');
        exit;
    }
} catch (PDOException $e) {
    echo "Erreur lors de la récupération de l'événement : " . $e->getMessage();
    exit;
}

// Vérifier les autorisations (si l'événement est visible pour l'utilisateur)
$can_view = false;

// Administrateurs et vie scolaire peuvent tout voir
if (isAdmin() || isVieScolaire()) {
    $can_view = true;
} 
// Vérifier la visibilité pour les autres rôles
else {
    // Si l'utilisateur est le créateur de l'événement
    if ($evenement['createur'] === $user_fullname) {
        $can_view = true;
    }
    // Si l'événement est public
    elseif ($evenement['visibilite'] === 'public') {
        $can_view = true;
    }
    // Si l'événement est pour les professeurs et l'utilisateur est un professeur
    elseif ($evenement['visibilite'] === 'professeurs' && isTeacher()) {
        $can_view = true;
    }
    // Si l'événement est pour les élèves et l'utilisateur est un élève
    elseif ($evenement['visibilite'] === 'eleves' && isStudent()) {
        $can_view = true;
    }
    // Si l'événement est pour des classes spécifiques
    elseif (strpos($evenement['visibilite'], 'classes:') === 0) {
        $classes_concernees = explode(',', substr($evenement['visibilite'], 8));
        
        // Si l'utilisateur est un élève, vérifier si sa classe est concernée
        if (isStudent()) {
            // Récupérer la classe de l'élève
            $classe_eleve = isset($user['classe']) ? $user['classe'] : '';
            
            if (in_array($classe_eleve, $classes_concernees)) {
                $can_view = true;
            }
        }
        // Si l'utilisateur est un professeur, il peut voir tous les événements pour des classes
        elseif (isTeacher()) {
            $can_view = true;
        }
    }
}

// Si l'utilisateur n'a pas les droits, rediriger
if (!$can_view) {
    header('Location: agenda.php');
    exit;
}

// Déterminer si l'utilisateur peut modifier ou supprimer l'événement
$can_edit = false;
$can_delete = false;

// Administrateurs et vie scolaire peuvent tout modifier/supprimer
if (isAdmin() || isVieScolaire()) {
    $can_edit = true;
    $can_delete = true;
} 
// Les professeurs ne peuvent modifier/supprimer que leurs propres événements
elseif (isTeacher() && $evenement['createur'] === $user_fullname) {
    $can_edit = true;
    $can_delete = true;
}

// Formater les dates pour l'affichage
$date_debut = new DateTime($evenement['date_debut']);
$date_fin = new DateTime($evenement['date_fin']);
$format_date = 'd/m/Y';
$format_heure = 'H:i';

// Déterminer si l'événement est aujourd'hui, demain, passé ou futur
$aujourd_hui = new DateTime();
$demain = new DateTime('tomorrow');
$is_today = $date_debut->format('Y-m-d') === $aujourd_hui->format('Y-m-d');
$is_tomorrow = $date_debut->format('Y-m-d') === $demain->format('Y-m-d');
$is_past = $date_fin < $aujourd_hui;
$is_future = $date_debut > $aujourd_hui;
$days_until = $is_future ? $date_debut->diff($aujourd_hui)->days : 0;

// Déterminer le type d'événement pour l'affichage
$types_evenements = [
    'cours' => ['nom' => 'Cours', 'icone' => 'book', 'couleur' => '#00843d'],
    'devoirs' => ['nom' => 'Devoirs', 'icone' => 'pencil', 'couleur' => '#4285f4'],
    'reunion' => ['nom' => 'Réunion', 'icone' => 'users', 'couleur' => '#ff9800'],
    'examen' => ['nom' => 'Examen', 'icone' => 'file-text', 'couleur' => '#f44336'],
    'sortie' => ['nom' => 'Sortie scolaire', 'icone' => 'map-pin', 'couleur' => '#00c853'],
    'autre' => ['nom' => 'Autre', 'icone' => 'calendar', 'couleur' => '#9e9e9e']
];

$type_info = isset($types_evenements[$evenement['type_evenement']]) 
            ? $types_evenements[$evenement['type_evenement']] 
            : $types_evenements['autre'];

// Préparer les classes pour l'affichage
$classes_array = !empty($evenement['classes']) ? explode(',', $evenement['classes']) : [];

// Préparer le texte de visibilité
$visibilite_texte = '';
$visibilite_icone = 'lock';
if ($evenement['visibilite'] === 'public') {
    $visibilite_texte = 'Public (visible par tous)';
    $visibilite_icone = 'globe';
} elseif ($evenement['visibilite'] === 'professeurs') {
    $visibilite_texte = 'Professeurs uniquement';
    $visibilite_icone = 'user-tie';
} elseif ($evenement['visibilite'] === 'eleves') {
    $visibilite_texte = 'Élèves uniquement';
    $visibilite_icone = 'user-graduate';
} elseif (strpos($evenement['visibilite'], 'classes:') === 0) {
    $classes = substr($evenement['visibilite'], 8);
    $visibilite_texte = 'Classes spécifiques: ' . $classes;
    $visibilite_icone = 'users';
} else {
    $visibilite_texte = $evenement['visibilite'];
}

// Générer le lien iCal
$ical_filename = urlencode(preg_replace('/[^a-z0-9]+/i', '_', $evenement['titre'])) . '.ics';
$ical_link = "export_ical.php?id=" . $evenement['id'] . "&filename=" . $ical_filename;

// Générer un lien de partage
$share_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

// Personnes concernées (si la colonne existe)
$personnes_concernees_array = [];
if ($personnes_concernees_exists && !empty($evenement['personnes_concernees'])) {
    $personnes_concernees_array = explode(',', $evenement['personnes_concernees']);
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($evenement['titre']) ?> - Agenda Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <!-- Rest of your head content -->
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Agenda</div>
      </a>
      
      <!-- Mini-calendrier pour la navigation -->
      <div class="mini-calendar">
        <!-- Le mini-calendrier sera généré dynamiquement -->
      </div>
      
      <!-- Créer un événement -->
      <div class="sidebar-section">
        <a href="ajouter_evenement.php" class="create-button">
          <span>+</span> Créer un événement
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="calendar-navigation">
          <a href="agenda.php" class="back-button">
            <span class="back-icon">
              <i class="fas fa-arrow-left"></i>
            </span>
            Retour à l'agenda
          </a>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Container principal -->
      <div class="calendar-container">
        <div class="event-details-container">
          <div class="event-header">
            <div class="event-header-top">
              <div class="event-title-container">
                <h1 class="event-title"><?= htmlspecialchars($evenement['titre']) ?></h1>
                <div class="event-subtitle">Créé par <?= htmlspecialchars($evenement['createur']) ?></div>
              </div>
              
              <?php if ($evenement['statut'] !== 'actif'): ?>
                <div class="event-status <?= $evenement['statut'] === 'annulé' ? 'cancelled' : 'postponed' ?>">
                  <i class="fas fa-<?= $evenement['statut'] === 'annulé' ? 'ban' : 'clock' ?>"></i>
                  <?= $evenement['statut'] === 'annulé' ? 'Annulé' : 'Reporté' ?>
                </div>
              <?php endif; ?>
            </div>
            
            <div class="event-type" style="background-color: <?= $type_info['couleur'] ?>;">
              <i class="fas fa-<?= $type_info['icone'] ?>"></i>
              <?= $type_info['nom'] ?>
            </div>
            
            <div class="event-timing">
              <div class="event-date-display">
                <i class="far fa-calendar-alt"></i>
                <?php if ($date_debut->format('Y-m-d') === $date_fin->format('Y-m-d')): ?>
                  <?= $date_debut->format($format_date) ?>
                <?php else: ?>
                  Du <?= $date_debut->format($format_date) ?> au <?= $date_fin->format($format_date) ?>
                <?php endif; ?>
                
                <?php if ($is_today): ?>
                  <span class="event-badge today">Aujourd'hui</span>
                <?php elseif ($is_tomorrow): ?>
                  <span class="event-badge tomorrow">Demain</span>
                <?php elseif ($is_future): ?>
                  <span class="event-badge future">Dans <?= $days_until ?> jour<?= $days_until > 1 ? 's' : '' ?></span>
                <?php elseif ($is_past): ?>
                  <span class="event-badge past">Passé</span>
                <?php endif; ?>
              </div>
              
              <div class="event-date-display">
                <i class="far fa-clock"></i>
                <?php if ($date_debut->format('Y-m-d') === $date_fin->format('Y-m-d')): ?>
                  De <?= $date_debut->format($format_heure) ?> à <?= $date_fin->format($format_heure) ?>
                <?php else: ?>
                  De <?= $date_debut->format($format_date . ' à ' . $format_heure) ?> à <?= $date_fin->format($format_date . ' à ' . $format_heure) ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          
          <div class="event-body">
            <!-- Description -->
            <?php if (!empty($evenement['description'])): ?>
            <div class="event-section">
              <h3 class="section-title">
                <i class="fas fa-align-left"></i>
                Description
              </h3>
              <div class="section-content description">
                <?= nl2br(htmlspecialchars($evenement['description'])) ?>
              </div>
            </div>
            <?php endif; ?>
            
            <!-- Informations supplémentaires -->
            <div class="event-section">
              <h3 class="section-title">
                <i class="fas fa-info-circle"></i>
                Informations
              </h3>
              <div class="info-grid">
                <?php if (!empty($evenement['lieu'])): ?>
                <div class="info-item">
                  <div class="info-label">Lieu</div>
                  <div class="info-value">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars($evenement['lieu']) ?>
                  </div>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                  <div class="info-label">Visibilité</div>
                  <div class="info-value">
                    <i class="fas fa-<?= $visibilite_icone ?>"></i>
                    <?= $visibilite_texte ?>
                  </div>
                </div>
                
                <?php if (!empty($evenement['matieres'])): ?>
                <div class="info-item">
                  <div class="info-label">Matière</div>
                  <div class="info-value">
                    <i class="fas fa-book"></i>
                    <?= htmlspecialchars($evenement['matieres']) ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($classes_array)): ?>
              <div class="tags-container">
                <?php foreach ($classes_array as $classe): ?>
                  <div class="tag"><?= htmlspecialchars($classe) ?></div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            
            <?php if ($personnes_concernees_exists && !empty($personnes_concernees_array)): ?>
            <div class="event-section">
              <h3 class="section-title">
                <i class="fas fa-users"></i>
                Personnes concernées
              </h3>
              <div class="tags-container">
                <?php foreach ($personnes_concernees_array as $personne): ?>
                  <div class="tag"><?= htmlspecialchars($personne) ?></div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="event-actions">
              <a href="agenda.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Retour à l'agenda
              </a>
              
              <?php if ($can_edit): ?>
              <a href="modifier_evenement.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i>
                Modifier
              </a>
              <?php endif; ?>
              
              <?php if ($can_delete): ?>
              <a href="supprimer_evenement.php?id=<?= $id ?>" class="btn btn-danger">
                <i class="fas fa-trash-alt"></i>
                Supprimer
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>