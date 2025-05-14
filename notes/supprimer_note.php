<?php
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour supprimer des notes
if (!canManageNotes()) {
  header('Location: notes.php');
  exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: notes.php');
  exit;
}

$id = $_GET['id'];

// Si l'utilisateur est un professeur (et pas un admin ou vie scolaire), 
// il peut seulement supprimer ses propres notes
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ? AND nom_professeur = ?');
  $stmt->execute([$id, $user_fullname]);
  
  if ($stmt->rowCount() === 0) {
    // La note n'existe pas ou n'appartient pas au professeur connecté
    header('Location: notes.php');
    exit;
  }
}

// Supprimer la note
$stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
$stmt->execute([$id]);

header('Location: notes.php');
exit;
?>