<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Nous n'avons plus besoin de démarrer la session, car c'est fait dans Auth
include 'includes/header.php'; 
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
?>

<div class="container">
  <h3>Ajouter une note</h3>
  
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
                    <option value="<?= $classe ?>"><?= $classe ?></option>
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
                  <option value="<?= $classe ?>"><?= $classe ?></option>
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
            <option value="<?= htmlspecialchars($eleve['prenom']) ?>" data-classe="<?= htmlspecialchars($eleve['classe']) ?>"><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?> (<?= htmlspecialchars($eleve['classe']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Champ pour la matière -->
      <div class="form-group">
        <label for="nom_matiere">Matière:<span class="required">*</span></label>
        <?php if (isTeacher()): ?>
          <!-- Si c'est un professeur, on présélectionne sa matière -->
          <select name="nom_matiere" id="nom_matiere" required>
            <option value="">Sélectionnez une matière</option>
            <?php if (!empty($etablissement_data['matieres'])): ?>
              <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                <option value="<?= $matiere['nom'] ?>" <?= ($prof_matiere == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        <?php else: ?>
          <!-- Pour admin/vie scolaire -->
          <select name="nom_matiere" id="nom_matiere" required>
            <option value="">Sélectionnez une matière</option>
            <?php if (!empty($etablissement_data['matieres'])): ?>
              <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
                <option value="<?= $matiere['nom'] ?>"><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        <?php endif; ?>
      </div>

      <!-- Champ pour le professeur -->
      <div class="form-group">
        <label for="nom_professeur">Professeur:<span class="required">*</span></label>
        <?php if (isTeacher()): ?>
          <!-- Si c'est un professeur, il ne peut ajouter que des notes en son nom -->
          <input type="text" name="nom_professeur" id="nom_professeur" value="<?= htmlspecialchars($nom_professeur) ?>" readonly>
        <?php else: ?>
          <!-- Admin et vie scolaire peuvent choisir n'importe quel professeur -->
          <select name="nom_professeur" id="nom_professeur" required>
            <option value="">Sélectionnez un professeur</option>
            <?php foreach ($professeurs as $prof): ?>
              <option value="<?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>" data-matiere="<?= htmlspecialchars($prof['matiere']) ?>"><?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      
      <!-- Champ pour la note -->
      <div class="form-group">
        <label for="note">Note:<span class="required">*</span></label>
        <input type="number" name="note" id="note" max="20" min="0" step="0.1" placeholder="Note sur 20" required>
      </div>
      
      <!-- Champ pour le coefficient -->
      <div class="form-group">
        <label for="coefficient">Coefficient:<span class="required">*</span></label>
        <input type="number" name="coefficient" id="coefficient" min="1" max="10" step="1" value="1" required>
      </div>
      
      <!-- Champ pour la date -->
      <div class="form-group">
        <label for="date_ajout">Date:<span class="required">*</span></label>
        <input type="date" name="date_ajout" id="date_ajout" value="<?= date('Y-m-d') ?>" required>
      </div>
      
      <!-- Champ pour la description (intitulé) -->
      <div class="form-group form-full">
        <label for="description">Intitulé de l'évaluation:<span class="required">*</span></label>
        <input type="text" name="description" id="description" placeholder="Ex: Contrôle évaluation trimestre" required>
      </div>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
      <button type="submit" style="flex: 1;">Ajouter la note</button>
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

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('INSERT INTO notes (nom_eleve, nom_matiere, nom_professeur, note, date_ajout, classe, coefficient, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout'],
    $_POST['classe'],
    $_POST['coefficient'],
    $_POST['description']
  ]);
  header('Location: notes.php');
  exit;
}

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>