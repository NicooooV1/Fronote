<?php
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur est un professeur
if (!isTeacher() && !isAdmin()) {
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

// Vérifier que la note existe et appartient au professeur connecté
// ou que l'utilisateur est un administrateur
if (isTeacher() && !isAdmin()) {
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