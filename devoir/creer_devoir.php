<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (!isProf()) {
    die("Accès refusé.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre = $_POST['titre'];
    $description = $_POST['description'];
    $date_rendu = $_POST['date_rendu'];
    $matiere = $_POST['matiere'];
    $classe = $_POST['classe'];
    $auteur_id = $_SESSION['user']['id'];

    $stmt = $pdo->prepare("INSERT INTO devoirs (titre, description, date_rendu, matiere, classe, auteur_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$titre, $description, $date_rendu, $matiere, $classe, $auteur_id]);
    header('Location: devoir.php');
    exit();
}

$matieres = getMatieres();
$classes = getClasses();

<link rel="stylesheet" href="assets/style.css">
<form method="POST">
    <input type="text" name="titre" placeholder="Titre" required><br>
    <textarea name="description" placeholder="Description"></textarea><br>
    <input type="date" name="date_rendu" required><br>
    <select name="matiere">
        <?php foreach ($matieres as $m): ?>
            <option value="<?= htmlspecialchars(is_array($m) ? $m['nom'] : $m) ?>">
                <?= htmlspecialchars(is_array($m) ? $m['nom'] : $m) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="classe">
        <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars(is_array($c) ? $c['nom'] : $c) ?>">
                <?= htmlspecialchars(is_array($c) ? $c['nom'] : $c) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Créer</button>
</form>
