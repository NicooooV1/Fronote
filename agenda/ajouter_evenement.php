<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour ajouter des événements
if (!canManageEvents()) {
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

// Date par défaut (aujourd'hui)
$date_par_defaut = date('Y-m-d');
$heure_debut_defaut = date('H:i');
$heure_fin_defaut = date('H:i', strtotime('+1 hour'));

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
      
      // Insertion dans la base de données
      try {
        $stmt = $pdo->prepare('INSERT INTO evenements (titre, description, date_debut, date_fin, type_evenement, statut, createur, visibilite, lieu, classes, matieres) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        
        $stmt->execute([
          $_POST['titre'],
          $_POST['description'] ?? '',
          $date_debut,
          $date_fin,
          $_POST['type_evenement'],
          'actif', // Statut par défaut
          $nom_createur,
          $visibilite,
          $_POST['lieu'] ?? '',
          $classes_selectionnees,
          $_POST['matieres'] ?? ''
        ]);
        
        $message = "L'événement a été ajouté avec succès.";
        
        // Redirection après un court délai
        header('refresh:2;url=agenda.php');
      } catch (PDOException $e) {
        $erreur = "Erreur lors de l'ajout de l'événement : " . $e->getMessage();
      }
    }
  }
}
?>

<div class="container">
  <h3>Ajouter un événement</h3>
  
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
        <input type="text" name="titre" id="titre" required>
      </div>
      
      <div class="form-group">
        <label for="type_evenement">Type d'événement*:</label>
        <select name="type_evenement" id="type_evenement" required>
          <option value="">Sélectionnez un type</option>
          <?php foreach ($types_evenements as $key => $label): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="date_debut">Date de début*:</label>
        <input type="date" name="date_debut" id="date_debut" value="<?= $date_par_defaut ?>" required>
      </div>
      
      <div class="form-group">
        <label for="heure_debut">Heure de début*:</label>
        <input type="time" name="heure_debut" id="heure_debut" value="<?= $heure_debut_defaut ?>" required>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="date_fin">Date de fin*:</label>
        <input type="date" name="date_fin" id="date_fin" value="<?= $date_par_defaut ?>" required>
      </div>
      
      <div class="form-group">
        <label for="heure_fin">Heure de fin*:</label>
        <input type="time" name="heure_fin" id="heure_fin" value="<?= $heure_fin_defaut ?>" required>
      </div>
    </div>
    
    <div class="form-group">
      <label for="description">Description:</label>
      <textarea name="description" id="description" rows="4"></textarea>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="lieu">Lieu:</label>
        <input type="text" name="lieu" id="lieu">
      </div>
      
      <div class="form-group">
        <label for="visibilite">Visibilité*:</label>
        <select name="visibilite" id="visibilite" required>
          <?php foreach ($options_visibilite as $key => $label): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <!-- Section pour les classes (visible uniquement si "Classes spécifiques" est sélectionné) -->
    <div id="section_classes" style="display: none;">
      <label>Sélectionnez les classes concernées:</label>
      <div class="checkbox-group">
        <?php if (!empty($etablissement_data['classes'])): ?>
          <?php foreach ($etablissement_data['classes'] as $niveau => $niveaux): ?>
            <div class="checkbox-group-section">
              <h4><?= ucfirst($niveau) ?></h4>
              <?php foreach ($niveaux as $sousniveau => $classes): ?>
                <?php foreach ($classes as $classe): ?>
                  <div class="checkbox-item">
                    <input type="checkbox" name="classes[]" id="classe_<?= $classe ?>" value="<?= $classe ?>">
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
                  <input type="checkbox" name="classes[]" id="classe_<?= $classe ?>" value="<?= $classe ?>">
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
            <option value="<?= $matiere['nom'] ?>"><?= $matiere['nom'] ?> (<?= $matiere['code'] ?>)</option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
      <button type="submit" style="flex: 1;">Ajouter l'événement</button>
      <a href="agenda.php" class="button button-secondary" style="flex: 1; text-align: center;">Annuler</a>
    </div>
  </form>
</div>

<script>
// Synchroniser les dates de début et de fin par défaut
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

// Initialiser l'état de la section classes au chargement
window.addEventListener('load', function() {
  const visibiliteSelect = document.getElementById('visibilite');
  if (visibiliteSelect.value === 'classes_specifiques') {
    document.getElementById('section_classes').style.display = 'block';
  }
});
</script>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>