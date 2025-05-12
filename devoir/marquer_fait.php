<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (!isEleve()) {
    die("Accès refusé.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $devoir_id = $_POST['devoir_id'];
    $eleve_id = $_SESSION['user']['id'];

    $stmt = $pdo->prepare("INSERT IGNORE INTO devoirs_faits (devoir_id, eleve_id) VALUES (?, ?)");
    $stmt->execute([$devoir_id, $eleve_id]);
}

header('Location: devoir.php');
exit();
?>