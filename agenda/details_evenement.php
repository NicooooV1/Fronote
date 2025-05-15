<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];

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
      // On suppose qu'il y a un moyen de récupérer la classe de l'élève
      $classe_eleve = ''; // À remplacer par la classe réelle de l'élève
      
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

// Déterminer le type d'événement pour l'affichage
$types_evenements = [
  'cours' => 'Cours',
  'devoirs' => 'Devoirs',
  'reunion' => 'Réunion',
  'examen' => 'Examen',
  'sortie' => 'Sortie scolaire',
  'autre' => 'Autre'
];

$type_affichage = isset($types_evenements[$evenement['type_evenement']]) 
                  ? $types_evenements[$evenement['type_evenement']] 
                  : $evenement['type_evenement'];

// Préparer le texte de visibilité
$visibilite_texte = '';
if ($evenement['visibilite'] === 'public') {
  $visibilite_texte = 'Public (visible par tous)';
} elseif ($evenement['visibilite'] === 'professeurs') {
  $visibilite_texte = 'Professeurs uniquement';
} elseif ($evenement['visibilite'] === 'eleves') {
  $visibilite_texte = 'Élèves uniquement';
} elseif (strpos($evenement['visibilite'], 'classes:') === 0) {
  $classes = substr($evenement['visibilite'], 8);
  $visibilite_texte = 'Classes spécifiques: ' . $classes;
} else {
  $visibilite_texte = $evenement['visibilite'];
}
?>

<div class="container">
  <div class="event-details-container">
    <h3>Détails de l'événement</h3>
    
    <!-- En-tête avec le titre et le type -->
    <div class="event-header">
      <h2><?= htmlspecialchars($evenement['titre']) ?></h2>
      <div class="event-type event-<?= $evenement['type_evenement'] ?>"><?= $type_affichage ?></div>
    </div>
    
    <!-- Informations principales -->
    <div class="event-main-info">
      <div class="event-date-time">
        <div class="info-label">Date et heure:</div>
        <div class="info-value">
          <?php if ($date_debut->format('Y-m-d') === $date_fin->format('Y-m-d')): ?>
            Le <?= $date_debut->format($format_date) ?> de <?= $date_debut->format($format_heure) ?> à <?= $date_fin->format($format_heure) ?>
          <?php else: ?>
            Du <?= $date_debut->format($format_date) ?> à <?= $date_debut->format($format_heure) ?> 
            au <?= $date_fin->format($format_date) ?> à <?= $date_fin->format($format_heure) ?>
          <?php endif; ?>
        </div>
      </div>
      
      <?php if (!empty($evenement['lieu'])): ?>
      <div class="event-location">
        <div class="info-label">Lieu:</div>
        <div class="info-value"><?= htmlspecialchars($evenement['lieu']) ?></div>
      </div>
      <?php endif; ?>
      
      <div class="event-creator">
        <div class="info-label">Créé par:</div>
        <div class="info-value"><?= htmlspecialchars($evenement['createur']) ?></div>
      </div>
    </div>
    
    <!-- Description de l'événement -->
    <?php if (!empty($evenement['description'])): ?>
    <div class="event-description">
      <div class="info-label">Description:</div>
      <div class="info-value"><?= nl2br(htmlspecialchars($evenement['description'])) ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Informations supplémentaires -->
    <div class="event-additional-info">
      <?php if (!empty($evenement['matieres'])): ?>
      <div class="event-subject">
        <div class="info-label">Matière:</div>
        <div class="info-value"><?= htmlspecialchars($evenement['matieres']) ?></div>
      </div>
      <?php endif; ?>
      
      <?php if (!empty($evenement['classes'])): ?>
      <div class="event-classes">
        <div class="info-label">Classes concernées:</div>
        <div class="info-value"><?= htmlspecialchars($evenement['classes']) ?></div>
      </div>
      <?php endif; ?>
      
      <div class="event-visibility">
        <div class="info-label">Visibilité:</div>
        <div class="info-value"><?= htmlspecialchars($visibilite_texte) ?></div>
      </div>
    </div>
    
    <!-- Boutons d'action -->
    <div class="event-actions">
      <a href="agenda.php" class="button">Retour au calendrier</a>
      
      <?php if ($can_edit): ?>
      <a href="modifier_evenement.php?id=<?= $evenement['id'] ?>" class="button">Modifier</a>
      <?php endif; ?>
      
      <?php if ($can_delete): ?>
      <a href="supprimer_evenement.php?id=<?= $evenement['id'] ?>" class="button button-secondary" 
         onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet événement ?');">Supprimer</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>