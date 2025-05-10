<?php
/**
 * En-tête HTML commun
 */

// Inclure le fichier avec la fonction countUnreadNotifications
require_once __DIR__ . '/../models/notification.php';

// URL de base
$baseUrl = '/~u22405372/SAE/Pronote/messagerie/';

// Titre par défaut
$pageTitle = $pageTitle ?? 'Pronote - Messagerie';

// Obtenir la page courante pour activer le menu correspondant
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Récupérer le dossier courant pour les menus
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : 'reception';

// Compter les notifications non lues
$unreadNotifications = isset($user) ? countUnreadNotifications($user['id'], $user['type']) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Feuilles de style -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/main.css">
    
    <?php if (in_array($currentPage, ['conversation'])): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/conversation.css">
    <?php endif; ?>
    
    <?php if (in_array($currentPage, ['new_message', 'new_announcement', 'class_message'])): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/forms.css">
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <header>
            <?php if ($currentPage != 'index'): ?>
            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Retour</a>
            <?php endif; ?>
            
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            
            <div class="user-info">
                <?php if (isset($user)): ?>
                <span><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></span>
                <span class="badge"><?= htmlspecialchars(ucfirst($user['type'])) ?></span>
                
                <?php if ($unreadNotifications > 0): ?>
                <span class="notification-badge"><?= $unreadNotifications ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>