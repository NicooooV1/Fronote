<?php
ob_start();

include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
  exit;
}

$user = getCurrentUser();
$nom_professeur = getUserFullName();

require_once __DIR__ . '/../../API/data.php';
$etablissement_data = getEtablissementData();

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
  if ($devoir['nom_professeur'] !== $nom_professeur) {
    header('Location: cahierdetextes.php');
    exit;
  }
}

$stmt_profs = $pdo->query('SELECT id, nom, prenom, matiere FROM professeurs ORDER BY nom, prenom');
$professeurs = $stmt_profs->fetchAll();

$prof_matiere = '';
if (isTeacher()) {
  $stmt_prof = $pdo->prepare('SELECT matiere FROM professeurs WHERE nom = ? AND prenom = ?');
  $stmt_prof->execute([$user['nom'], $user['prenom']]);
  $prof_data = $stmt_prof->fetch();
  $prof_matiere = $prof_data ? $prof_data['matiere'] : '';
}
?>

<div class="container">
  <h3>Modifier le devoir</h3>
  
  <form method="post">
    <label for="titre">Titre:</label>
    <input type="text" name="titre" id="titre" value="<?= htmlspecialchars($devoir['titre']) ?>" required>

    <label for="classe">Classe:</label>
    <select name="classe" id="classe" required>
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

    <label for="nom_matiere">Matière:</label>
    <select name="nom_matiere" id="nom_matiere" required>
      <option value="">Sélectionnez une matière</option>
      <?php if (!empty($etablissement_data['matieres'])): ?>
        <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
          <option value="<?= $matiere['nom'] ?>" <?= ($devoir['nom_matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>

    <label for="nom_professeur">Professeur:</label>
    <?php if (isTeacher() && !isAdmin() && !isVieScolaire()): ?>
      <input type="text" name="nom_professeur" id="nom_professeur" value="<?= htmlspecialchars($devoir['nom_professeur']) ?>" readonly>
    <?php else: ?>
      <select name="nom_professeur" id="nom_professeur" required>
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
    
    <label for="description">Description:</label>
    <textarea name="description" id="description" rows="6" required><?= htmlspecialchars($devoir['description']) ?></textarea>
    
    <label for="date_ajout">Date d'ajout:</label>
    <input type="date" name="date_ajout" id="date_ajout" value="<?= $devoir['date_ajout'] ?>" required>
    
    <label for="date_rendu">Date de rendu:</label>
    <input type="date" name="date_rendu" id="date_rendu" value="<?= $devoir['date_rendu'] ?>" required>
    
    <div style="display: flex; gap: 10px; margin-top: 10px;">
      <button type="submit" style="flex: 1;">Mettre à jour</button>
      <a href="cahierdetextes.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
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

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
  header('Location: cahierdetextes.php');
  exit;
}

ob_end_flush();
?>