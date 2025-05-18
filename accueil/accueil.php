<?php
// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Standard path
    dirname(__DIR__) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Another possible path
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    // Define ABSPATH for security check in path_helper.php
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__));
    require_once $path_helper;
    require_once API_CORE_PATH;
    require_once API_AUTH_PATH;
    require_once API_DATA_PATH;
} else {
    // Fallback for direct inclusion if path_helper is not found
    $base_dir = dirname(dirname(__DIR__));
    $api_dir = $base_dir . '/API';

    // Si nous sommes sur le serveur, le chemin pourrait être différent
    if (!file_exists($api_dir)) {
        $api_dir = dirname(__DIR__) . '/API';
    }

    // Include the API core
    require_once $api_dir . '/core.php';
    require_once $api_dir . '/auth.php';
    require_once $api_dir . '/data.php';
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Get user data
$user = getCurrentUser();
$eleve_nom = $user['prenom'] . ' ' . $user['nom'];
$classe = isset($user['classe']) ? $user['classe'] : '3e A';

// Simulated data
$edt = [
  ["heure" => "9h00", "matiere" => "Français", "prof" => "Gallet B.", "salle" => "105", "couleur" => "#3498db"],
  ["heure" => "10h00", "matiere" => "Histoire-Géo", "prof" => "Moreau C.", "salle" => "206", "couleur" => "#e67e22"],
  ["heure" => "11h00", "matiere" => "Maths", "prof" => "Professeur M.", "salle" => "207", "couleur" => "#9b59b6"],
  ["heure" => "13h30", "matiere" => "SVT", "prof" => "Tessier A.", "salle" => "Labo 2", "couleur" => "#2ecc71"],
  ["heure" => "14h30", "matiere" => "Anglais", "prof" => "Brown J.", "salle" => "103", "couleur" => "#1abc9c"],
  ["heure" => "15h30", "matiere" => "EPS", "prof" => "Roux N.", "salle" => "Piscine", "couleur" => "#f39c12"]
];

$devoirs = [
  ["date" => "lun. 12 mai", "matiere" => "Maths", "contenu" => "Exercices 4 à 6 p.45", "fait" => false],
  ["date" => "mar. 13 mai", "matiere" => "Français", "contenu" => "Rédaction sur Molière", "fait" => true],
  ["date" => "jeu. 15 mai", "matiere" => "SVT", "contenu" => "Apprendre le chapitre 8", "fait" => false],
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Accueil PRONOTE</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background: #f5f6fa;
    }

    nav {
      background: #34495e;
      color: white;
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .header {
      background: #2c3e50;
      color: white;
      padding: 30px;
      text-align: center;
    }

    .header h1 {
      margin: 0;
      font-size: 24px;
    }

    .header p {
      margin: 5px 0 0;
      font-size: 16px;
    }

    .container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
      padding: 30px;
      max-width: 1100px;
      margin: auto;
    }

    .section {
      background: white;
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .edt-entry {
      display: flex;
      margin-bottom: 15px;
    }

    .edt-color {
      width: 5px;
      border-radius: 4px 0 0 4px;
      margin-right: 10px;
    }

    .edt-content {
      background: #fefefe;
      border-radius: 8px;
      padding: 10px 15px;
      flex: 1;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .edt-content p {
      margin: 4px 0;
    }

    .devoir {
      background: #f3f4f6;
      padding: 12px 16px;
      border-left: 4px solid #6c5ce7;
      margin-bottom: 15px;
      border-radius: 6px;
      position: relative;
    }

    .devoir.done {
      border-color: #2ecc71;
    }

    .devoir .date {
      font-size: 13px;
      color: #888;
      margin-bottom: 5px;
    }

    .devoir .matiere {
      font-weight: bold;
      margin-bottom: 4px;
    }

    .devoir .status {
      position: absolute;
      right: 10px;
      top: 10px;
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 12px;
      background: #bdc3c7;
      color: white;
    }

    .devoir.done .status {
      background: #2ecc71;
    }

    h2 {
      margin-top: 0;
      color: #2c3e50;
    }

    a {
      color: white;
      text-decoration: none;
    }

  </style>
</head>
<body>

<nav>
  <div>Bienvenue, <?= $eleve_nom ?></div>
  <div><a href="#">Déconnexion</a></div>
</nav>

<div class="header">
  <h1>PRONOTE - Espace Élève</h1>
  <p>Classe de <?= $classe ?> – <?= $eleve_nom ?></p>
</div>

<div class="container">

  <!-- Emploi du temps -->
  <div class="section">
    <h2>Emploi du temps du jour</h2>
    <?php foreach ($edt as $cours): ?>
      <div class="edt-entry">
        <div class="edt-color" style="background:<?= $cours['couleur'] ?>;"></div>
        <div class="edt-content">
          <p><strong><?= $cours['heure'] ?></strong></p>
          <p><?= htmlspecialchars($cours['matiere']) ?> — <?= htmlspecialchars($cours['prof']) ?></p>
          <p><small>Salle <?= htmlspecialchars($cours['salle']) ?></small></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Devoirs -->
  <div class="section">
    <h2>Travail à faire</h2>
    <?php foreach ($devoirs as $d): ?>
      <div class="devoir <?= $d['fait'] ? 'done' : '' ?>">
        <div class="date"><?= $d['date'] ?></div>
        <div class="matiere"><?= htmlspecialchars($d['matiere']) ?></div>
        <div class="contenu"><?= htmlspecialchars($d['contenu']) ?></div>
        <div class="status"><?= $d['fait'] ? 'Fait' : 'Non fait' ?></div>
      </div>
    <?php endforeach; ?>
  </div>

</div>
<script src="/~u22405372/SAE/Pronote/login/public/assets/js/session_checker.js"></script>
</body>
</html>
