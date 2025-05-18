<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'includes/db.php';
include 'includes/auth.php';

if (!canManageNotes()) {
  header('Location: notes.php');
  exit;
}

$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: notes.php');
  exit;
}

$id = $_GET['id'];

if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ? AND nom_professeur = ?');
  $stmt->execute([$id, $user_fullname]);
  
  if ($stmt->rowCount() === 0) {
    header('Location: notes.php');
    exit;
  }
} else {
  $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
  $stmt->execute([$id]);
  
  if ($stmt->rowCount() === 0) {
    header('Location: notes.php');
    exit;
  }
}

// Si on arrive ici, on peut supprimer la note
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
  $stmt->execute([$id]);
  
  header('Location: notes.php');
  exit;
}

// Récupérer les informations sur la note à supprimer
$stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supprimer une note</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Notes</div>
      </a>
      
      <div class="sidebar-section">
        <a href="notes.php" class="action-button secondary">
          <i class="fas fa-arrow-left"></i> Retour aux notes
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <div class="top-header">
        <div class="page-title">
          <h1>Supprimer une note</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <div class="content-container">
        <div class="notes-container">
          <div class="alert alert-error">
            <h2>Confirmation de suppression</h2>
            <p>Êtes-vous sûr de vouloir supprimer cette note ?</p>
            
            <div class="note-details" style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-radius: 4px;">
              <p><strong>Élève :</strong> <?= htmlspecialchars($note['nom_eleve']) ?></p>
              <p><strong>Matière :</strong> <?= htmlspecialchars($note['matiere']) ?></p>
              <p><strong>Note :</strong> <?= htmlspecialchars($note['note']) ?>/<?= htmlspecialchars($note['note_sur']) ?></p>
              <p><strong>Date :</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_ajout']))) ?></p>
              <?php if (isset($note['description']) && !empty($note['description'])): ?>
              <p><strong>Description :</strong> <?= htmlspecialchars($note['description']) ?></p>
              <?php endif; ?>
            </div>
            
            <form action="supprimer_note.php?id=<?= $id ?>" method="post">
              <div class="form-actions">
                <a href="notes.php" class="btn btn-secondary">
                  <i class="fas fa-times"></i> Annuler
                </a>
                <button type="submit" class="btn btn-danger">
                  <i class="fas fa-trash-alt"></i> Confirmer la suppression
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

<?php
ob_end_flush();
?>