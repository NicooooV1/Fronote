<?php
session_start();
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php'; // Pour vérifier l'authentification
?>

<div class="container">
  <?php if (isTeacher()): ?>
  <div class="actions">
    <a href="ajouter_note.php" class="button">Ajouter une note</a>
  </div>
  <?php endif; ?>
  
  <div class="filter-buttons">
    <a href="?order=date" class="button <?php echo (!isset($_GET['order']) || $_GET['order'] == 'date') ? '' : 'button-secondary'; ?>">Par date</a>
    <a href="?order=matiere" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') ? '' : 'button-secondary'; ?>">Par matière</a>
  </div>

  <div class="notes">
    <?php
    $order = isset($_GET['order']) ? $_GET['order'] : 'date';
    
    // Si c'est un élève, on ne montre que ses propres notes
    if (isStudent()) {
      $student_name = $_SESSION['user_name']; // Supposons que le nom de l'élève est stocké dans la session
      
      if ($order == 'matiere') {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE nom_eleve = ? ORDER BY nom_matiere ASC, date_ajout DESC');
      } else {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE nom_eleve = ? ORDER BY date_ajout DESC');
      }
      $stmt->execute([$student_name]);
    } else {
      // Si c'est un professeur ou un administrateur, on montre toutes les notes
      if ($order == 'matiere') {
        $stmt = $pdo->query('SELECT * FROM notes ORDER BY nom_matiere ASC, date_ajout DESC');
      } else {
        $stmt = $pdo->query('SELECT * FROM notes ORDER BY date_ajout DESC');
      }
    }
    
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
        if (isTeacher()) {
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