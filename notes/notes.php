<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusions
include_once 'includes/header.php'; 
include_once 'includes/db.php';
include_once 'includes/auth.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    // Utiliser un chemin absolu pour la redirection
    $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'] ?? null;
if (!$user) {
    // Utiliser un chemin absolu pour la redirection
    $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 

// Récupérer toutes les classes disponibles
$stmt_classes = $pdo->query('SELECT DISTINCT classe FROM notes ORDER BY classe');
$classes = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);

// Définir la classe sélectionnée (si présente dans l'URL ou par défaut la première)
$selected_class = isset($_GET['classe']) ? $_GET['classe'] : ($classes[0] ?? '');

// Définir la matière sélectionnée (si présente dans l'URL ou vide par défaut)
$selected_subject = isset($_GET['matiere']) ? $_GET['matiere'] : '';

// Définir le filtre de date
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Construire la requête de base
$query = 'SELECT * FROM notes WHERE 1=1';
$params = [];

// Ajouter des filtres à la requête
if (!empty($selected_class)) {
    $query .= ' AND classe = ?';
    $params[] = $selected_class;
}

if (!empty($selected_subject)) {
    $query .= ' AND matiere = ?';
    $params[] = $selected_subject;
}

if (!empty($date_filter)) {
    $query .= ' AND date_evaluation = ?';
    $params[] = $date_filter;
}

// Si l'utilisateur est un professeur (et pas un admin), limiter aux notes qu'il a créées
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
    $query .= ' AND nom_professeur = ?';
    $params[] = $user_fullname;
}

// Si l'utilisateur est un élève, limiter aux notes le concernant
if (isStudent()) {
    $query .= ' AND eleve_id = ?';
    $params[] = $user['id'];
}

// Si l'utilisateur est un parent, limiter aux notes concernant son/ses enfant(s)
if (isParent()) {
    // Récupérer les IDs des enfants
    $stmt_enfants = $pdo->prepare('SELECT eleve_id FROM parents_eleves WHERE parent_id = ?');
    $stmt_enfants->execute([$user['id']]);
    $enfants = $stmt_enfants->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($enfants)) {
        $placeholders = implode(',', array_fill(0, count($enfants), '?'));
        $query .= ' AND eleve_id IN (' . $placeholders . ')';
        $params = array_merge($params, $enfants);
    } else {
        // Si le parent n'a pas d'enfant enregistré, ne rien afficher
        $query .= ' AND 1=0';
    }
}

// Ajouter l'ordre
$query .= ' ORDER BY date_evaluation DESC, matiere ASC';

// Préparer et exécuter la requête
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notes = $stmt->fetchAll();

// Récupérer les matières disponibles
$stmt_matieres = $pdo->query('SELECT DISTINCT matiere FROM notes ORDER BY matiere');
$matieres = $stmt_matieres->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les dates d'évaluation disponibles
$stmt_dates = $pdo->query('SELECT DISTINCT date_evaluation FROM notes ORDER BY date_evaluation DESC');
$dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="container">
  <!-- En-tête avec barre verte -->
  <div class="header-bar"></div>
  
  <!-- Menu supérieur et filtres -->
  <div class="main-header">
    <h2>Classes de l'établissement</h2>
    <div class="classes-buttons">
      <?php foreach ($classes as $classe): ?>
        <a href="?classe=<?= $classe ?>&trimestre=<?= $trimestre_selectionne ?>" 
           class="classe-btn <?= $classe == $classe_selectionnee ? 'active' : '' ?>">
          <?= $classe ?>
        </a>
      <?php endforeach; ?>
    </div>
    
    <div class="filters-row">
      <div class="filter-controls">
        <select id="trimestre-select" onchange="window.location.href='?classe=<?= $classe_selectionnee ?>&trimestre='+this.value<?= $eleve_selectionne ? '&eleve='.urlencode($eleve_selectionne) : '' ?>'">
          <option value="1" <?= $trimestre_selectionne == 1 ? 'selected' : '' ?>>Trimestre 1</option>
          <option value="2" <?= $trimestre_selectionne == 2 ? 'selected' : '' ?>>Trimestre 2</option>
          <option value="3" <?= $trimestre_selectionne == 3 ? 'selected' : '' ?>>Trimestre 3</option>
        </select>
      </div>
    </div>
  </div>
  
  <!-- Bouton Ajouter une note -->
  <?php if (canManageNotes()): ?>
  <div class="actions-bar">
    <a href="ajouter_note.php" class="btn-add-note">Ajouter une note</a>
  </div>
  <?php endif; ?>
  
  <?php if ($classe_selectionnee): ?>
    <!-- Récupérer tous les élèves de la classe sélectionnée -->
    <?php
    $stmt_eleves = $pdo->prepare("SELECT DISTINCT nom_eleve FROM notes WHERE classe = ? ORDER BY nom_eleve");
    $stmt_eleves->execute([$classe_selectionnee]);
    $eleves = $stmt_eleves->fetchAll(PDO::FETCH_COLUMN);
    ?>
    
    <?php if (!$eleve_selectionne): ?>
      <!-- Affichage amélioré de la liste des élèves -->
      <div class="eleves-section">
        <div class="eleves-grid">
          <?php foreach ($eleves as $eleve): ?>
            <a href="?classe=<?= $classe_selectionnee ?>&trimestre=<?= $trimestre_selectionne ?>&eleve=<?= urlencode($eleve) ?>" class="eleve-item">
              <span class="eleve-bullet"></span>
              <span class="eleve-nom"><?= htmlspecialchars($eleve) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <!-- Affichage détaillé d'un élève spécifique -->
      <?php
      // Vérifier si l'élève existe dans cette classe
      if (!in_array($eleve_selectionne, $eleves)) {
        echo "<div class='error-message'>Cet élève n'existe pas dans cette classe.</div>";
      } else {
        // Récupérer toutes les notes de l'élève pour ce trimestre
        $stmt_notes = $pdo->prepare("SELECT * FROM notes 
                              WHERE nom_eleve = ? AND classe = ? AND date_ajout BETWEEN ? AND ? 
                              ORDER BY nom_matiere");
        $stmt_notes->execute([$eleve_selectionne, $classe_selectionnee, $date_debut, $date_fin]);
        $notes_eleve = $stmt_notes->fetchAll();
        
        // Regrouper les notes par matière
        $notes_par_matiere = [];
        foreach ($notes_eleve as $note) {
          $matiere = $note['nom_matiere'];
          if (!isset($notes_par_matiere[$matiere])) {
            $notes_par_matiere[$matiere] = [];
          }
          $notes_par_matiere[$matiere][] = $note;
        }
        
        // Calculer la moyenne générale et par matière
        $moyennes_matieres = [];
        $coefficients_matieres = [];
        
        foreach ($notes_par_matiere as $matiere => $notes) {
          $moyennes_matieres[$matiere] = calculerMoyenne($notes);
          
          // Calculer le coefficient total pour cette matière
          $coef_total = 0;
          foreach ($notes as $note) {
            $coef_total += isset($note['coefficient']) ? intval($note['coefficient']) : 1;
          }
          $coefficients_matieres[$matiere] = $coef_total;
        }
        
        // Calculer la moyenne générale
        $somme_ponderee = 0;
        $somme_coef = 0;
        
        foreach ($moyennes_matieres as $matiere => $moyenne) {
          $coef = $coefficients_matieres[$matiere];
          $somme_ponderee += $moyenne * $coef;
          $somme_coef += $coef;
        }
        
        $moyenne_generale = $somme_coef > 0 ? round($somme_ponderee / $somme_coef, 2) : 0;
        ?>
        
        <div class="eleve-detail">
          <h2 class="eleve-heading">Notes de <?= htmlspecialchars($eleve_selectionne) ?> - Trimestre <?= $trimestre_selectionne ?></h2>
          
          <div class="moyenne-generale-box">
            <p>Moyenne générale: <?= $moyenne_generale ?></p>
          </div>
          
          <?php if (empty($notes_eleve)): ?>
            <p>Aucune note trouvée pour cet élève ce trimestre.</p>
          <?php else: ?>
            <?php foreach ($notes_par_matiere as $matiere => $notes): ?>
              <div class="matiere-section">
                <h3 class="matiere-heading"><?= htmlspecialchars($matiere) ?></h3>
                
                <?php foreach ($notes as $note): ?>
                  <?php
                  $date_obj = new DateTime($note['date_ajout']);
                  $date_format = $date_obj->format('d/m/Y');
                  
                  $description = isset($note['description']) && !empty($note['description']) 
                               ? htmlspecialchars($note['description']) 
                               : 'Évaluation';
                               
                  $coef = isset($note['coefficient']) ? $note['coefficient'] : 1;
                  ?>
                  
                  <div class="note-row">
                    <div class="note-value"><?= $note['note'] ?></div>
                    <div class="note-date"><?= $date_format ?></div>
                    <div class="note-description"><?= $description ?></div>
                    <div class="note-prof"><?= htmlspecialchars($note['nom_professeur']) ?></div>
                    <div class="note-coef">Coef. <?= $coef ?></div>
                    <div class="note-sur"><?= $note['note'] ?>/20</div>
                    
                    <?php if (canManageNotes()): ?>
                      <div class="note-actions">
                        <a href="modifier_note.php?id=<?= $note['id'] ?>" class="btn-modifier">Modifier</a>
                        <a href="supprimer_note.php?id=<?= $note['id'] ?>" class="btn-supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette note ?');">Supprimer</a>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php
      }
      ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="info-message">
      <p>Veuillez sélectionner une classe.</p>
    </div>
  <?php endif; ?>
</div>

<!-- CSS spécifique à cette page -->
<style>
  /* Conteneur principal */
  body {
    background-color: #f8f9fa;
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
  }
  
  .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 15px;
  }
  
  /* Barre verte en haut */
  .header-bar {
    height: 5px;
    background-color: #198754;
    margin-bottom: 20px;
  }
  
  /* En-tête et filtres */
  .main-header {
    margin-bottom: 20px;
  }
  
  .main-header h2 {
    font-size: 20px;
    margin-top: 0;
    margin-bottom: 15px;
    color: #333;
  }
  
  .classes-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
  }
  
  .classe-btn {
    padding: 8px 15px;
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #0d6efd;
    text-decoration: none;
    font-size: 14px;
    text-align: center;
    min-width: 50px;
  }
  
  .classe-btn.active {
    background-color: #0d6efd;
    color: white;
    font-weight: bold;
  }
  
  .filters-row {
    margin-top: 15px;
  }
  
  .filter-controls {
    display: flex;
    gap: 15px;
  }
  
  .filter-controls select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-width: 200px;
    background-color: white;
  }
  
  /* Bouton d'ajout de note */
  .actions-bar {
    margin-bottom: 20px;
  }
  
  .btn-add-note {
    display: inline-block;
    padding: 8px 15px;
    background-color: #198754;
    color: white;
    border-radius: 4px;
    text-decoration: none;
    font-weight: bold;
  }
  
  .btn-add-note:hover {
    background-color: #0f5132;
  }
  
  /* Liste des élèves améliorée */
  .eleves-section {
    margin-bottom: 30px;
  }
  
  .eleves-grid {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  
  .eleve-item {
    display: flex;
    align-items: center;
    padding: 5px 0;
    color: #0d6efd;
    text-decoration: none;
  }
  
  .eleve-bullet {
    display: inline-block;
    width: 6px;
    height: 6px;
    background-color: #0d6efd;
    border-radius: 50%;
    margin-right: 10px;
  }
  
  .eleve-nom {
    font-size: 16px;
  }
  
  .eleve-item:hover .eleve-nom {
    text-decoration: underline;
  }
  
  /* Vue détaillée élève */
  .eleve-detail {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 4px;
  }
  
  .eleve-heading {
    font-size: 22px;
    margin-top: 0;
    margin-bottom: 20px;
    color: #333;
  }
  
  .moyenne-generale-box {
    background-color: white;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  }
  
  .moyenne-generale-box p {
    font-size: 18px;
    margin: 0;
    font-weight: bold;
  }
  
  .matiere-section {
    margin-bottom: 30px;
  }
  
  .matiere-heading {
    color: #0d6efd;
    font-size: 20px;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 1px solid #dee2e6;
  }
  
  .note-row {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
  }
  
  .note-row:last-child {
    border-bottom: none;
  }
  
  .note-value {
    font-weight: bold;
    font-size: 18px;
    width: 40px;
  }
  
  .note-date {
    width: 100px;
    color: #6c757d;
  }
  
  .note-description {
    flex: 1;
    font-weight: 500;
  }
  
  .note-prof {
    width: 150px;
    color: #6c757d;
  }
  
  .note-coef {
    width: 70px;
    color: #6c757d;
  }
  
  .note-sur {
    width: 60px;
    font-weight: bold;
  }
  
  .note-actions {
    display: flex;
    gap: 10px;
    margin-left: 15px;
  }
  
  .btn-modifier, .btn-supprimer {
    padding: 5px 10px;
    border-radius: 4px;
    color: white;
    text-decoration: none;
    font-size: 14px;
  }
  
  .btn-modifier {
    background-color: #198754;
  }
  
  .btn-modifier:hover {
    background-color: #0f5132;
  }
  
  .btn-supprimer {
    background-color: #dc3545;
  }
  
  .btn-supprimer:hover {
    background-color: #b02a37;
  }
  
  /* Messages */
  .info-message, .error-message {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
  }
  
  .info-message {
    background-color: #cfe2ff;
    color: #084298;
  }
  
  .error-message {
    background-color: #f8d7da;
    color: #842029;
  }
</style>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon
ob_end_flush();
?>