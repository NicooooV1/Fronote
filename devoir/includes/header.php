<?php
// includes/header.php - En-tête commun à toutes les pages
if (!defined('INCLUDED')) {
    exit('Accès direct au fichier non autorisé');
}

// Récupération des notifications
$notification = isset($_SESSION['notification']) ? $_SESSION['notification'] : null;
unset($_SESSION['notification']); // Effacer après lecture
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - <?= htmlspecialchars($pageTitle ?? 'Espace élèves') ?></title>
    <link rel="stylesheet" href="/assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="pronote-container">
        <!-- En-tête -->
        <header class="pronote-header">
            <div class="pronote-logo">
                <img src="/assets/img/pronote-logo.png" alt="Pronote Logo">
                <span>PRONOTE</span>
            </div>
            <div class="pronote-user-info">
                <span class="pronote-user-name"><?= htmlspecialchars(getUserName()) ?></span>
                <a href="/login/logout.php" class="pronote-logout">Déconnexion</a>
            </div>
        </header>