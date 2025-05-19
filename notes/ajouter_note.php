<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour ajouter des notes
if (!canManageNotes()) {
  header('Location: notes.php');
  exit;
}

// Utiliser les données utilisateur de la session
$user = $_SESSION['user'];
$nom_professeur = $user['prenom'] . ' ' . $user['nom'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

// Récupérer la liste des élèves depuis la base de données
$stmt_eleves = $pdo->query('SELECT id, nom, prenom, classe FROM eleves ORDER BY nom, prenom');
$eleves = $stmt_eleves->fetchAll();

// Récupérer la liste des professeurs depuis la base de données
$stmt_profs = $pdo->query('SELECT id, nom, prenom, matiere FROM professeurs ORDER BY nom, prenom');
$professeurs = $stmt_profs->fetchAll();

// Si c'est un professeur, récupérer sa matière
$prof_matiere = '';
if (isTeacher()) {
  $stmt_prof = $pdo->prepare('SELECT matiere FROM professeurs WHERE nom = ? AND prenom = ?');
  $stmt_prof->execute([$user['nom'], $user['prenom']]);
  $prof_data = $stmt_prof->fetch();
  $prof_matiere = $prof_data ? $prof_data['matiere'] : '';
}

// Récupérer le trimestre actuel (1, 2 ou 3 en fonction de la date)
$current_month = (int)date('n'); // 1-12
if ($current_month >= 9 && $current_month <= 12) {
  $trimestre_actuel = 1; // Septembre-Décembre
} elseif ($current_month >= 1 && $current_month <= 3) {
  $trimestre_actuel = 2; // Janvier-Mars
} else {
  $trimestre_actuel = 3; // Avril-Août
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Debug: Log POST data to help troubleshoot
    error_log("POST data: " . print_r($_POST, true));
    
    // Vérifier la structure de la table
    $check_columns = $pdo->query("SHOW COLUMNS FROM notes");
    $columns = $check_columns->fetchAll(PDO::FETCH_COLUMN);
    error_log("Table columns: " . print_r($columns, true));
    
    // Vérifier si la colonne 'eleve_id' existe
    $eleve_id_exists = in_array('eleve_id', $columns);
    
    // Récupérer l'ID de l'élève si la colonne existe
    $eleve_id = 0;
    if ($eleve_id_exists) {
      $stmt_eleve = $pdo->prepare("SELECT id FROM eleves WHERE prenom = ? LIMIT 1");
      $stmt_eleve->execute([$_POST['nom_eleve']]);
      $eleve = $stmt_eleve->fetch();
      $eleve_id = $eleve ? $eleve['id'] : 0;
    }
    
    // Construire la requête SQL en fonction des colonnes existantes
    $fields = [];
    $placeholders = [];
    $values = [];
    
    // Champs obligatoires
    if ($eleve_id_exists) {
      $fields[] = 'eleve_id';
      $placeholders[] = '?';
      $values[] = $eleve_id;
    }
    
    $fields[] = 'nom_eleve';
    $placeholders[] = '?';
    $values[] = $_POST['nom_eleve'];
    
    $fields[] = 'classe';
    $placeholders[] = '?';
    $values[] = $_POST['classe'];
    
    // Vérifier si la table a une colonne 'matiere' ou 'nom_matiere'
    if (in_array('matiere', $columns)) {
      $fields[] = 'matiere';
      $placeholders[] = '?';
      $values[] = $_POST['nom_matiere'];
    }
    
    // Vérifier si la table a une colonne 'nom_matiere'
    if (in_array('nom_matiere', $columns)) {
      $fields[] = 'nom_matiere';
      $placeholders[] = '?';
      $values[] = $_POST['nom_matiere'];
    }
    
    $fields[] = 'note';
    $placeholders[] = '?';
    $values[] = $_POST['note'];
    
    // Vérifier si la colonne 'note_sur' existe
    if (in_array('note_sur', $columns)) {
      $fields[] = 'note_sur';
      $placeholders[] = '?';
      $values[] = 20; // Par défaut sur 20
    }
    
    $fields[] = 'nom_professeur';
    $placeholders[] = '?';
    $values[] = $_POST['nom_professeur'];
    
    // Vérifier si la colonne 'date_ajout' existe
    if (in_array('date_ajout', $columns)) {
      $fields[] = 'date_ajout';
      $placeholders[] = '?';
      $values[] = $_POST['date_ajout'];
    }
    
    // Vérifier si la colonne 'date_evaluation' existe
    if (in_array('date_evaluation', $columns)) {
      $fields[] = 'date_evaluation';
      $placeholders[] = '?';
      $values[] = $_POST['date_ajout']; // On utilise la même date
    }
    
    // Vérifier si la colonne 'coefficient' existe
    if (in_array('coefficient', $columns)) {
      $fields[] = 'coefficient';
      $placeholders[] = '?';
      $values[] = $_POST['coefficient'];
    }
    
    // Vérifier si la colonne 'commentaire' existe
    if (in_array('commentaire', $columns)) {
      $fields[] = 'commentaire';
      $placeholders[] = '?';
      $values[] = $_POST['description'];
    }
    
    // Vérifier si la colonne 'trimestre' existe
    if (in_array('trimestre', $columns)) {
      $fields[] = 'trimestre';
      $placeholders[] = '?';
      $values[] = $_POST['trimestre'] ?? $trimestre_actuel;
    }
    
    // Construire et exécuter la requête
    $query = 'INSERT INTO notes (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    error_log("SQL Query: " . $query); // Log the SQL query
    error_log("SQL Values: " . print_r($values, true)); // Log the values
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($values);
    
    // Redirection après succès
    $_SESSION['success_message'] = "La note a été ajoutée avec succès.";
    header('Location: notes.php');
    exit;
  } catch (PDOException $e) {
    // Enregistrer l'erreur et afficher un message d'erreur à l'utilisateur
    error_log("Erreur SQL lors de l'ajout d'une note: " . $e->getMessage());
    $error_message = "Une erreur s'est produite lors de l'ajout de la note: " . $e->getMessage();
  } catch (Exception $e) {
    error_log("Erreur générale: " . $e->getMessage());
    $error_message = $e->getMessage();
  }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ajouter une note - Pronote</title>
  <link rel="stylesheet" href="../assets/css/pronote-theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .required {
      color: var(--error-color);
      margin-left: 3px;
    }
    
    .form-container {
      background-color: var(--white);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-light);
      padding: var(--space-md);
    }
  </style>
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote</div>
      </div>
      
      <!-- Navigation -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Navigation</div>
        <a href="<?= defined('HOME_URL') ? HOME_URL : '../accueil/accueil.php' ?>" class="sidebar-link">
          <i class="fas fa-home"></i> Accueil
        </a>
        <a href="notes.php" class="sidebar-link active">
          <i class="fas fa-chart-bar"></i> Notes
        </a>
        <a href="../absences/absences.php" class="sidebar-link">
          <i class="fas fa-calendar-times"></i> Absences
        </a>
        <a href="../agenda/agenda.php" class="sidebar-link">
          <i class="fas fa-calendar-alt"></i> Agenda
        </a>
        <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-link">
          <i class="fas fa-book"></i> Cahier de textes
        </a>
        <a href="../messagerie/index.php" class="sidebar-link">
          <i class="fas fa-envelope"></i> Messagerie
        </a>
      </div>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="notes.php" class="create-button">
          <i class="fas fa-arrow-left"></i> Retour aux notes
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <div class="top-header">
        <div class="page-title">
          <h1>Ajouter une note</h1>
        </div>
        
        <div class="header-actions">
          <a href="<?= defined('LOGOUT_URL') ? LOGOUT_URL : '../login/public/logout.php' ?>" class="logout-button" title="Déconnexion">
            <i class="fas fa-sign-out-alt"></i>
          </a>
          <div class="user-avatar" title="<?= htmlspecialchars($nom_professeur) ?>">
            <?= htmlspecialchars($user_initials) ?>
          </div>
        </div>
      </div>
      
      <div class="content-container">
        <?php if (isset($error_message)): ?>
          <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
          </div>
        <?php endif; ?>
        
        <div class="form-container">
          <form method="post">
            <div class="form-grid">
              <!-- Champ pour la classe -->
              <div class="form-group">
                <label for="classe">Classe<span class="required">*</span></label>
                <select name="classe" id="classe" class="form-select" required>
                  <option value="">Sélectionnez une classe</option>
                  <?php if (!empty($etablissement_data['classes'])): ?>
                    <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
                      <optgroup label="<?= ucfirst($niveau) ?>">
                        <?php foreach ($niveaux as $sousniveau => $classes): ?>
                          <?php foreach ($classes as $classe): ?>
                            <option value="<?= htmlspecialchars($classe) ?>"><?= htmlspecialchars($classe) ?></option>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Champ pour l'élève -->
              <div class="form-group">
                <label for="nom_eleve">Élève<span class="required">*</span></label>
                <select name="nom_eleve" id="nom_eleve" class="form-select" required>
                  <option value="">Sélectionnez un élève</option>
                  <?php foreach ($eleves as $eleve): ?>
                    <option value="<?= htmlspecialchars($eleve['prenom']) ?>" data-classe="<?= htmlspecialchars($eleve['classe']) ?>">
                      <?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?> (<?= htmlspecialchars($eleve['classe']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Champ pour la matière -->
              <div class="form-group">
                <label for="nom_matiere">Matière<span class="required">*</span></label>
                <?php if (isTeacher()): ?>
                  <!-- Si c'est un professeur, on présélectionne sa matière -->
                  <select name="nom_matiere" id="nom_matiere" class="form-select" required>
                    <option value="">Sélectionnez une matière</option>
                    <?php if (!empty($etablissement_data['matieres'])): ?>
                      <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                        <option value="<?= htmlspecialchars($matiere['nom']) ?>" <?= ($prof_matiere == $matiere['nom']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($matiere['nom']) ?> (<?= htmlspecialchars($matiere['code']) ?>)
                        </option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                <?php else: ?>
                  <!-- Pour admin/vie scolaire -->
                  <select name="nom_matiere" id="nom_matiere" class="form-select" required>
                    <option value="">Sélectionnez une matière</option>
                    <?php if (!empty($etablissement_data['matieres'])): ?>
                      <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                        <option value="<?= htmlspecialchars($matiere['nom']) ?>">
                          <?= htmlspecialchars($matiere['nom']) ?> (<?= htmlspecialchars($matiere['code']) ?>)
                        </option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                <?php endif; ?>
              </div>

              <!-- Champ pour le professeur -->
              <div class="form-group">
                <label for="nom_professeur">Professeur<span class="required">*</span></label>
                <?php if (isTeacher()): ?>
                  <!-- Si c'est un professeur, il ne peut ajouter que des notes en son nom -->
                  <input type="text" name="nom_professeur" id="nom_professeur" class="form-control" value="<?= htmlspecialchars($nom_professeur) ?>" readonly>
                <?php else: ?>
                  <!-- Admin et vie scolaire peuvent choisir n'importe quel professeur -->
                  <select name="nom_professeur" id="nom_professeur" class="form-select" required>
                    <option value="">Sélectionnez un professeur</option>
                    <?php foreach ($professeurs as $prof): ?>
                      <option value="<?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>" data-matiere="<?= htmlspecialchars($prof['matiere']) ?>">
                        <?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
              
              <!-- Champ pour la note -->
              <div class="form-group">
                <label for="note">Note<span class="required">*</span></label>
                <input type="number" name="note" id="note" class="form-control" max="20" min="0" step="0.1" placeholder="Note sur 20" required>
              </div>
              
              <!-- Champ pour le coefficient -->
              <div class="form-group">
                <label for="coefficient">Coefficient<span class="required">*</span></label>
                <input type="number" name="coefficient" id="coefficient" class="form-control" min="1" max="10" step="1" value="1" required>
              </div>
              
              <!-- Champ pour la date -->
              <div class="form-group">
                <label for="date_ajout">Date<span class="required">*</span></label>
                <input type="date" name="date_ajout" id="date_ajout" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>
              
              <!-- Champ pour le trimestre -->
              <div class="form-group">
                <label for="trimestre">Trimestre<span class="required">*</span></label>
                <select name="trimestre" id="trimestre" class="form-select" required>
                  <option value="1" <?= $trimestre_actuel == 1 ? 'selected' : '' ?>>Trimestre 1</option>
                  <option value="2" <?= $trimestre_actuel == 2 ? 'selected' : '' ?>>Trimestre 2</option>
                  <option value="3" <?= $trimestre_actuel == 3 ? 'selected' : '' ?>>Trimestre 3</option>
                </select>
              </div>
            </div>
              
            <!-- Champ pour la description (intitulé) -->
            <div class="form-group mt-3">
              <label for="description">Intitulé de l'évaluation<span class="required">*</span></label>
              <input type="text" name="description" id="description" class="form-control" placeholder="Ex: Contrôle évaluation trimestre" required>
            </div>
            
            <div class="form-actions">
              <a href="notes.php" class="btn btn-secondary">Annuler</a>
              <button type="submit" class="btn btn-primary">Ajouter la note</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
  // Script pour filtrer les élèves en fonction de la classe sélectionnée
  document.getElementById('classe').addEventListener('change', function() {
    const classeSelectionnee = this.value;
    const selectEleve = document.getElementById('nom_eleve');
    const options = selectEleve.options;
    
    // Réinitialiser le sélecteur d'élève
    selectEleve.selectedIndex = 0;
    
    // Afficher/cacher les options en fonction de la classe
    for (let i = 1; i < options.length; i++) {
      const classeEleve = options[i].getAttribute('data-classe');
      if (classeSelectionnee === '' || classeEleve === classeSelectionnee) {
        options[i].style.display = '';
      } else {
        options[i].style.display = 'none';
      }
    }
  });

  // Script pour définir automatiquement la classe lorsqu'un élève est sélectionné
  document.getElementById('nom_eleve').addEventListener('change', function() {
    if (this.selectedIndex > 0) {
      const classeEleve = this.options[this.selectedIndex].getAttribute('data-classe');
      const selectClasse = document.getElementById('classe');
      
      // Parcourir toutes les options pour trouver la classe correspondante
      for (let i = 0; i < selectClasse.options.length; i++) {
        if (selectClasse.options[i].value === classeEleve) {
          selectClasse.selectedIndex = i;
          break;
        }
      }
    }
  });

  <?php if (!isTeacher()): ?>
  // Filtrer les professeurs en fonction de la matière sélectionnée
  document.getElementById('nom_matiere').addEventListener('change', function() {
    const matiereSelectionnee = this.value;
    const selectProf = document.getElementById('nom_professeur');
    const options = selectProf.options;
    
    // Réinitialiser le sélecteur de professeur
    selectProf.selectedIndex = 0;
    
    // Afficher/cacher les options en fonction de la matière
    for (let i = 1; i < options.length; i++) {
      const matiereProf = options[i].getAttribute('data-matiere');
      if (matiereSelectionnee === '' || matiereProf === matiereSelectionnee) {
        options[i].style.display = '';
      } else {
        options[i].style.display = 'none';
      }
    }
  });

  // Si un administrateur ou vie scolaire sélectionne un professeur, 
  // sélectionner automatiquement sa matière
  document.getElementById('nom_professeur').addEventListener('change', function() {
    if (this.selectedIndex > 0) {
      const matiereProf = this.options[this.selectedIndex].getAttribute('data-matiere');
      const selectMatiere = document.getElementById('nom_matiere');
      
      // Parcourir toutes les options pour trouver la matière correspondante
      for (let i = 0; i < selectMatiere.options.length; i++) {
        if (selectMatiere.options[i].value === matiereProf) {
          selectMatiere.selectedIndex = i;
          break;
        }
      }
    }
  });
  <?php endif; ?>
  </script>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>