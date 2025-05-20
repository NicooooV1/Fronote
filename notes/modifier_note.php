<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclure les fichiers nécessaires avec des chemins relatifs
include_once 'includes/db.php';
include_once 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour modifier des notes
if (!canManageNotes()) {
  header('Location: notes.php');
  exit;
}

// Utiliser les données utilisateur de la session
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: ' . (defined('LOGIN_URL') ? LOGIN_URL : '../login/public/index.php'));
    exit;
}
$nom_professeur = $user['prenom'] . ' ' . $user['nom'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));
$user_role = $user['profil'];

// Charger les données depuis le fichier JSON en utilisant un chemin relatif
$json_file = dirname(__DIR__) . '/login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: notes.php');
  exit;
}

$id = $_GET['id'];
$stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch();

if (!$note) {
  header('Location: notes.php');
  exit;
}

// Si l'utilisateur est un professeur (et pas un admin ou vie scolaire), 
// il peut seulement modifier ses propres notes
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  if ($note['nom_professeur'] !== $nom_professeur) {
    // Le professeur tente de modifier une note qu'il n'a pas créée
    header('Location: notes.php');
    exit;
  }
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

// Message d'erreur initialisé à vide
$error_message = '';

// Traitement du formulaire soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Vérifier la structure de la table
    $check_columns = $pdo->query("SHOW COLUMNS FROM notes");
    $columns = $check_columns->fetchAll(PDO::FETCH_COLUMN);
    
    // Préparer les champs à mettre à jour
    $fields = [];
    $values = [];
    
    // Champs de base
    $fields[] = 'nom_eleve = ?';
    $values[] = $_POST['nom_eleve'];
    
    // Gérer les différentes possibilités pour le champ matière
    if (in_array('matiere', $columns)) {
      $fields[] = 'matiere = ?';
      $values[] = $_POST['nom_matiere'];
    } else if (in_array('nom_matiere', $columns)) {
      $fields[] = 'nom_matiere = ?';
      $values[] = $_POST['nom_matiere'];
    }
    
    $fields[] = 'nom_professeur = ?';
    $values[] = $_POST['nom_professeur'];
    
    $fields[] = 'note = ?';
    $values[] = $_POST['note'];
    
    if (in_array('date_evaluation', $columns)) {
      $fields[] = 'date_evaluation = ?';
      $values[] = $_POST['date_ajout'];
    }
    
    if (in_array('date_ajout', $columns)) {
      $fields[] = 'date_ajout = ?';
      $values[] = $_POST['date_ajout'];
    }
    
    $fields[] = 'classe = ?';
    $values[] = $_POST['classe'];
    
    if (in_array('coefficient', $columns)) {
      $fields[] = 'coefficient = ?';
      $values[] = $_POST['coefficient'];
    }
    
    if (in_array('commentaire', $columns)) {
      $fields[] = 'commentaire = ?';
      $values[] = $_POST['description'];
    }
    
    // Vérifier si la colonne 'trimestre' existe
    if (in_array('trimestre', $columns)) {
      $fields[] = 'trimestre = ?';
      $values[] = isset($_POST['trimestre']) ? $_POST['trimestre'] : $trimestre_actuel;
    }
    
    // Ajouter l'ID de la note à la fin
    $values[] = $id;
    
    // Construire la requête SQL
    $query = 'UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = ?';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($values);
    
    // Redirection après succès
    $_SESSION['success_message'] = "La note a été modifiée avec succès.";
    header('Location: notes.php');
    exit;
  } catch (PDOException $e) {
    error_log("Erreur lors de la mise à jour de la note: " . $e->getMessage());
    $error_message = "Une erreur est survenue lors de la mise à jour de la note: " . $e->getMessage();
  }
}

// Variables pour le template
$pageTitle = "Modifier une note";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> - PRONOTE</title>
  <link rel="stylesheet" href="assets/css/notes.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Menu mobile -->
    <div class="mobile-menu-toggle" id="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </div>
    <div class="page-overlay" id="page-overlay"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-container">
            <div class="app-logo">P</div>
            <div class="app-title">PRONOTE</div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Navigation</div>
            <div class="sidebar-nav">
                <a href="../accueil/accueil.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
                    <span>Accueil</span>
                </a>
                <a href="notes.php" class="sidebar-nav-item active">
                    <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Notes</span>
                </a>
                <a href="../agenda/agenda.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
                    <span>Agenda</span>
                </a>
                <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-book"></i></span>
                    <span>Cahier de textes</span>
                </a>
                <a href="../messagerie/index.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
                    <span>Messagerie</span>
                </a>
                <?php if ($user_role === 'vie_scolaire' || $user_role === 'administrateur'): ?>
                <a href="../absences/absences.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
                    <span>Absences</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Actions</div>
            <div class="sidebar-nav">
                <a href="notes.php" class="create-button">
                    <i class="fas fa-arrow-left"></i> Retour aux notes
                </a>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Informations</div>
            <div class="info-item">
                <div class="info-label">Date</div>
                <div class="info-value"><?= date('d/m/Y') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Période</div>
                <div class="info-value"><?= $trimestre_actuel ?>ème trimestre</div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <div class="top-header">
        <div class="page-title">
          <h1>Modifier une note</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">
            <i class="fas fa-sign-out-alt"></i>
          </a>
          <div class="user-avatar" title="<?= htmlspecialchars($nom_professeur) ?>"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Welcome Banner -->
      <div class="welcome-banner">
          <div class="welcome-content">
              <h2>Modifier une note</h2>
              <p>Vous modifiez la note de <?= htmlspecialchars($note['nom_eleve']) ?> en <?= htmlspecialchars($note['matiere']) ?></p>
          </div>
          <div class="welcome-logo">
              <i class="fas fa-edit"></i>
          </div>
      </div>
      
      <div class="content-container">
        <?php if ($error_message): ?>
          <div class="alert-banner alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
          </div>
        <?php endif; ?>
        
        <div class="form-container">
          <form method="post">
            <div class="form-grid">
              <!-- Champ pour la classe -->
              <div class="form-group">
                <label for="classe" class="form-label">Classe<span class="required">*</span></label>
                <select name="classe" id="classe" class="form-select" required>
                  <option value="">Sélectionnez une classe</option>
                  <?php if (!empty($etablissement_data['classes'])): ?>
                    <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
                      <optgroup label="<?= ucfirst($niveau) ?>">
                        <?php foreach ($niveaux as $sousniveau => $classes): ?>
                          <?php foreach ($classes as $classe): ?>
                            <option value="<?= htmlspecialchars($classe) ?>" <?= ($note['classe'] == $classe) ? 'selected' : '' ?>><?= htmlspecialchars($classe) ?></option>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  
                  <!-- Ajout des classes primaires si elles existent -->
                  <?php if (!empty($etablissement_data['primaire'])): ?>
                    <optgroup label="Primaire">
                      <?php foreach ($etablissement_data['primaire'] as $niveau => $classes): ?>
                        <?php foreach ($classes as $classe): ?>
                          <option value="<?= htmlspecialchars($classe) ?>" <?= ($note['classe'] == $classe) ? 'selected' : '' ?>><?= htmlspecialchars($classe) ?></option>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Champ pour l'élève -->
              <div class="form-group">
                <label for="nom_eleve" class="form-label">Élève<span class="required">*</span></label>
                <select name="nom_eleve" id="nom_eleve" class="form-select" required>
                  <option value="">Sélectionnez un élève</option>
                  <?php foreach ($eleves as $eleve): ?>
                    <option value="<?= htmlspecialchars($eleve['prenom']) ?>" 
                            data-classe="<?= htmlspecialchars($eleve['classe']) ?>" 
                            <?= ($note['nom_eleve'] == $eleve['prenom']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?> (<?= htmlspecialchars($eleve['classe']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <!-- Champ pour la matière -->
              <div class="form-group">
                <label for="nom_matiere" class="form-label">Matière<span class="required">*</span></label>
                <select name="nom_matiere" id="nom_matiere" class="form-select" required>
                  <option value="">Sélectionnez une matière</option>
                  <?php if (!empty($etablissement_data['matieres'])): ?>
                    <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                      <option value="<?= htmlspecialchars($matiere['nom']) ?>" <?= ($note['matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= htmlspecialchars($matiere['nom']) ?> (<?= htmlspecialchars($matiere['code']) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              
              <!-- Champ pour le professeur -->
              <div class="form-group">
                <label for="nom_professeur" class="form-label">Professeur<span class="required">*</span></label>
                <?php if (isTeacher() && !isAdmin() && !isVieScolaire()): ?>
                  <!-- Si c'est un professeur, il ne peut pas changer le nom du professeur -->
                  <input type="text" name="nom_professeur" id="nom_professeur" class="form-control" value="<?= htmlspecialchars($note['nom_professeur']) ?>" readonly>
                <?php else: ?>
                  <!-- Admin et vie scolaire peuvent choisir n'importe quel professeur -->
                  <select name="nom_professeur" id="nom_professeur" class="form-select" required>
                    <option value="">Sélectionnez un professeur</option>
                    <?php foreach ($professeurs as $prof): ?>
                      <?php $prof_fullname = $prof['prenom'] . ' ' . $prof['nom']; ?>
                      <option value="<?= htmlspecialchars($prof_fullname) ?>" 
                              data-matiere="<?= htmlspecialchars($prof['matiere']) ?>"
                              <?= ($note['nom_professeur'] == $prof_fullname) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prof_fullname) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
              
              <!-- Champ pour la note -->
              <div class="form-group">
                <label for="note" class="form-label">Note<span class="required">*</span></label>
                <input type="number" name="note" id="note" class="form-control" max="20" min="0" step="0.1" value="<?= htmlspecialchars($note['note']) ?>" required>
              </div>
              
              <!-- Champ pour le coefficient -->
              <div class="form-group">
                <label for="coefficient" class="form-label">Coefficient<span class="required">*</span></label>
                <input type="number" name="coefficient" id="coefficient" class="form-control" min="1" max="10" step="1" value="<?= isset($note['coefficient']) ? htmlspecialchars($note['coefficient']) : 1 ?>" required>
              </div>
              
              <!-- Champ pour la date -->
              <div class="form-group">
                <label for="date_ajout" class="form-label">Date<span class="required">*</span></label>
                <input type="date" name="date_ajout" id="date_ajout" class="form-control" value="<?= htmlspecialchars($note['date_ajout'] ?? $note['date_evaluation'] ?? date('Y-m-d')) ?>" required>
              </div>
              
              <!-- Champ pour le trimestre -->
              <div class="form-group">
                <label for="trimestre" class="form-label">Trimestre<span class="required">*</span></label>
                <select name="trimestre" id="trimestre" class="form-select" required>
                  <option value="1" <?= (isset($note['trimestre']) && $note['trimestre'] == 1) ? 'selected' : '' ?>>Trimestre 1</option>
                  <option value="2" <?= (isset($note['trimestre']) && $note['trimestre'] == 2) ? 'selected' : '' ?>>Trimestre 2</option>
                  <option value="3" <?= (isset($note['trimestre']) && $note['trimestre'] == 3) ? 'selected' : '' ?>>Trimestre 3</option>
                </select>
              </div>
            </div>
              
            <!-- Champ pour la description -->
            <div class="form-group mt-3">
              <label for="description" class="form-label">Intitulé de l'évaluation<span class="required">*</span></label>
              <input type="text" name="description" id="description" class="form-control" value="<?= isset($note['commentaire']) ? htmlspecialchars($note['commentaire']) : (isset($note['description']) ? htmlspecialchars($note['description']) : '') ?>" placeholder="Ex: Contrôle évaluation trimestre" required>
            </div>
            
            <div class="form-actions">
              <a href="notes.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Annuler
              </a>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Mettre à jour
              </button>
            </div>
          </form>
        </div>
      </div>
      
      <!-- Footer -->
      <div class="footer">
          <div class="footer-content">
              <div class="footer-links">
                  <a href="#">Mentions Légales</a>
              </div>
              <div class="footer-copyright">
                  &copy; <?= date('Y') ?> PRONOTE - Tous droits réservés
              </div>
          </div>
      </div>
    </div>
  </div>
  
  <script>
    // Navigation mobile
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const pageOverlay = document.getElementById('page-overlay');
        
        if (mobileMenuToggle && sidebar && pageOverlay) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-visible');
                pageOverlay.classList.toggle('visible');
            });
            
            pageOverlay.addEventListener('click', function() {
                sidebar.classList.remove('mobile-visible');
                pageOverlay.classList.remove('visible');
            });
        }
    });
  </script>
</body>
</html>

<?php
ob_end_flush();
?>