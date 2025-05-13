<?php
// Nous n'avons plus besoin de démarrer la session, car c'est fait dans Auth
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur est un professeur
if (!isTeacher()) {
  header('Location: notes.php');
  exit;
}

// Utiliser les données utilisateur de la session
$user = $_SESSION['user'];

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
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

// Vérifier que le professeur connecté est bien celui qui a créé la note
// ou qu'il est un administrateur (fonctionnalité à implémenter si nécessaire)
if ($note['nom_professeur'] !== $user['prenom'] . ' ' . $user['nom'] && !isAdmin()) {
  // On pourrait ajouter un message d'erreur ici
  header('Location: notes.php');
  exit;
}
?>

<div class="container">
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
    </select>

    <label for="nom_eleve">Élève:</label>
    <input type="text" name="nom_eleve" id="nom_eleve" value="<?= htmlspecialchars($note['nom_eleve']) ?>" required>
    
    <!-- Champ pour la matière -->
    <label for="nom_matiere">Matière:</label>
    <select name="nom_matiere" id="nom_matiere" required>
      <option value="">Sélectionnez une matière</option>
      <?php if (!empty($etablissement_data['matieres'])): ?>
        <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
          <option value="<?= $matiere['nom'] ?>" <?= ($note['nom_matiere'] == $matiere['nom']) ? 'selected' : '' ?>><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>
    
    <label for="nom_professeur">Professeur:</label>
    <input type="text" name="nom_professeur" id="nom_professeur" value="<?= htmlspecialchars($note['nom_professeur']) ?>" readonly>
    
    <label for="note">Note:</label>
    <input type="number" name="note" id="note" max="20" min="0" step="0.1" value="<?= $note['note'] ?>" required>
    
    <label for="date_ajout">Date:</label>
    <input type="date" name="date_ajout" id="date_ajout" value="<?= $note['date_ajout'] ?>" required>
    
    <div style="display: flex; gap: 10px; margin-top: 10px;">
      <button type="submit" style="flex: 1;">Mettre à jour</button>
      <a href="notes.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
</div>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('UPDATE notes SET nom_eleve = ?, nom_matiere = ?, note = ?, date_ajout = ?, classe = ? WHERE id = ?');
  $stmt->execute([
    $_POST['nom_eleve'],
    $_POST['nom_matiere'],
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