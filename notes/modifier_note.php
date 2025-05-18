<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclure les fichiers nécessaires avec des chemins relatifs
include_once 'includes/header.php'; 
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
    header('Location: ../login/public/index.php');
    exit;
}
$nom_professeur = $user['prenom'] . ' ' . $user['nom'];

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
?>

<div class="container">
  <h3>Modifier la note</h3>
  
  <form method="post">
    <div class="form-grid">
      <!-- Champ pour la classe -->
      <div class="form-group">
        <label for="classe">Classe:<span class="required">*</span></label>
        <select name="classe" id="classe" required>
          <option value="">Sélectionnez une classe</option>
          <?php if (!empty($etablissement_data['classes'])): ?>
            <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
              <optgroup label="<?= ucfirst($niveau) ?>">
                <?php foreach ($niveaux as $sousniveau => $classes): ?>
                  <?php foreach ($classes as $classe): ?>
                    <option value="<?= $classe ?>" <?= ($note['classe'] == $classe) ? 'selected' : '' ?>><?= $classe ?></option>
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
                  <option value="<?= $classe ?>" <?= ($note['classe'] == $classe) ? 'selected' : '' ?>><?= $classe ?></option>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <!-- Champ pour l'élève -->
      <div class="form-group">
        <label for="nom_eleve">Élève:<span class="required">*</span></label>
        <select name="nom_eleve" id="nom_eleve" required>
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
        <label for="nom_matiere">Matière:<span class="required">*</span></label>
        <select name="nom_matiere" id="nom_matiere" required>
          <option value="">Sélectionnez une matière</option>
          <?php if (!empty($etablissement_data['matieres'])): ?>
            <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
              <option value="<?= $matiere['nom'] ?>" <?= ($note['nom_matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      
      <!-- Champ pour le professeur -->
      <div class="form-group">
        <label for="nom_professeur">Professeur:<span class="required">*</span></label>
        <?php if (isTeacher() && !isAdmin() && !isVieScolaire()): ?>
          <!-- Si c'est un professeur, il ne peut pas changer le nom du professeur -->
          <input type="text" name="nom_professeur" id="nom_professeur" value="<?= htmlspecialchars($note['nom_professeur']) ?>" readonly>
        <?php else: ?>
          <!-- Admin et vie scolaire peuvent choisir n'importe quel professeur -->
          <select name="nom_professeur" id="nom_professeur" required>
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
        <label for="note">Note:<span class="required">*</span></label>
        <input type="number" name="note" id="note" max="20" min="0" step="0.1" value="<?= $note['note'] ?>" required>
      </div>
      
      <!-- Champ pour le coefficient -->
      <div class="form-group">
        <label for="coefficient">Coefficient:<span class="required">*</span></label>
        <input type="number" name="coefficient" id="coefficient" min="1" max="10" step="1" value="<?= isset($note['coefficient']) ? $note['coefficient'] : 1 ?>" required>
      </div>
      
      <!-- Champ pour la date -->
      <div class="form-group">
        <label for="date_ajout">Date:<span class="required">*</span></label>
        <input type="date" name="date_ajout" id="date_ajout" value="<?= $note['date_ajout'] ?>" required>
      </div>
      
      <!-- Champ pour la description -->
      <div class="form-group form-full">
        <label for="description">Intitulé de l'évaluation:<span class="required">*</span></label>
        <input type="text" name="description" id="description" value="<?= isset($note['description']) ? htmlspecialchars($note['description']) : '' ?>" placeholder="Ex: Contrôle évaluation trimestre" required>
      </div>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
      <button type="submit" style="flex: 1;">Mettre à jour</button>
      <a href="notes.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
</div>

<script>
// Script pour filtrer les élèves en fonction de la classe sélectionnée
document.getElementById('classe').addEventListener('change', function() {
  const classeSelectionnee = this.value;
  const selectEleve = document.getElementById('nom_eleve');
  const options = selectEleve.options;
  
  // Réinitialiser le sélecteur d'élève si la classe change
  if (selectEleve.selectedIndex > 0) {
    const classeEleve = options[selectEleve.selectedIndex].getAttribute('data-classe');
    if (classeEleve !== classeSelectionnee) {
      selectEleve.selectedIndex = 0;
    }
  }
  
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

<?php if (!isTeacher() || isAdmin() || isVieScolaire()): ?>
// Filtrer les professeurs en fonction de la matière sélectionnée
document.getElementById('nom_matiere').addEventListener('change', function() {
  const matiereSelectionnee = this.value;
  const selectProf = document.getElementById('nom_professeur');
  const options = selectProf.options;
  
  // Réinitialiser le sélecteur de professeur si la matière change
  if (selectProf.selectedIndex > 0) {
    const matiereProf = options[selectProf.selectedIndex].getAttribute('data-matiere');
    if (matiereProf !== matiereSelectionnee) {
      selectProf.selectedIndex = 0;
    }
  }
  
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

// Déclencher l'événement au chargement pour synchroniser la matière avec le professeur sélectionné
window.addEventListener('load', function() {
  const selectProf = document.getElementById('nom_professeur');
  if (selectProf.tagName === 'SELECT' && selectProf.selectedIndex > 0) {
    selectProf.dispatchEvent(new Event('change'));
  }
});
<?php endif; ?>

// Déclencher l'événement de changement de classe au chargement pour filtrer les élèves
window.addEventListener('load', function() {
  document.getElementById('classe').dispatchEvent(new Event('change'));
});
</script>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('UPDATE notes SET nom_eleve = ?, nom_matiere = ?, nom_professeur = ?, note = ?, date_ajout = ?, classe = ?, coefficient = ?, description = ? WHERE id = ?');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout'],
    $_POST['classe'],
    $_POST['coefficient'],
    $_POST['description'],
    $id
  ]);
  header('Location: notes.php');
  exit;
}

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>