<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cahier de Textes</title>
  <link rel="stylesheet" href="../notes/assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    header {
      background-color: #00843d; /* Même couleur que le système de notes */
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
      color: #00843d;
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
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Cahier de Textes</div>
      </div>
      
      <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
      <!-- Actions -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="ajouter_devoir.php" class="create-button">
          <i class="fas fa-plus"></i> Ajouter un devoir
        </a>
      </div>
      <?php endif; ?>
      
      <!-- Autres modules -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Autres modules</div>
        <div class="folder-menu">
          <a href="../notes/notes.php" class="module-link">
            <i class="fas fa-chart-bar"></i> Notes
          </a>
          <a href="../messagerie/index.php" class="module-link">
            <i class="fas fa-envelope"></i> Messagerie
          </a>
          <a href="../absences/absences.php" class="module-link">
            <i class="fas fa-calendar-times"></i> Absences
          </a>
          <a href="../agenda/agenda.php" class="module-link">
            <i class="fas fa-calendar"></i> Agenda
          </a>
          <a href="../accueil/accueil.php" class="module-link">
            <i class="fas fa-home"></i> Accueil
          </a>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <h1>Cahier de Textes</h1>
        </div>
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?? '' ?></div>
        </div>
      </div>
      
      <div class="content-container">