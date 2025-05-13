<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Système de Notes</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <header>
    <h2>Système de Notes</h2>
    <nav>
      <a href="notes.php">Notes</a>
      <?php if (isset($_SESSION['user']) && $_SESSION['user']['profil'] === 'professeur'): ?>
        <a href="ajouter_note.php">Ajouter une note</a>
      <?php endif; ?>
      <a href="/~u22405372/SAE/Pronote/accueil/accueil.php">Accueil Pronote</a>
      <a href="/~u22405372/SAE/Pronote/login/public/logout.php">Déconnexion</a>
    </nav>
  </header>