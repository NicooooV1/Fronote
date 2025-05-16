<?php
ob_start();

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

$stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
$stmt->execute([$id]);

header('Location: notes.php');
exit;

ob_end_flush();
?>