<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier que l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/login.php');
    exit;
}

// Vérifier si l'utilisateur a les permissions pour ajouter des devoirs
if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
  exit;
}

// Générer le token CSRF
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));

// Utiliser les données utilisateur de la session
$nom_professeur = $user_fullname;

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

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

$message = '';
$erreur = '';
$success = false;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Vérification du token CSRF
  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
    $erreur = "Erreur de validation du formulaire. Veuillez réessayer.";
  }
  // Validation
  else if (empty($_POST['titre']) || empty($_POST['classe']) || 
      empty($_POST['nom_matiere']) || empty($_POST['nom_professeur']) || 
      empty($_POST['description']) || empty($_POST['date_ajout']) || 
      empty($_POST['date_rendu'])) {
    $erreur = "Veuillez remplir tous les champs obligatoires.";
  } else {
    // Vérifier que la date de rendu est postérieure à la date d'ajout
    if (strtotime($_POST['date_rendu']) <= strtotime($_POST['date_ajout'])) {
      $erreur = "La date de rendu doit être postérieure à la date d'ajout.";
    } else {
      try {
        // Sanitize input data
        $titre = trim($_POST['titre']);
        $description = trim($_POST['description']);
        
        // Insertion dans la base de données
        $stmt = $pdo->prepare('INSERT INTO devoirs (titre, description, classe, nom_matiere, nom_professeur, date_ajout, date_rendu) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
          $titre,
          $description,
          $_POST['classe'],
          $_POST['nom_matiere'],
          $_POST['nom_professeur'],
          $_POST['date_ajout'],
          $_POST['date_rendu']
        ]);
        
        $message = "Le devoir a été ajouté avec succès.";
        $success = true;
        
        // Redirection après un court délai
        header('refresh:2;url=cahierdetextes.php?success=added');
      } catch (PDOException $e) {
        error_log("Erreur d'ajout dans ajouter_devoir.php: " . $e->getMessage());
        $erreur = "Une erreur est survenue lors de l'ajout du devoir.";
      }
    }
  }
}

// Variables pour le template
$pageTitle = "Ajouter un devoir";
$moduleClass = "cahier";
$moduleColor = "var(--accent-cahier)";

// Contenu additionnel pour le head
$additionalHead = <<<HTML
<link rel="stylesheet" href="../cahierdetextes/assets/css/cahierdetextes.css">
HTML;

// Contenu de la sidebar
$sidebarContent = <<<HTML
<div class="sidebar-section">
  <div class="sidebar-title">Navigation</div>
  <div class="sidebar-menu">
    <a href="cahierdetextes.php" class="sidebar-link">
      <i class="fas fa-list"></i> Liste des devoirs
    </a>
    <a href="ajouter_devoir.php" class="sidebar-link active">
      <i class="fas fa-plus"></i> Ajouter un devoir
    </a>
  </div>
</div>

<div class="sidebar-section">
  <div class="sidebar-title">Autres modules</div>
  <div class="sidebar-menu">
    <a href="../notes/notes.php" class="sidebar-link">
      <i class="fas fa-chart-bar"></i> Notes
    </a>
    <a href="../absences/absences.php" class="sidebar-link">
      <i class="fas fa-calendar-times"></i> Absences
    </a>
    <a href="../agenda/agenda.php" class="sidebar-link">
      <i class="fas fa-calendar-alt"></i> Agenda
    </a>
    <a href="../messagerie/index.php" class="sidebar-link">
      <i class="fas fa-envelope"></i> Messagerie
    </a>
    <a href="../accueil/accueil.php" class="sidebar-link">
      <i class="fas fa-home"></i> Accueil
    </a>
  </div>
</div>
HTML;

// Actions du header
$headerActions = <<<HTML
<a href="cahierdetextes.php" class="header-icon-button" title="Retour à la liste">
  <i class="fas fa-arrow-left"></i>
</a>
HTML;

include '../assets/css/templates/header-template.php';
?>

<div class="section">
  <div class="section-header">
    <h2>Nouveau devoir</h2>
    <p class="text-muted">Complétez le formulaire pour ajouter un devoir au cahier de textes</p>
  </div>

  <?php if ($message): ?>
    <div class="alert-banner alert-<?= $success ? 'success' : 'error' ?>">
      <i class="fas fa-<?= $success ? 'check-circle' : 'exclamation-circle' ?>"></i>
      <?= htmlspecialchars($message) ?>
      <button class="alert-close">&times;</button>
    </div>
  <?php endif; ?>
  
  <?php if ($erreur): ?>
    <div class="alert-banner alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($erreur) ?>
      <button class="alert-close">&times;</button>
    </div>
  <?php endif; ?>
  
  <div class="card">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        
        <div class="form-grid">
          <div class="form-group" style="grid-column: span 2;">
            <label class="form-label" for="titre">Titre du devoir <span class="required">*</span></label>
            <input type="text" name="titre" id="titre" class="form-control" required placeholder="Titre du devoir">
          </div>
          
          <div class="form-group">
            <label class="form-label" for="classe">Classe <span class="required">*</span></label>
            <select name="classe" id="classe" class="form-select" required>
              <option value="">Sélectionnez une classe</option>
              <?php if (!empty($etablissement_data['classes'])): ?>
                <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
                  <optgroup label="<?= ucfirst($niveau) ?>">
                    <?php foreach ($niveaux as $sousniveau => $classes): ?>
                      <?php foreach ($classes as $classe): ?>
                        <option value="<?= $classe ?>"><?= $classe ?></option>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              <?php endif; ?>
              
              <?php if (!empty($etablissement_data['primaire'])): ?>
                <optgroup label="Primaire">
                  <?php foreach ($etablissement_data['primaire'] as $niveau => $classes): ?>
                    <?php foreach ($classes as $classe): ?>
                      <option value="<?= $classe ?>"><?= $classe ?></option>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label class="form-label" for="nom_matiere">Matière <span class="required">*</span></label>
            <?php if (isTeacher()): ?>
              <select name="nom_matiere" id="nom_matiere" class="form-select" required>
                <option value="">Sélectionnez une matière</option>
                <?php if (!empty($etablissement_data['matieres'])): ?>
                  <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                    <option value="<?= $matiere['nom'] ?>" <?= ($prof_matiere == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            <?php else: ?>
              <select name="nom_matiere" id="nom_matiere" class="form-select" required>
                <option value="">Sélectionnez une matière</option>
                <?php if (!empty($etablissement_data['matieres'])): ?>
                  <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                    <option value="<?= $matiere['nom'] ?>"><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            <?php endif; ?>
          </div>
          
          <div class="form-group">
            <label class="form-label" for="nom_professeur">Professeur <span class="required">*</span></label>
            <?php if (isTeacher()): ?>
              <input type="text" name="nom_professeur" id="nom_professeur" class="form-control" value="<?= htmlspecialchars($nom_professeur) ?>" readonly>
            <?php else: ?>
              <select name="nom_professeur" id="nom_professeur" class="form-select" required>
                <option value="">Sélectionnez un professeur</option>
                <?php foreach ($professeurs as $prof): ?>
                  <option value="<?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>" data-matiere="<?= htmlspecialchars($prof['matiere']) ?>"><?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          
          <div class="form-group">
            <label class="form-label" for="date_ajout">Date d'ajout <span class="required">*</span></label>
            <input type="date" name="date_ajout" id="date_ajout" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          
          <div class="form-group">
            <label class="form-label" for="date_rendu">Date de rendu <span class="required">*</span></label>
            <input type="date" name="date_rendu" id="date_rendu" class="form-control" required>
          </div>
          
          <div class="form-group" style="grid-column: span 2;">
            <label class="form-label" for="description">Description <span class="required">*</span></label>
            <textarea name="description" id="description" class="form-control" rows="6" required placeholder="Description détaillée du devoir"></textarea>
          </div>
        </div>
        
        <div class="form-actions">
          <a href="cahierdetextes.php" class="btn btn-secondary">Annuler</a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Ajouter le devoir
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Si un administrateur ou vie scolaire sélectionne un professeur, 
// sélectionner automatiquement sa matière
<?php if (!isTeacher()): ?>
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
<?php endif; ?>

// Valider que la date de rendu est ultérieure à la date d'ajout
document.querySelector('form').addEventListener('submit', function(e) {
  const dateAjout = new Date(document.getElementById('date_ajout').value);
  const dateRendu = new Date(document.getElementById('date_rendu').value);
  
  if (dateRendu <= dateAjout) {
    e.preventDefault();
    alert("La date de rendu doit être ultérieure à la date d'ajout.");
  }
});
</script>

<?php
include '../assets/css/templates/footer-template.php';
ob_end_flush();
?>