<?php include 'includes/header.php'; include 'includes/db.php'; ?>
<h3>Ajouter une note</h3>
<form method="post">
  <input type="text" name="nom_eleve" placeholder="Nom de l'élève" required><br>
  <input type="text" name="nom_matiere" placeholder="Matière" required><br>
  <input type="text" name="nom_professeur" placeholder="Professeur" required><br>
  <input type="number" name="note" max="20" step="0.1" placeholder="Note sur 20" required><br>
  <input type="date" name="date_ajout" required><br>
  <button type="submit">Ajouter</button>
</form>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('INSERT INTO notes (nom_eleve, nom_matiere, nom_professeur, note, date_ajout) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout']
  ]);
  header('Location: notes.php');
}
?>
</body>
</html>