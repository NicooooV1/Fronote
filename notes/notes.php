<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

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

  <?php if (canManageNotes()): ?>
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
    
    // Déterminer si on doit filtrer par élève ou afficher toutes les notes
    if (isStudent() && isset($_SESSION['user_name'])) {
      $student_name = $_SESSION['user_name'];
      
      if ($order == 'matiere') {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE nom_eleve = ? ORDER BY nom_matiere ASC, date_ajout DESC');
      } else {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE nom_eleve = ? ORDER BY date_ajout DESC');
      }
      $stmt->execute([$student_name]);
    } else {
      // Pour les professeurs ou si le système de session n'est pas encore configuré
      $sql = '';
      if ($order == 'matiere') {
        $sql = 'SELECT * FROM notes ORDER BY nom_matiere ASC, date_ajout DESC';
      } elseif ($order == 'classe') {
        $sql = 'SELECT * FROM notes ORDER BY classe ASC, nom_eleve ASC, date_ajout DESC';
      } elseif ($order == 'eleve') {
        $sql = 'SELECT * FROM notes ORDER BY nom_eleve ASC, date_ajout DESC';
      } else {
        $sql = 'SELECT * FROM notes ORDER BY date_ajout DESC';
      }
      
      $stmt = $pdo->query($sql);
    }
    
    // Affichage des notes
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
        
        // Afficher les boutons de modification et suppression pour les rôles autorisés
        if (canManageNotes()) {
          // Si c'est un professeur, vérifier qu'il a créé la note
          if (!isTeacher() || (isTeacher() && $note['nom_professeur'] == $user_fullname)) {
            echo "<div style='margin-top: 10px; display: flex; gap: 10px;'>
              <a href='modifier_note.php?id={$note['id']}' class='button button-secondary'>Modifier</a>
              <a href='supprimer_note.php?id={$note['id']}' class='button button-secondary' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
            </div>";
          }
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

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>