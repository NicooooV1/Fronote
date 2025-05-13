<?php
// Nous n'avons plus besoin de démarrer la session, car c'est fait dans Auth
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 
?>

<div class="container">
  <div class="user-info">
    <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
  </div>

  <?php if (isTeacher()): ?>
  <div class="actions">
    <a href="ajouter_note.php" class="button">Ajouter une note</a>
  </div>
  <?php endif; ?>
  
  <div class="filter-buttons">
    <a href="?order=date" class="button <?php echo (!isset($_GET['order']) || $_GET['order'] == 'date') ? '' : 'button-secondary'; ?>">Par date</a>
    <a href="?order=matiere" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') ? '' : 'button-secondary'; ?>">Par matière</a>
    <?php if (!isStudent()): ?>
    <a href="?order=classe" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'classe') ? '' : 'button-secondary'; ?>">Par classe</a>
    <a href="?order=eleve" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'eleve') ? '' : 'button-secondary'; ?>">Par élève</a>
    <?php endif; ?>
  </div>

  <div class="notes">
    <?php
    $order = isset($_GET['order']) ? $_GET['order'] : 'date';
    
    // Si c'est un élève, on ne montre que ses propres notes
    if (isStudent()) {
      // Pour les élèves, on utilise le nom complet stocké dans la session
      $student_name = $user_fullname;
      
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM notes WHERE nom_eleve = ? ORDER BY nom_matiere ASC, date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes WHERE nom_eleve = ? ORDER BY date_ajout DESC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$student_name]);
    }
    // Si c'est un parent, on pourrait montrer les notes de ses enfants (à implémenter)
    // elseif (isParent()) {
    //   // TODO: Récupérer les enfants du parent et montrer leurs notes
    // } 
    // Si c'est un professeur, on montre toutes les notes qu'il a ajoutées
    elseif (isTeacher()) {
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? ORDER BY nom_matiere ASC, date_ajout DESC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? ORDER BY classe ASC, nom_eleve ASC, date_ajout DESC';
          break;
        case 'eleve':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? ORDER BY nom_eleve ASC, date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? ORDER BY date_ajout DESC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$user_fullname]);
    }
    // Sinon (administrateur ou autre), on montre toutes les notes
    else {
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM notes ORDER BY nom_matiere ASC, date_ajout DESC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM notes ORDER BY classe ASC, nom_eleve ASC, date_ajout DESC';
          break;
        case 'eleve':
          $sql = 'SELECT * FROM notes ORDER BY nom_eleve ASC, date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes ORDER BY date_ajout DESC';
      }
      
      $stmt = $pdo->query($sql);
    }
    
    // Afficher les notes
    while ($note = $stmt->fetch()) {
      echo "<div class='note'>
        <div class='note-header'>
          <div class='note-title'>{$note['nom_matiere']}</div>
          <div class='note-date'>{$note['date_ajout']}</div>
        </div>
        <div class='note-details'>
          <div class='note-detail'>
            <div>Classe:</div>
            <div class='note-value'>{$note['classe']}</div>
          </div>
          <div class='note-detail'>
            <div>Élève:</div>
            <div class='note-value'>{$note['nom_eleve']}</div>
          </div>
          <div class='note-detail'>
            <div>Professeur:</div>
            <div class='note-value'>{$note['nom_professeur']}</div>
          </div>
          <div class='note-detail'>
            <div>Note:</div>
            <div class='note-score'>{$note['note']}/20</div>
          </div>
        </div>";
        
        // Afficher les boutons de modification et suppression uniquement pour les professeurs
        // qui ont ajouté la note ou pour les administrateurs
        if ((isTeacher() && $note['nom_professeur'] == $user_fullname) || isAdmin()) {
          echo "<div style='margin-top: 10px; display: flex; gap: 10px;'>
            <a href='modifier_note.php?id={$note['id']}' class='button button-secondary'>Modifier</a>
            <a href='supprimer_note.php?id={$note['id']}' class='button button-secondary' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
          </div>";
        }
        
      echo "</div>";
    }
    
    if ($stmt->rowCount() === 0) {
      echo "<p>Aucune note n'a été ajoutée pour le moment.</p>";
    }
    ?>
  </div>
</div>

</body>
</html>