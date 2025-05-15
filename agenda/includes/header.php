<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agenda - Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <!-- Inclure les styles du système principal si besoin -->
  <link rel="stylesheet" href="../notes/assets/css/style.css">
</head>
<body>
  <header>
    <h2>Agenda Pronote</h2>
    <nav>
      <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
        <a href="ajouter_evenement.php">Ajouter un événement</a>
      <?php endif; ?>
      <a href="../notes/notes.php">Système de Notes</a>
      <a href="../accueil/accueil.php">Accueil Pronote</a>
      <a href="../login/public/logout.php">Déconnexion</a>
    </nav>
  </header>
  
  <div class="main-container">