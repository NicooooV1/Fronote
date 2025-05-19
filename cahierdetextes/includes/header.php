<?php
/**
 * En-tête commun pour le module Cahier de Textes
 * Utilise le système de design unifié de Pronote
 */

// Vérification si les informations utilisateur sont disponibles
if (!isset($user_initials) && isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    $user_initials = strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));
}

// Définition des paramètres du module
$pageTitle = $pageTitle ?? 'Cahier de Textes';
$moduleClass = 'cahier';
$moduleColor = 'var(--accent-cahier)';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> - Pronote</title>
  <link rel="stylesheet" href="../assets/css/pronote-theme.css">
  <link rel="stylesheet" href="../cahierdetextes/assets/css/cahierdetextes.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    :root {
      --module-color: <?= $moduleColor ?>;
    }
    
    .devoir-description {
      margin-top: 15px;
      padding: 10px;
      background-color: #f9f9f9;
      border-radius: 4px;
    }
    
    .devoir-description h4 {
      margin-top: 0;
      margin-bottom: 10px;
      color: var(--module-color);
    }
    
    .devoir-description p {
      margin: 0;
      line-height: 1.5;
    }
  </style>
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="sidebar-logo">
        <div class="logo-icon">P</div>
        <div class="logo-text">Pronote</div>
      </div>
      
      <!-- Navigation principale -->
      <div class="sidebar-section">
        <div class="sidebar-title">Navigation</div>
        <div class="sidebar-menu">
          <a href="cahierdetextes.php" class="sidebar-link active">
            <i class="fas fa-list"></i> Liste des devoirs
          </a>
          
          <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
          <a href="ajouter_devoir.php" class="sidebar-link">
            <i class="fas fa-plus"></i> Ajouter un devoir
          </a>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Autres modules -->
      <div class="sidebar-section">
        <div class="sidebar-title">Autres modules</div>
        <div class="sidebar-menu">
          <a href="../notes/notes.php" class="sidebar-link">
            <i class="fas fa-chart-bar"></i> Notes
          </a>
          <a href="../messagerie/index.php" class="sidebar-link">
            <i class="fas fa-envelope"></i> Messagerie
          </a>
          <a href="../absences/absences.php" class="sidebar-link">
            <i class="fas fa-calendar-times"></i> Absences
          </a>
          <a href="../agenda/agenda.php" class="sidebar-link">
            <i class="fas fa-calendar-alt"></i> Agenda
          </a>
          <a href="../accueil/accueil.php" class="sidebar-link">
            <i class="fas fa-home"></i> Accueil
          </a>
        </div>
      </div>
      
      <!-- Footer de la sidebar -->
      <div class="sidebar-footer">
        <div class="text-small">
          © <?= date('Y') ?> Pronote
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="header">
        <div class="header-title">
          <h1><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="header-icon-button logout-button" title="Déconnexion">
            <i class="fas fa-sign-out-alt"></i>
          </a>
          <div class="user-avatar" title="<?= isset($user) ? htmlspecialchars($user['prenom'] . ' ' . $user['nom']) : 'Utilisateur' ?>">
            <?= htmlspecialchars($user_initials ?? 'U') ?>
          </div>
        </div>
      </div>
      
      <div class="content-container">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
          <div class="alert-banner alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button class="alert-close">&times;</button>
          </div>
          <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
          <div class="alert-banner alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button class="alert-close">&times;</button>
          </div>
          <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>