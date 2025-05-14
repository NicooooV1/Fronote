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
    
    // Si c'est un élève ou un parent, on ne montre que les devoirs de la classe de l'élève
    if (isStudent()) {
      // Pour les élèves, on utilise leur classe
      $stmt_eleve = $pdo->prepare('SELECT classe FROM eleves WHERE prenom = ? AND nom = ?');
      $stmt_eleve->execute([$user['prenom'], $user['nom']]);
      $eleve_data = $stmt_eleve->fetch();
      $classe_eleve = $eleve_data ? $eleve_data['classe'] : '';
      
      // Debug : afficher la classe de l'élève pour vérification
      // echo "<p>Classe de l'élève: " . htmlspecialchars($classe_eleve) . "</p>";
      
      // Si aucune classe n'est trouvée, afficher tous les devoirs (fallback)
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
    // Si c'est un parent, on pourrait montrer les devoirs des classes de ses enfants (à implémenter)
    elseif (isParent()) {
      // TODO: Récupérer les enfants du parent et leurs classes
      // Pour l'instant, afficher tous les devoirs comme fallback
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
    // Si c'est un professeur, on montre tous les devoirs qu'il a ajoutés
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
    // Sinon (administrateur, vie scolaire ou autre), on montre tous les devoirs
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
    
    // Afficher les devoirs
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
        
        // Afficher les boutons de modification et suppression pour les rôles autorisés
        if (canManageDevoirs()) {
          // Si c'est un professeur, vérifier qu'il a créé le devoir
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
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>