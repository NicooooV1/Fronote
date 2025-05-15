<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour modifier des événements
if (!canManageEvents()) {
  header('Location: agenda.php');
  exit;
}

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: agenda.php');
  exit;
}

$id = $_GET['id'];

// Récupérer les détails de l'événement
$stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
$stmt->execute([$id]);
$evenement = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifier que l'événement existe
if (!$evenement) {
  header('Location: agenda.php');
  exit;
}

// Vérifier que l'utilisateur a le droit de modifier cet événement
if (!canEditEvent($evenement)) {
  header('Location: agenda.php');
  exit;
}

// Utiliser les données utilisateur de la session
$user = $_SESSION['user'];
$nom_createur = $user['prenom'] . ' ' . $user['nom'];

// Charger les données depuis le fichier JSON
$json_file = __DIR__ . '/../login/data/etablissement.json';
$etablissement_data = [];

if (file_exists($json_file)) {
  $etablissement_data = json_decode(file_get_contents($json_file), true);
}

// Types d'événements possibles
$types_evenements = [
  'cours' => 'Cours',
  'devoirs' => 'Devoirs',
  'reunion' => 'Réunion',
  'examen' => 'Examen',
  'sortie' => 'Sortie scolaire',
  'autre' => 'Autre'
];

// Options de visibilité
$options_visibilite = [
  'public' => 'Public (visible par tous)',
  'professeurs' => 'Professeurs uniquement',
  'eleves' => 'Élèves uniquement',
  'classes_specifiques' => 'Classes spécifiques',
  'participants' => 'Participants sélectionnés uniquement'
];

// Préparer les données de l'événement pour l'affichage
$date_debut = new DateTime($evenement['date_debut']);
$date_fin = new DateTime($evenement['date_fin']);

// Déterminer la visibilité actuelle et les classes sélectionnées
$visibilite_actuelle = $evenement['visibilite'];
$classes_selectionnees = [];

if (strpos($visibilite_actuelle, 'classes:') === 0) {
  $classes_selectionnees = explode(',', substr($visibilite_actuelle, 8));
  $visibilite_actuelle = 'classes_specifiques';
}

// Traitement du formulaire
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validation des champs obligatoires
  if (empty($_POST['titre']) || empty($_POST['date_debut']) || empty($_POST['heure_debut']) || 
      empty($_POST['date_fin']) || empty($_POST['heure_fin']) || empty($_POST['type_evenement'])) {
    $erreur = "Veuillez remplir tous les champs obligatoires.";
  } else {
    // Formatage des dates
    $date_debut = $_POST['date_debut'] . ' ' . $_POST['heure_debut'] . ':00';
    $date_fin = $_POST['date_fin'] . ' ' . $_POST['heure_fin'] . ':00';
    
    // Vérifier que la date de fin est après la date de début
    if (strtotime($date_fin) <= strtotime($date_debut)) {
      $erreur = "La date/heure de fin doit être après la date/heure de début.";
    } else {
      // Traitement de la visibilité et des classes sélectionnées
      $visibilite = $_POST['visibilite'];
      $classes_selectionnees = '';
      
      if ($visibilite === 'classes_specifiques' && !empty($_POST['classes'])) {
        $classes_selectionnees = implode(',', $_POST['classes']);
        $visibilite = 'classes:' . $classes_selectionnees;
      }
      
      // Mise à jour dans la base de données
      try {
        $stmt = $pdo->prepare('UPDATE evenements SET 
                                titre = ?, 
                                description = ?, 
                                date_debut = ?, 
                                date_fin = ?, 
                                type_evenement = ?, 
                                statut = ?, 
                                visibilite = ?, 
                                lieu = ?, 
                                classes = ?, 
                                matieres = ? 
                              WHERE id = ?');
        
        $stmt->execute([
          $_POST['titre'],
          $_POST['description'] ?? '',
          $date_debut,
          $date_fin,
          $_POST['type_evenement'],
          $_POST['statut'] ?? 'actif',
          $visibilite,
          $_POST['lieu'] ?? '',
          $visibilite === 'classes_specifiques' ? $classes_selectionnees : $evenement['classes'],
          $_POST['matieres'] ?? '',
          $id
        ]);
        
        $message = "L'événement a été mis à jour avec succès.";
        
        // Redirection après un court délai
        header('refresh:2;url=details_evenement.php?id=' . $id);
      } catch (PDOException $e) {
        $erreur = "Erreur lors de la mise à jour de l'événement : " . $e->getMessage();
      }
    }
  }
}
?>

<div class="container">
  <h3>Modifier l'événement</h3>
  
  <?php if ($message): ?>
    <div class="message success"><?= $message ?></div>
  <?php endif; ?>
  
  <?php if ($erreur): ?>
    <div class="message error"><?= $erreur ?></div>
  <?php endif; ?>
  
  <form method="post" class="event-form">
    <div class="form-row">
      <div class="form-group">
        <label for="titre">Titre de l'événement*:</label>
        <input type="text" name="titre" id="titre" value="<?= htmlspecialchars($evenement['titre']) ?>" required>
      </div>
      
      <div class="form-group">
        <label for="type_evenement">Type d'événement*:</label>
        <select name="type_evenement" id="type_evenement" required>
          <option value="">Sélectionnez un type</option>
          <?php foreach ($types_evenements as $key => $label): ?>
            <option value="<?= $key ?>" <?= ($evenement['type_evenement'] === $key) ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="date_debut">Date de début*:</label>
        <input type="date" name="date_debut" id="date_debut" value="<?= $date_debut->format('Y-m-d') ?>" required>
      </div>
      
      <div class="form-group">
        <label for="heure_debut">Heure de début*:</label>
        <input type="time" name="heure_debut" id="heure_debut" value="<?= $date_debut->format('H:i') ?>" required>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="date_fin">Date de fin*:</label>
        <input type="date" name="date_fin" id="date_fin" value="<?= $date_fin->format('Y-m-d') ?>" required>
      </div>
      
      <div class="form-group">
        <label for="heure_fin">Heure de fin*:</label>
        <input type="time" name="heure_fin" id="heure_fin" value="<?= $date_fin->format('H:i') ?>" required>
      </div>
    </div>
    
    <div class="form-group">
      <label for="description">Description:</label>
      <textarea name="description" id="description" rows="4"><?= htmlspecialchars($evenement['description']) ?></textarea>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="lieu">Lieu:</label>
        <input type="text" name="lieu" id="lieu" value="<?= htmlspecialchars($evenement['lieu']) ?>">
      </div>
      
      <div class="form-group">
        <label for="statut">Statut:</label>
        <select name="statut" id="statut">
          <option value="actif" <?= ($evenement['statut'] === 'actif') ? 'selected' : '' ?>>Actif</option>
          <option value="annulé" <?= ($evenement['statut'] === 'annulé') ? 'selected' : '' ?>>Annulé</option>
          <option value="reporté" <?= ($evenement['statut'] === 'reporté') ? 'selected' : '' ?>>Reporté</option>
        </select>
      </div>
    </div>
    
    <div class="form-group">
      <label for="visibilite">Visibilité*:</label>
      <select name="visibilite" id="visibilite" required>
        <?php foreach ($options_visibilite as $key => $label): ?>
          <option value="<?= $key ?>" <?= ($visibilite_actuelle === $key) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    
    <!-- Section pour les classes (visible uniquement si "Classes spécifiques" est sélectionné) -->
    <div id="section_classes" style="display: <?= ($visibilite_actuelle === 'classes_specifiques') ? 'block' : 'none' ?>;">
      <label>Sélectionnez les classes concernées:</label>
      <div class="checkbox-group">
        <?php if (!empty($etablissement_data['classes'])): ?>
          <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
            <div class="checkbox-group-section">
              <h4><?= ucfirst($niveau) ?></h4>
              <?php foreach ($niveaux as $sousniveau => $classes): ?>
                <?php foreach ($classes as $classe): ?>
                  <div class="checkbox-item">
                    <input type="checkbox" name="classes[]" id="classe_<?= $classe ?>" value="<?= $classe ?>"
                           <?= (in_array($classe, $classes_selectionnees)) ? 'checked' : '' ?>>
                    <label for="classe_<?= $classe ?>"><?= $classe ?></label>
                  </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Ajout des classes primaires si elles existent -->
        <?php if (!empty($etablissement_data['primaire'])): ?>
          <div class="checkbox-group-section">
            <h4>Primaire</h4>
            <?php foreach ($etablissement_data['primaire'] as $niveau => $classes): ?>
              <?php foreach ($classes as $classe): ?>
                <div class="checkbox-item">
                  <input type="checkbox" name="classes[]" id="classe_<?= $classe ?>" value="<?= $classe ?>"
                         <?= (in_array($classe, $classes_selectionnees)) ? 'checked' : '' ?>>
                  <label for="classe_<?= $classe ?>"><?= $classe ?></label>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Section pour les matières -->
    <div class="form-group">
      <label for="matieres">Matière associée:</label>
      <select name="matieres" id="matieres">
        <option value="">Aucune matière</option>
        <?php if (!empty($etablissement_data['matieres'])): ?>
          <?php foreach ($etablissement_data['matieres'] as $matiere): ?>
            <option value="<?= $matiere['nom'] ?>" <?= ($evenement['matieres'] === $matiere['nom']) ? 'selected' : '' ?>>
              <?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
      <button type="submit" style="flex: 1;">Mettre à jour l'événement</button>
      <a href="details_evenement.php?id=<?= $id ?>" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
</div>

<script>
// Synchroniser les dates de début et de fin
document.getElementById('date_debut').addEventListener('change', function() {
  const dateFinInput = document.getElementById('date_fin');
  // Si la date de fin est vide ou si elle est avant la date de début
  if (!dateFinInput.value || dateFinInput.value < this.value) {
    dateFinInput.value = this.value;
  }
});

// Montrer/cacher la section des classes selon la visibilité sélectionnée
document.getElementById('visibilite').addEventListener('change', function() {
  const sectionClasses = document.getElementById('section_classes');
  if (this.value === 'classes_specifiques') {
    sectionClasses.style.display = 'block';
  } else {
    sectionClasses.style.display = 'none';
  }
});
</script>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>