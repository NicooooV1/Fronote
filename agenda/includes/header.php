<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agenda - Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
<<<<<<< HEAD
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Agenda</div>
      </a>
      
      <!-- Mini-calendrier pour la navigation -->
      <div class="sidebar-section">
        <h3 class="sidebar-section-header">Calendrier</h3>
=======
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Agenda</div>
      </div>
      
      <!-- Mini-calendrier pour la navigation -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Calendrier</div>
>>>>>>> design
        <!-- Insérer ici le mini-calendrier -->
      </div>
      
      <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
      <!-- Actions -->
      <div class="sidebar-section">
<<<<<<< HEAD
        <h3 class="sidebar-section-header">Actions</h3>
        <a href="ajouter_evenement.php" class="action-button">
=======
        <div class="sidebar-section-header">Actions</div>
        <a href="ajouter_evenement.php" class="create-button">
>>>>>>> design
          <i class="fas fa-plus"></i> Ajouter un événement
        </a>
      </div>
      <?php endif; ?>
      
      <!-- Autres modules -->
      <div class="sidebar-section">
<<<<<<< HEAD
        <h3 class="sidebar-section-header">Autres modules</h3>
        <div class="sidebar-nav">
          <a href="../notes/notes.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
            <span>Notes</span>
          </a>
          <a href="../messagerie/index.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
            <span>Messagerie</span>
          </a>
          <a href="../absences/absences.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
            <span>Absences</span>
          </a>
          <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-book"></i></span>
            <span>Cahier de textes</span>
          </a>
          <a href="../accueil/accueil.php" class="sidebar-nav-item">
            <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
            <span>Accueil</span>
=======
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
          <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
            <i class="fas fa-book"></i> Cahier de textes
          </a>
          <a href="../accueil/accueil.php" class="module-link">
            <i class="fas fa-home"></i> Accueil
>>>>>>> design
          </a>
        </div>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <h1>Agenda</h1>
        </div>
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?? '' ?></div>
        </div>
      </div>
      
      <div class="content-container">