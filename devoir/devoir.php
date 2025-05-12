<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$matieres = getMatieres();
$classes = getClasses();
$filters = [
    'matiere' => $_GET['matiere'] ?? null,
    'classe' => $_GET['classe'] ?? null
];
$devoirs = getDevoirs($pdo, $filters);
?>

<link rel="stylesheet" href="assets/style.css">
<script src="assets/script.js" defer></script>

<form method="GET">
    <select name="matiere_id">
        <option value="">Toutes les matières</option>
        <?php foreach ($matieres as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($filters['matiere_id'] == $m['id']) ? 'selected' : '' ?>><?= $m['nom'] ?></option>
        <?php endforeach; ?>
    </select>
    <select name="classe_id">
        <option value="">Toutes les classes</option>
        <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($filters['classe_id'] == $c['id']) ? 'selected' : '' ?>><?= $c['nom'] ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filtrer</button>
</form>

<?php foreach ($devoirs as $d): ?>
    <div class="devoir">
        <h3><?= htmlspecialchars($d['titre']) ?> (<?= $d['matiere'] ?> - <?= $d['classe'] ?>)</h3>
        <p><?= nl2br(htmlspecialchars($d['description'])) ?></p>
        <p>À rendre le : <?= $d['date_rendu'] ?></p>
        <?php if (isEleve()): ?>
            <form method="POST" action="marquer_fait.php">
                <input type="hidden" name="devoir_id" value="<?= $d['id'] ?>">
                <button type="submit">Marquer comme fait</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

