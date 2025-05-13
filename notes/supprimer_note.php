<?php
session_start();
include 'includes/auth.php';

// Vérifier si l'utilisateur est un professeur
if (!isTeacher()) {
  header('Location: notes.php');
  exit;
}

include 'includes/db.php';

$id = $_GET['id'];

if (isset($id) && is_numeric($id)) {
  $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
  $stmt->execute([$id]);
}

header('Location: notes.php');
exit;
?>