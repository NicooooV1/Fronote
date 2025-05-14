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
    <base href="/devoir/"> <!-- Ajout de la balise base pour résoudre les problèmes de chemin -->
    <style>
        /* Variables CSS pour l'harmonisation avec les autres modules */
        :root {
            --pronote-primary: #009b72;       /* Vert principal */
            --pronote-hover: #008a65;         /* Vert hover */
            --pronote-light-bg: #f8f9fa;      /* Fond clair */
            --pronote-highlight: #009b72;     /* Surbrillance harmonisée */
        }
    </style>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="assets/img/favicon.ico" type="image/x-icon">
</head>
<body>
    <div class="pronote-container">
        <!-- En-tête -->
        <header class="pronote-header">
            <div class="pronote-logo">
                <img src="assets/img/pronote-logo.png" alt="Pronote Logo">
                <span>PRONOTE</span>
            </div>
            <div class="pronote-user-info">
                <div class="pronote-user-name">
                    <i class="fas fa-user-circle"></i>
                    <?= htmlspecialchars(getUserName()) ?>
                </div>
                <a href="login/logout.php" class="pronote-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Déconnexion
                </a>
            </div>
        </header>