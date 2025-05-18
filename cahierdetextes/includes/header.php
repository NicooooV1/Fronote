<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cahier de Textes</title>
  <link rel="stylesheet" href="../notes/assets/css/style.css">
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
  <header>
    <h2>Cahier de Textes</h2>
    <nav>
      <a href="cahierdetextes.php">Devoirs</a>
      <?php if (isset($_SESSION['user']) && (in_array($_SESSION['user']['profil'], ['professeur', 'administrateur', 'vie_scolaire']))): ?>
        <a href="ajouter_devoir.php">Ajouter un devoir</a>
      <?php endif; ?>
      <a href="../notes/notes.php">Système de Notes</a>
      <a href="../accueil/accueil.php">Accueil Pronote</a>
      <a href="../login/public/logout.php">Déconnexion</a>
    </nav>
  </header>