<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Absences - Pronote</title>
  <link rel="stylesheet" href="../notes/assets/css/style.css">
  <link rel="stylesheet" href="assets/css/absences.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Absences</div>
      </div>

      <!-- Filtres par période -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Périodes</div>
        <div class="folder-menu">
          <a href="?periode=semaine" class="<?= ($periode_active == 'semaine' ? 'active' : '') ?>">
            <i class="fas fa-calendar-week"></i> Cette semaine
          </a>
          <a href="?periode=mois" class="<?= ($periode_active == 'mois' ? 'active' : '') ?>">
            <i class="fas fa-calendar-alt"></i> Ce mois
          </a>
          <a href="?periode=trimestre" class="<?= ($periode_active == 'trimestre' ? 'active' : '') ?>">
            <i class="fas fa-calendar"></i> Ce trimestre
          </a>
        </div>
      </div>

      <!-- Actions -->
      <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="ajouter_absence.php" class="create-button">
          <i class="fas fa-plus"></i> Signaler une absence
        </a>
        <a href="appel.php" class="button button-secondary">
          <i class="fas fa-clipboard-list"></i> Faire l'appel
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
          <a href="../agenda/agenda.php" class="module-link">
            <i class="fas fa-calendar"></i> Agenda
          </a>
          <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
            <i class="fas fa-book"></i> Cahier de textes
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
          <h1>Gestion des Absences</h1>
        </div>
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?? '' ?></div>
        </div>
      </div>
      
      <div class="content-container">
