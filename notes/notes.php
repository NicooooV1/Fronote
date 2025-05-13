<?php include 'includes/header.php'; include 'includes/db.php'; ?>
<h3>Liste des notes</h3>
<a href="ajouter_note.php">Créer une note</a>
<div class="notes">
<?php
$stmt = $pdo->query('SELECT * FROM notes ORDER BY date_ajout DESC');
while ($note = $stmt->fetch()) {
  echo "<div class='note'>
    <strong>Classe :</strong> {$note['classe']}<br>
    <strong>Élève :</strong> {$note['nom_eleve']}<br>
    <strong>Matière :</strong> {$note['nom_matiere']}<br>
    <strong>Professeur :</strong> {$note['nom_professeur']}<br>
    <strong>Date :</strong> {$note['date_ajout']}<br>
    <strong>Note :</strong> {$note['note']}/20<br>
    <a href='modifier_note.php?id={$note['id']}'>Modifier</a>
  </div>";
}
?>
</div>
</body>
</html>