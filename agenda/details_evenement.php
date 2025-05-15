<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

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

// Récupérer les détails de l'événement
$stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
$stmt->execute([$id]);
$evenement = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifier que l'événement existe
if (!$evenement) {
    header('Location: agenda.php');
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
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($evenement['titre']) ?> - Agenda Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    /* Styles spécifiques pour la page de détails d'événement */
    .event-details-container {
      max-width: 800px;
      margin: 20px auto;
      background-color: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    
    .event-header {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 10px;
      padding: 25px;
      border-bottom: 1px solid #eee;
      position: relative;
    }
    
    .event-header-top {
      display: flex;
      width: 100%;
      justify-content: space-between;
      align-items: flex-start;
    }
    
    .event-status {
      position: absolute;
      top: 20px;
      right: 20px;
      display: flex;
      align-items: center;
      font-size: 14px;
      font-weight: 500;
      padding: 4px 8px;
      border-radius: 4px;
    }
    
    .event-status.active {
      background-color: #e0f2e9;
      color: #00843d;
    }
    
    .event-status.cancelled {
      background-color: #fee8e7;
      color: #f44336;
    }
    
    .event-status.postponed {
      background-color: #fff8e1;
      color: #ff9800;
    }
    
    .event-title-container {
      max-width: 80%;
    }
    
    .event-title {
      font-size: 24px;
      font-weight: 500;
      color: #333;
      margin: 0;
    }
    
    .event-subtitle {
      color: #666;
      margin-top: 5px;
      font-size: 14px;
    }
    
    .event-type {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 14px;
      font-weight: 500;
      color: white;
    }
    
    .event-timing {
      margin-top: 15px;
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    
    .event-date-display {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 16px;
    }
    
    .event-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
      margin-left: 10px;
    }
    
    .event-badge.today {
      background-color: #e0f2e9;
      color: #00843d;
    }
    
    .event-badge.tomorrow {
      background-color: #e3f2fd;
      color: #2196f3;
    }
    
    .event-badge.future {
      background-color: #f1f3f4;
      color: #5f6368;
    }
    
    .event-badge.past {
      background-color: #eeeeee;
      color: #757575;
    }
    
    .event-body {
      padding: 25px;
    }
    
    .event-section {
      margin-bottom: 25px;
      position: relative;
    }
    
    .event-section:last-child {
      margin-bottom: 0;
    }
    
    .section-title {
      font-size: 16px;
      font-weight: 500;
      margin-bottom: 15px;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #444;
    }
    
    .section-title i {
      color: #777;
    }
    
    .section-content {
      color: #555;
      line-height: 1.5;
    }
    
    .section-content.description {
      background-color: #f9f9f9;
      padding: 15px;
      border-radius: 4px;
      white-space: pre-line;
    }
    
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 15px;
    }
    
    .info-item {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    
    .info-label {
      font-size: 13px;
      color: #777;
    }
    
    .info-value {
      font-weight: 500;
      color: #444;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .info-value i {
      color: #666;
      width: 16px;
    }
    
    .tags-container {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 10px;
    }
    
    .tag {
      background-color: #f1f3f4;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 13px;
      color: #5f6368;
    }
    
    .event-actions {
      display: flex;
      gap: 10px;
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
      text-decoration: none;
      transition: all 0.2s;
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
    
    .btn-danger {
      background-color: #fce8e6;
      color: #d93025;
    }
    
    .btn-danger:hover {
      background-color: #f9d1cd;
    }
    
    .btn-outline {
      background-color: transparent;
      border: 1px solid #ddd;
      color: #444;
    }
    
    .btn-outline:hover {
      background-color: #f9f9f9;
    }
    
    .share-dropdown {
      position: relative;
      display: inline-block;
    }
    
    .share-menu {
      position: absolute;
      top: 100%;
      right: 0;
      margin-top: 5px;
      background-color: white;
      border-radius: 4px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      width: 200px;
      z-index: 100;
      padding: 8px;
      display: none;
    }
    
    .share-option {
      padding: 8px 12px;
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
      border-radius: 4px;
      transition: background-color 0.2s;
    }
    
    .share-option:hover {
      background-color: #f5f5f5;
    }
    
    .copy-link-success {
      position: fixed;
      bottom: 20px;
      left: 50%;
      transform: translateX(-50%);
      background-color: #323232;
      color: white;
      padding: 10px 20px;
      border-radius: 4px;
      font-size: 14px;
      display: none;
      z-index: 1000;
    }
    
    /* Modal de confirmation */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      display: none;
    }
    
    .modal-container {
      background-color: white;
      border-radius: 8px;
      padding: 20px;
      width: 100%;
      max-width: 400px;
    }
    
    .modal-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 15px;
    }
    
    .modal-title {
      font-size: 18px;
      font-weight: 500;
      color: #333;
    }
    
    .modal-body {
      margin-bottom: 20px;
      color: #555;
    }
    
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .event-header {
        padding: 20px 15px;
      }
      
      .event-title {
        font-size: 20px;
      }
      
      .event-status {
        top: 15px;
        right: 15px;
      }
      
      .event-body {
        padding: 20px 15px;
      }
      
      .info-grid {
        grid-template-columns: 1fr;
      }
      
      .event-actions {
        flex-wrap: wrap;
      }
      
      .btn {
        flex: 1;
        min-width: 120px;
        justify-content: center;
      }
    }