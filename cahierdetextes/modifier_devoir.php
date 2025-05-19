<?php
ob_start();

include 'includes/db.php';
include 'includes/auth.php';

if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
  exit;
}

$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: cahierdetextes.php');
  exit;
}

$id = $_GET['id'];
$stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
$stmt->execute([$id]);
$devoir = $stmt->fetch();

if (!$devoir) {
  header('Location: cahierdetextes.php');
  exit;
}

if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  if ($devoir['nom_professeur'] !== $user_fullname) {
    header('Location: cahierdetextes.php');
    exit;
  }
}

// Récupérer la liste des professeurs
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

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
$etablissement_data = [];
if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

$message = '';
$erreur = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validation des champs
  if (empty($_POST['titre']) || empty($_POST['description']) || empty($_POST['classe']) ||
      empty($_POST['nom_matiere']) || empty($_POST['nom_professeur']) || 
      empty($_POST['date_ajout']) || empty($_POST['date_rendu'])) {
    $erreur = "Veuillez remplir tous les champs obligatoires.";
  } else {
    // Vérifier que la date de rendu est postérieure à la date d'ajout
    if (strtotime($_POST['date_rendu']) <= strtotime($_POST['date_ajout'])) {
      $erreur = "La date de rendu doit être postérieure à la date d'ajout.";
    } else {
      // Mettre à jour le devoir dans la base de données
      $stmt = $pdo->prepare('UPDATE devoirs SET titre = ?, description = ?, classe = ?, nom_matiere = ?, nom_professeur = ?, date_ajout = ?, date_rendu = ? WHERE id = ?');
      $stmt->execute([
        $_POST['titre'],
        $_POST['description'],
        $_POST['classe'],
        $_POST['nom_matiere'],
        $_POST['nom_professeur'],
        $_POST['date_ajout'],
        $_POST['date_rendu'],
        $id
      ]);
      
      $message = "Le devoir a été mis à jour avec succès.";
      
      // Recharger les données du devoir
      $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
      $stmt->execute([$id]);
      $devoir = $stmt->fetch();
      
      // Redirection après un court délai
      header('refresh:2;url=cahierdetextes.php');
    }
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifier un devoir - Pronote</title>
  <link rel="stylesheet" href="assets/css/cahierdetextes.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Cahier de Textes</div>
      </a>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <a href="cahierdetextes.php" class="action-button secondary">
          <i class="fas fa-list"></i> Liste des devoirs
        </a>
        
        <a href="../notes/notes.php" class="action-button secondary">
          <i class="fas fa-graduation-cap"></i> Système de Notes
        </a>
        
        <a href="../accueil/accueil.php" class="action-button secondary">
          <i class="fas fa-home"></i> Accueil Pronote
        </a>
      </div>
    </div>
    
    <!-- Remplacer par une sidebar cohérente avec les autres modules -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Cahier de Textes</div>
      </a>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <h3 class="sidebar-section-header">Actions</h3>
        <a href="cahierdetextes.php" class="action-button secondary">
          <i class="fas fa-list"></i> Liste des devoirs
        </a>
      </div>
      
      <!-- Autres modules -->
      <div class="sidebar-section">
        <h3 class="sidebar-section-header">Autres modules</h3>
        <div class="sidebar-nav">
          <a href="../notes/notes.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
            <span>Notes</span>
          </a>
          <a href="../messagerie/index.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
            <span>Messagerie</span>
          </a>
          <a href="../absences/absences.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
            <span>Absences</span>
          </a>
          <a href="../agenda/agenda.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
            <span>Agenda</span>
          </a>
          <a href="../accueil/accueil.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
            <span>Accueil</span>
          </a>
        </div>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <a href="cahierdetextes.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
          </a>
          <h1>Modifier un devoir</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <?php if ($message): ?>
          <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?= $message ?></div>
          </div>
        <?php endif; ?>
        
        <?php if ($erreur): ?>
          <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= $erreur ?></div>
          </div>
        <?php endif; ?>
        
        <div class="form-container">
          <form method="post">
            <div class="form-grid">
              <div class="form-group form-full">
                <label for="titre">Titre du devoir <span class="required">*</span></label>
                <input type="text" name="titre" id="titre" class="form-control" required value="<?= htmlspecialchars($devoir['titre']) ?>">
              </div>
              
              <div class="form-group">
                <label for="classe">Classe <span class="required">*</span></label>
                <select name="classe" id="classe" class="form-control" required>
                  <option value="">Sélectionnez une classe</option>
                  <?php if (!empty($etablissement_data['classes'])): ?>
                    <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
                      <optgroup label="<?= ucfirst($niveau) ?>">
                        <?php foreach ($niveaux as $sousniveau => $classes): ?>
                          <?php foreach ($classes as $classe): ?>
                            <option value="<?= $classe ?>" <?= ($devoir['classe'] == $classe) ? 'selected' : '' ?>><?= $classe ?></option>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  
                  <?php if (!empty($etablissement_data['primaire'])): ?>
                    <optgroup label="Primaire">
                      <?php foreach ($etablissement_data['primaire'] as $niveau => $classes): ?>
                        <?php foreach ($classes as $classe): ?>
                          <option value="<?= $classe ?>" <?= ($devoir['classe'] == $classe) ? 'selected' : '' ?>><?= $classe ?></option>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>
              </div>
              
              <div class="form-group">
                <label for="nom_matiere">Matière <span class="required">*</span></label>
                <select name="nom_matiere" id="nom_matiere" class="form-control" required>
                  <option value="">Sélectionnez une matière</option>
                  <?php if (!empty($etablissement_data['matieres'])): ?>
                    <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                      <option value="<?= $matiere['nom'] ?>" <?= ($devoir['nom_matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              
              <div class="form-group">
                <label for="nom_professeur">Professeur <span class="required">*</span></label>
                <?php if (isTeacher() && !isAdmin() && !isVieScolaire()): ?>
                  <input type="text" name="nom_professeur" id="nom_professeur" class="form-control" value="<?= htmlspecialchars($devoir['nom_professeur']) ?>" readonly>
                <?php else: ?>
                  <select name="nom_professeur" id="nom_professeur" class="form-control" required>
                    <option value="">Sélectionnez un professeur</option>
                    <?php foreach ($professeurs as $prof): ?>
                      <?php $prof_fullname = $prof['prenom'] . ' ' . $prof['nom']; ?>
                      <option value="<?= htmlspecialchars($prof_fullname) ?>" 
                             data-matiere="<?= htmlspecialchars($prof['matiere']) ?>"
                             <?= ($devoir['nom_professeur'] == $prof_fullname) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prof_fullname) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
              
              <div class="form-group">
                <label for="date_ajout">Date d'ajout <span class="required">*</span></label>
                <input type="date" name="date_ajout" id="date_ajout" class="form-control" value="<?= $devoir['date_ajout'] ?>" required>
              </div>
              
              <div class="form-group">
                <label for="date_rendu">Date de rendu <span class="required">*</span></label>
                <input type="date" name="date_rendu" id="date_rendu" class="form-control" value="<?= $devoir['date_rendu'] ?>" required>
              </div>
              
              <div class="form-group form-full">
                <label for="description">Description <span class="required">*</span></label>
                <textarea name="description" id="description" class="form-control" rows="6" required><?= htmlspecialchars($devoir['description']) ?></textarea>
              </div>
              
              <div class="form-full">
                <div class="form-actions">
                  <a href="cahierdetextes.php" class="button button-secondary">
                    <i class="fas fa-times"></i> Annuler
                  </a>
                  <button type="submit" class="button button-primary">
                    <i class="fas fa-save"></i> Mettre à jour
                  </button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  
  <script>
  <?php if (!isTeacher() || isAdmin() || isVieScolaire()): ?>
  document.getElementById('nom_professeur').addEventListener('change', function() {
    if (this.selectedIndex > 0) {
      const matiereProf = this.options[this.selectedIndex].getAttribute('data-matiere');
      const selectMatiere = document.getElementById('nom_matiere');
      
      for (let i = 0; i < selectMatiere.options.length; i++) {
        if (selectMatiere.options[i].value === matiereProf) {
          selectMatiere.selectedIndex = i;
          break;
        }
      }
    }
  });
  
  document.getElementById('nom_matiere').addEventListener('change', function() {
    const matiereSelectionnee = this.value;
    const selectProf = document.getElementById('nom_professeur');
    const options = selectProf.options;
    
    if (selectProf.selectedIndex > 0) {
      const matiereProf = options[selectProf.selectedIndex].getAttribute('data-matiere');
      if (matiereProf !== matiereSelectionnee) {
        selectProf.selectedIndex = 0;
      }
    }
    
    for (let i = 1; i < options.length; i++) {
      const matiereProf = options[i].getAttribute('data-matiere');
      if (matiereSelectionnee === '' || matiereProf === matiereSelectionnee) {
        options[i].style.display = '';
      } else {
        options[i].style.display = 'none';
      }
    }
  });
  
  window.addEventListener('load', function() {
    const selectProf = document.getElementById('nom_professeur');
    if (selectProf.tagName === 'SELECT' && selectProf.selectedIndex > 0) {
      selectProf.dispatchEvent(new Event('change'));
    }
  });
  <?php endif; ?>
  
  document.querySelector('form').addEventListener('submit', function(e) {
    const dateAjout = new Date(document.getElementById('date_ajout').value);
    const dateRendu = new Date(document.getElementById('date_rendu').value);
    
    if (dateRendu < dateAjout) {
      e.preventDefault();
      alert("La date de rendu doit être ultérieure à la date d'ajout.");
    }
  });
  </script>
</body>
</html>

<?php
ob_end_flush();
?>