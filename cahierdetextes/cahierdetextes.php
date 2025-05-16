<?php
ob_start();

include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_role = getUserRole(); 
?>

<div class="container">
  <div class="user-info">
    <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
  </div>

  <?php if (canManageDevoirs()): ?>
  <div class="actions">
    <a href="ajouter_devoir.php" class="button">Ajouter un devoir</a>
  </div>
  <?php endif; ?>
  
  <div class="filter-buttons">
    <a href="?order=date_rendu" class="button <?php echo (!isset($_GET['order']) || $_GET['order'] == 'date_rendu') ? '' : 'button-secondary'; ?>">Par date de rendu</a>
    <a href="?order=date_ajout" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'date_ajout') ? '' : 'button-secondary'; ?>">Par date d'ajout</a>
    <a href="?order=matiere" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') ? '' : 'button-secondary'; ?>">Par matière</a>
    <?php if (!isStudent() && !isParent()): ?>
    <a href="?order=classe" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'classe') ? '' : 'button-secondary'; ?>">Par classe</a>
    <?php endif; ?>
  </div>

  <div class="notes">
    <?php
    $order = isset($_GET['order']) ? $_GET['order'] : 'date_rendu';
    
    // If student or parent, only show homeworks for the student's class
    if (isStudent()) {
      $stmt_eleve = $pdo->prepare('SELECT classe FROM eleves WHERE prenom = ? AND nom = ?');
      $stmt_eleve->execute([$user['prenom'], $user['nom']]);
      $eleve_data = $stmt_eleve->fetch();
      $classe_eleve = $eleve_data ? $eleve_data['classe'] : '';
      
      if (empty($classe_eleve)) {
        switch ($order) {
          case 'matiere':
            $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
            break;
          case 'date_ajout':
            $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
            break;
          default:
            $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
        }
        
        $stmt = $pdo->query($sql);
      } else {
        switch ($order) {
          case 'matiere':
            $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY nom_matiere ASC, date_rendu ASC';
            break;
          case 'date_ajout':
            $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_ajout DESC';
            break;
          default:
            $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_rendu ASC';
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$classe_eleve]);
      }
    }
    elseif (isParent()) {
      // Future implementation: get children's classes
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM devoirs ORDER BY classe ASC, date_rendu ASC';
          break;
        case 'date_ajout':
          $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
      }
      
      $stmt = $pdo->query($sql);
    }
    elseif (isTeacher()) {
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY nom_matiere ASC, date_rendu ASC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY classe ASC, date_rendu ASC';
          break;
        case 'date_ajout':
          $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_rendu ASC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$user_fullname]);
    }
    else {
      switch ($order) {
        case 'matiere':
          $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM devoirs ORDER BY classe ASC, date_rendu ASC';
          break;
        case 'date_ajout':
          $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
      }
      
      $stmt = $pdo->query($sql);
    }
    
    // Display homework assignments
    while ($devoir = $stmt->fetch()) {
      echo "<div class='note'>
        <div class='note-header'>
          <div class='note-title'>{$devoir['titre']}</div>
          <div class='note-date'>Date d'ajout: {$devoir['date_ajout']}</div>
        </div>
        <div class='note-details'>
          <div class='note-detail'>
            <div>Classe:</div>
            <div class='note-value'>{$devoir['classe']}</div>
          </div>
          <div class='note-detail'>
            <div>Matière:</div>
            <div class='note-value'>{$devoir['nom_matiere']}</div>
          </div>
          <div class='note-detail'>
            <div>Professeur:</div>
            <div class='note-value'>{$devoir['nom_professeur']}</div>
          </div>
          <div class='note-detail'>
            <div>À rendre pour le:</div>
            <div class='note-value' style='color: #d33; font-weight: bold;'>{$devoir['date_rendu']}</div>
          </div>
        </div>
        <div class='devoir-description'>
          <h4>Description:</h4>
          <p>" . nl2br(htmlspecialchars($devoir['description'])) . "</p>
        </div>";
        
        // Display edit and delete buttons for authorized roles
        if (canManageDevoirs()) {
          // If teacher, check if they created this homework
          if (!isTeacher() || (isTeacher() && $devoir['nom_professeur'] == $user_fullname)) {
            echo "<div style='margin-top: 10px; display: flex; gap: 10px;'>
              <a href='modifier_devoir.php?id={$devoir['id']}' class='button button-secondary'>Modifier</a>
              <a href='supprimer_devoir.php?id={$devoir['id']}' class='button button-secondary' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer ce devoir ?\");'>Supprimer</a>
            </div>";
          }
        }
        
      echo "</div>";
    }
    
    if ($stmt->rowCount() === 0) {
      echo "<p>Aucun devoir n'a été ajouté pour le moment.</p>";
    }
    ?>
  </div>
</div>

</body>
</html>

<?php
ob_end_flush();
?>