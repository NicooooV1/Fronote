<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/functions.php';

// Vérifier que l'utilisateur est connecté et autorisé
if (!isLoggedIn() || !canManageAbsences()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Récupérer l'ID de l'absence à modifier
$id_absence = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Récupérer les détails de l'absence
$absence = getAbsenceById($pdo, $id_absence);

// Vérifier si l'absence existe
if (!$absence) {
    header('Location: absences.php');
    exit;
}

// Message de succès ou d'erreur
$message = '';
$erreur = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données du formulaire
    if (empty($_POST['date_debut']) || empty($_POST['heure_debut']) || 
        empty($_POST['date_fin']) || empty($_POST['heure_fin']) || empty($_POST['type_absence'])) {
        $erreur = "Veuillez remplir tous les champs obligatoires.";
    } else {
        // Formater les dates et heures
        $date_debut = $_POST['date_debut'] . ' ' . $_POST['heure_debut'] . ':00';
        $date_fin = $_POST['date_fin'] . ' ' . $_POST['heure_fin'] . ':00';
        
        // S'assurer que la date de fin est après la date de début
        if (strtotime($date_fin) <= strtotime($date_debut)) {
            $erreur = "La date/heure de fin doit être après la date/heure de début.";
        } else {
            // Préparer les données pour la mise à jour
            $data = [
                'date_debut' => $date_debut,
                'date_fin' => $date_fin,
                'type_absence' => $_POST['type_absence'],
                'motif' => $_POST['motif'] ?? '',
                'justifie' => isset($_POST['justifie']) ? true : false,
                'commentaire' => $_POST['commentaire'] ?? ''
            ];
            
            // Mettre à jour l'absence dans la base de données
            $success = modifierAbsence($pdo, $id_absence, $data);
            
            if ($success) {
                $message = "L'absence a été modifiée avec succès.";
                // Mettre à jour les données affichées
                $absence = getAbsenceById($pdo, $id_absence);
                // Redirection après un court délai
                header('refresh:2;url=details_absence.php?id=' . $id_absence);
            } else {
                $erreur = "Une erreur est survenue lors de la modification de l'absence.";
            }
        }
    }
}

// Extraire les composantes de date et heure
$date_debut = new DateTime($absence['date_debut']);
$date_fin = new DateTime($absence['date_fin']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifier une absence - Pronote</title>
  <link rel="stylesheet" href="../agenda/assets/css/calendar.css">
  <link rel="stylesheet" href="assets/css/absences.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Absences</div>
      </a>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <a href="details_absence.php?id=<?= $id_absence ?>" class="back-button">
            <i class="fas fa-arrow-left"></i>
          </a>
          <h1>Modifier une absence</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <?php if (!empty($message)): ?>
          <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= $message ?>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($erreur)): ?>
          <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= $erreur ?>
          </div>
        <?php endif; ?>
        
        <div class="form-container">
          <form method="post" action="modifier_absence.php?id=<?= $id_absence ?>">
            <div class="form-grid">
              <div class="form-group form-full">
                <h3>Élève: <?= htmlspecialchars($absence['prenom'] . ' ' . $absence['nom'] . ' (' . $absence['classe'] . ')') ?></h3>
              </div>
              
              <div class="form-group">
                <label for="date_debut">Date de début <span class="required">*</span></label>
                <input type="date" name="date_debut" id="date_debut" value="<?= $date_debut->format('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
              </div>
              
              <div class="form-group">
                <label for="heure_debut">Heure de début <span class="required">*</span></label>
                <input type="time" name="heure_debut" id="heure_debut" value="<?= $date_debut->format('H:i') ?>" required>
              </div>
              
              <div class="form-group">
                <label for="date_fin">Date de fin <span class="required">*</span></label>
                <input type="date" name="date_fin" id="date_fin" value="<?= $date_fin->format('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
              </div>
              
              <div class="form-group">
                <label for="heure_fin">Heure de fin <span class="required">*</span></label>
                <input type="time" name="heure_fin" id="heure_fin" value="<?= $date_fin->format('H:i') ?>" required>
              </div>
              
              <div class="form-group">
                <label for="type_absence">Type d'absence <span class="required">*</span></label>
                <select name="type_absence" id="type_absence" required>
                  <option value="cours" <?= $absence['type_absence'] === 'cours' ? 'selected' : '' ?>>Cours</option>
                  <option value="demi-journee" <?= $absence['type_absence'] === 'demi-journee' ? 'selected' : '' ?>>Demi-journée</option>
                  <option value="journee" <?= $absence['type_absence'] === 'journee' ? 'selected' : '' ?>>Journée complète</option>
                </select>
              </div>
              
              <div class="form-group">
                <label for="motif">Motif</label>
                <select name="motif" id="motif">
                  <option value="">Sélectionner un motif</option>
                  <option value="maladie" <?= $absence['motif'] === 'maladie' ? 'selected' : '' ?>>Maladie</option>
                  <option value="rdv_medical" <?= $absence['motif'] === 'rdv_medical' ? 'selected' : '' ?>>Rendez-vous médical</option>
                  <option value="familial" <?= $absence['motif'] === 'familial' ? 'selected' : '' ?>>Raison familiale</option>
                  <option value="transport" <?= $absence['motif'] === 'transport' ? 'selected' : '' ?>>Problème de transport</option>
                  <option value="autre" <?= $absence['motif'] === 'autre' ? 'selected' : '' ?>>Autre</option>
                </select>
              </div>
              
              <div class="form-group form-full">
                <div class="checkbox-group">
                  <input type="checkbox" name="justifie" id="justifie" <?= $absence['justifie'] ? 'checked' : '' ?>>
                  <label for="justifie">Absence justifiée</label>
                </div>
              </div>
              
              <div class="form-group form-full">
                <label for="commentaire">Commentaire</label>
                <textarea name="commentaire" id="commentaire" rows="4"><?= htmlspecialchars($absence['commentaire']) ?></textarea>
              </div>
              
              <div class="form-actions form-full">
                <a href="details_absence.php?id=<?= $id_absence ?>" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  
  <script>
    // Script pour valider que la date de fin est après la date de début
    document.querySelector('form').addEventListener('submit', function(e) {
      const dateDebut = document.getElementById('date_debut').value;
      const dateFin = document.getElementById('date_fin').value;
      const heureDebut = document.getElementById('heure_debut').value;
      const heureFin = document.getElementById('heure_fin').value;
      
      const debutComplet = new Date(dateDebut + 'T' + heureDebut);
      const finComplet = new Date(dateFin + 'T' + heureFin);
      
      if (finComplet <= debutComplet) {
        alert("La date et l'heure de fin doivent être après la date et l'heure de début.");
        e.preventDefault();
      }
    });
  </script>
</body>
</html>
<?php ob_end_flush(); ?>