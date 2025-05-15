<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour supprimer des événements
if (!canManageEvents()) {
  header('Location: agenda.php');
  exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: agenda.php');
  exit;
}

$id = $_GET['id'];

// Récupérer l'événement pour vérifier les permissions
$stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
$stmt->execute([$id]);
$evenement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evenement) {
  header('Location: agenda.php');
  exit;
}

// Vérifier que l'utilisateur a le droit de supprimer cet événement
if (!canDeleteEvent($evenement)) {
  header('Location: agenda.php');
  exit;
}

// Supprimer l'événement
$stmt = $pdo->prepare('DELETE FROM evenements WHERE id = ?');
$stmt->execute([$id]);

// Rediriger vers la page de l'agenda
header('Location: agenda.php');
exit;

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>