<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Nous n'avons plus besoin de démarrer la session, car c'est fait dans Auth
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour ajouter des devoirs
if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
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
  <h3>Ajouter un devoir</h3>
  
  <form method="post">
    <label for="titre">Titre:</label>
    <input type="text" name="titre" id="titre" placeholder="Titre du devoir" required>

    <!-- Champ pour la classe -->
    <label for="classe">Classe:</label>
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

    <!-- Champ pour la matière -->
    <label for="nom_matiere">Matière:</label>
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

    <!-- Champ pour le professeur (sélection depuis la base de données) -->
    <label for="nom_professeur">Professeur:</label>
    <?php if (isTeacher()): ?>
      <!-- Si c'est un professeur, il ne peut ajouter que des devoirs en son nom -->
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
    
    <label for="description">Description:</label>
    <textarea name="description" id="description" rows="6" placeholder="Description détaillée du devoir" required></textarea>
    
    <label for="date_ajout">Date d'ajout:</label>
    <input type="date" name="date_ajout" id="date_ajout" value="<?= date('Y-m-d') ?>" required>
    
    <label for="date_rendu">Date de rendu:</label>
    <input type="date" name="date_rendu" id="date_rendu" required>
    
    <div style="display: flex; gap: 10px; margin-top: 10px;">
      <button type="submit">Ajouter le devoir</button>
      <a href="cahierdetextes.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
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
  
  if (dateRendu < dateAjout) {
    e.preventDefault();
    alert("La date de rendu doit être ultérieure à la date d'ajout.");
  }
});
</script>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('INSERT INTO devoirs (titre, description, classe, nom_matiere, nom_professeur, date_ajout, date_rendu) VALUES (?, ?, ?, ?, ?, ?, ?)');
  $stmt->execute([
    $_POST['titre'],
    $_POST['description'],
    $_POST['classe'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['date_ajout'],
    $_POST['date_rendu']
  ]);
  header('Location: cahierdetextes.php');
  exit;
}

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>