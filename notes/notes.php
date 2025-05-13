<?php include 'includes/header.php'; include 'includes/db.php'; ?>

<div class="container">
  <div class="actions">
    <a href="ajouter_note.php" class="button">Ajouter une note</a>
  </div>
  
  <div class="filter-buttons">
    <a href="?order=date" class="button <?php echo (!isset($_GET['order']) || $_GET['order'] == 'date') ? '' : 'button-secondary'; ?>">Par date</a>
    <a href="?order=matiere" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') ? '' : 'button-secondary'; ?>">Par matière</a>
  </div>

  <div class="notes">
    <?php
    $order = isset($_GET['order']) ? $_GET['order'] : 'date';
    
    if ($order == 'matiere') {
      $stmt = $pdo->query('SELECT * FROM notes ORDER BY nom_matiere ASC, date_ajout DESC');
    } else {
      $stmt = $pdo->query('SELECT * FROM notes ORDER BY date_ajout DESC');
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
        </div>
        <div style='margin-top: 10px;'>
          <a href='modifier_note.php?id={$note['id']}' class='button button-secondary'>Modifier</a>
        </div>
      </div>";
    }
    
    if ($stmt->rowCount() === 0) {
      echo "<p>Aucune note n'a été ajoutée pour le moment.</p>";
    }
    ?>
  </div>
</div>

</body>
</html>