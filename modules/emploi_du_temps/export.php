<?php
declare(strict_types=1);
/**
 * Emploi du temps — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/includes/EdtService.php';

requireAuth();

if (!isAdmin() && !isVieScolaire() && !isTeacher()) {
    deny_access(false, 'Accès refusé.');
}

$pdo = getPDO();
$service = new EdtService($pdo);
$exportService = new \API\Services\ExportService($pdo);

$classeId = (int)($_GET['classe'] ?? 0);

// Sans paramètre classe (accès via l'onglet « Export ») : proposer le choix
// de la classe plutôt qu'une erreur 400.
if (!$classeId) {
    $classes = $service->getClasses();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export de l'emploi du temps</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6fa; color: #2c3e50; margin: 0; padding: 24px; }
        .export-box { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e0e4ea; border-radius: 8px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p.hint { color: #6b7280; margin: 0 0 16px; }
        ul.classes { list-style: none; margin: 0; padding: 0; }
        ul.classes li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 4px; border-bottom: 1px solid #eef0f4; }
        ul.classes li:last-child { border-bottom: none; }
        .classe-nom { font-weight: 600; }
        a.btn { display: inline-block; padding: 4px 12px; margin-left: 6px; border: 1px solid #2c6ecb; border-radius: 4px; color: #2c6ecb; text-decoration: none; font-size: 0.85rem; }
        a.btn:hover { background: #2c6ecb; color: #fff; }
        a.back { display: inline-block; margin-top: 16px; color: #2c6ecb; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        .empty { color: #6b7280; font-style: italic; }
    </style>
</head>
<body>
    <div class="export-box">
        <h1>Export de l'emploi du temps</h1>
        <p class="hint">Choisissez une classe puis un format d'export.</p>
        <?php if (empty($classes)): ?>
            <p class="empty">Aucune classe active.</p>
        <?php else: ?>
            <ul class="classes">
                <?php foreach ($classes as $c): ?>
                    <li>
                        <span class="classe-nom"><?= htmlspecialchars($c['nom']) ?></span>
                        <span class="formats">
                            <a class="btn" href="export.php?classe=<?= (int)$c['id'] ?>&amp;format=csv">CSV</a>
                            <a class="btn" href="export.php?classe=<?= (int)$c['id'] ?>&amp;format=pdf">PDF</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="back" href="emploi_du_temps.php">&larr; Retour à l'emploi du temps</a>
    </div>
</body>
</html>
    <?php
    exit;
}

$format = $_GET['format'] ?? 'csv';
$data = $service->getEdtForExport($classeId);
$columns = ['Jour', 'Créneau', 'Matière', 'Professeur', 'Salle', 'Type'];

// Récupérer le nom de la classe pour le titre
$classes = $service->getClasses();
$classeNom = 'Classe';
foreach ($classes as $c) {
    if ($c['id'] == $classeId) { $classeNom = $c['nom']; break; }
}

if ($format === 'pdf') {
    $titre = "EDT - {$classeNom}";
    $exportService->pdf($exportService->buildTable($data, $columns, $titre), $titre, "edt_{$classeNom}");
} else {
    $exportService->csv($data, $columns, "edt_{$classeNom}");
}
