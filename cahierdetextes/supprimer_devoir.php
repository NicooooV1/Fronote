<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour supprimer des devoirs
if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
  exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
$user_fullname = getUserFullName();

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: cahierdetextes.php');
  exit;
}

$id = $_GET['id'];

// Si l'utilisateur est un professeur (et pas un admin ou vie scolaire), 
// il peut seulement supprimer ses propres devoirs
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ? AND nom_professeur = ?');
  $stmt->execute([$id, $user_fullname]);
  
  if ($stmt->rowCount() === 0) {
    // Le devoir n'existe pas ou n'appartient pas au professeur connecté
    header('Location: cahierdetextes.php');
    exit;
  }
}

// Supprimer le devoir
$stmt = $pdo->prepare('DELETE FROM devoirs WHERE id = ?');
$stmt->execute([$id]);

header('Location: cahierdetextes.php');
exit;

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>