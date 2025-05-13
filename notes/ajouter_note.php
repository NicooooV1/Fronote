<?php include 'includes/header.php'; include 'includes/db.php'; ?>

<?php
// Charger les données depuis le fichier JSON
$json_file = '../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}
?>

<h3>Ajouter une note</h3>
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
              <option value="<?= $classe ?>"><?= $classe ?></option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    <?php endif; ?>
  </select><br>

  <!-- Champ pour l'élève (toujours en texte libre pour l'instant) -->
  <input type="text" name="nom_eleve" placeholder="Nom de l'élève" required><br>

  <!-- Champ pour la matière -->
  <label for="nom_matiere">Matière:</label>
  <select name="nom_matiere" id="nom_matiere" required>
    <option value="">Sélectionnez une matière</option>
    <?php if (!empty($etablissement_data['matieres'])): ?>
      <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
        <option value="<?= $matiere['nom'] ?>"><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
      <?php endforeach; ?>
    <?php endif; ?>
  </select><br>

  <input type="text" name="nom_professeur" placeholder="Professeur" required><br>
  <input type="number" name="note" max="20" step="0.1" placeholder="Note sur 20" required><br>
  <input type="date" name="date_ajout" value="<?= date('Y-m-d') ?>" required><br>
  <button type="submit">Ajouter</button>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('INSERT INTO notes (nom_eleve, nom_matiere, nom_professeur, note, date_ajout, classe) VALUES (?, ?, ?, ?, ?, ?)');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout'],
    $_POST['classe']
  ]);
  header('Location: notes.php');
}
?>
</body>
</html>