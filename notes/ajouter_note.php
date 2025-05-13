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
$nom_professeur = $user['prenom'] . ' ' . $user['nom'];

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}
?>

<div class="container">
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
    </select>

    <!-- Champ pour l'élève (maintenant avec autocomplétion) -->
    <label for="nom_eleve">Élève:</label>
    <input type="text" name="nom_eleve" id="nom_eleve" placeholder="Nom de l'élève" required>
    <div id="eleves_suggestions" class="suggestions-container" style="display: none;"></div>

    <!-- Champ pour la matière -->
    <label for="nom_matiere">Matière:</label>
    <select name="nom_matiere" id="nom_matiere" required>
      <option value="">Sélectionnez une matière</option>
      <?php if (!empty($etablissement_data['matieres'])): ?>
        <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
          <option value="<?= $matiere['nom'] ?>"><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>

    <label for="nom_professeur">Professeur:</label>
    <input type="text" name="nom_professeur" id="nom_professeur" value="<?= htmlspecialchars($nom_professeur) ?>" readonly>
    
    <label for="note">Note:</label>
    <input type="number" name="note" id="note" max="20" min="0" step="0.1" placeholder="Note sur 20" required>
    
    <label for="date_ajout">Date:</label>
    <input type="date" name="date_ajout" id="date_ajout" value="<?= date('Y-m-d') ?>" required>
    
    <div style="display: flex; gap: 10px; margin-top: 10px;">
      <button type="submit">Ajouter la note</button>
      <a href="notes.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
</div>

<script>
// Script pour récupérer la liste des élèves par classe
document.getElementById('classe').addEventListener('change', function() {
  const classe = this.value;
  if (!classe) return;
  
  // Ici, on pourrait ajouter un appel Ajax pour récupérer les élèves de la classe sélectionnée
  // et les afficher dans une liste de suggestions
  // Pour l'instant, nous laissons cette fonctionnalité pour une future amélioration
});
</script>

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