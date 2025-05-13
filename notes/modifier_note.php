<?php include 'includes/header.php'; include 'includes/db.php'; ?>
<?php
$id = $_GET['id'];
$stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch();
?>
<h3>Modifier la note</h3>
<form method="post">
  <input type="text" name="nom_eleve" value="<?= $note['nom_eleve'] ?>" required><br>
  <input type="text" name="nom_matiere" value="<?= $note['nom_matiere'] ?>" required><br>
  <input type="text" name="nom_professeur" value="<?= $note['nom_professeur'] ?>" required><br>
  <input type="number" name="note" max="20" step="0.1" value="<?= $note['note'] ?>" required><br>
  <input type="date" name="date_ajout" value="<?= $note['date_ajout'] ?>" required><br>
  <button type="submit">Mettre à jour</button>
</form>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('UPDATE notes SET nom_eleve = ?, nom_matiere = ?, nom_professeur = ?, note = ?, date_ajout = ? WHERE id = ?');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
    $_POST['nom_professeur'],
    $_POST['note'],
    $_POST['date_ajout'],
    $id
  ]);
  header('Location: notes.php');
}
?>
</body>
</html>