<?php include 'includes/header.php'; include 'includes/db.php'; ?>

<?php
// Charger les données depuis le fichier JSON
$json_file = '../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

$id = $_GET['id'];
$stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch();
?>

<h3>Modifier la note</h3>
<form method="post">
  <!-- Champ pour la classe -->
  <label for="classe">Classe:</label>
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
  </select><br>

  <input type="text" name="nom_eleve" value="<?= $note['nom_eleve'] ?>" required><br>
  
  <!-- Champ pour la matière -->
  <label for="nom_matiere">Matière:</label>
  <select name="nom_matiere" id="nom_matiere" required>
    <option value="">Sélectionnez une matière</option>
    <?php if (!empty($etablissement_data['matieres'])): ?>
      <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
        <option value="<?= $matiere['nom'] ?>" <?= ($note['nom_matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
      <?php endforeach; ?>
    <?php endif; ?>
  </select><br>
  
  <input type="text" name="nom_professeur" value="<?= $note['nom_professeur'] ?>" required><br>
  <input type="number" name="note" max="20" step="0.1" value="<?= $note['note'] ?>" required><br>
  <input type="date" name="date_ajout" value="<?= $note['date_ajout'] ?>" required><br>
  <button type="submit">Mettre à jour</button>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('UPDATE notes SET nom_eleve = ?, nom_matiere = ?, nom_professeur = ?, note = ?, date_ajout = ?, classe = ? WHERE id = ?');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout'],
    $_POST['classe'],
    $id
  ]);
  header('Location: notes.php');
}
?>
</body>
</html>