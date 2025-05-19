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

$id = intval($_GET['id']); // Sanitize with intval to ensure numeric value

// Vérifier que le devoir existe avant d'essayer de le supprimer
$check_stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
$check_stmt->execute([$id]);
$devoir = $check_stmt->fetch();

if (!$devoir) {
  // Le devoir n'existe pas
  header('Location: cahierdetextes.php?error=notfound');
  exit;
}

// Si l'utilisateur est un professeur (et pas un admin ou vie scolaire), 
// il peut seulement supprimer ses propres devoirs
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  if ($devoir['nom_professeur'] !== $user_fullname) {
    // Le devoir n'appartient pas au professeur connecté
    header('Location: cahierdetextes.php?error=unauthorized');
    exit;
  }
}

try {
  // Supprimer le devoir
  $stmt = $pdo->prepare('DELETE FROM devoirs WHERE id = ?');
  $stmt->execute([$id]);
  
  header('Location: cahierdetextes.php?success=deleted');
} catch (PDOException $e) {
  // Journal d'erreurs
  error_log("Erreur de suppression dans supprimer_devoir.php: " . $e->getMessage());
  header('Location: cahierdetextes.php?error=dbfailed');
}
exit;

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>