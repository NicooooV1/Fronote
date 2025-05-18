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
</head>
<body>
  <?php include 'includes/header.php'; ?>
  
  <main class="container">
    <h1>Supprimer une note</h1>
    
    <div class="alert alert-warning">
      <p>Êtes-vous sûr de vouloir supprimer cette note ?</p>
      
      <div class="note-details">
        <p><strong>Élève :</strong> <?= htmlspecialchars($note['nom_eleve']) ?></p>
        <p><strong>Matière :</strong> <?= htmlspecialchars($note['matiere']) ?></p>
        <p><strong>Note :</strong> <?= htmlspecialchars($note['note']) ?>/<?= htmlspecialchars($note['note_sur']) ?></p>
        <p><strong>Date :</strong> <?= htmlspecialchars($note['date_evaluation']) ?></p>
      </div>
      
      <form action="supprimer_note.php?id=<?= $id ?>" method="post" style="margin-top: 20px;">
        <div class="form-actions">
          <a href="notes.php" class="btn btn-secondary">Annuler</a>
          <button type="submit" class="btn btn-danger">Supprimer</button>
        </div>
      </form>
    </div>
  </main>
</body>
</html>

<?php
ob_end_flush();
?>