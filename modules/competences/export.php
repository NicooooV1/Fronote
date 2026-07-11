<?php
declare(strict_types=1);
/**
 * M38 – Compétences & Évaluations — Export CSV/PDF
 *
 * Sans classe_id (accès via l'onglet « Export »), affiche un sélecteur
 * de classe avec les liens d'export ; avec classe_id, génère le fichier.
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire() && !isProfesseur()) { deny_access(false, 'Accès refusé.'); }

require_once __DIR__ . '/includes/CompetenceService.php';
$service = new CompetenceService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'evaluations';
$format = $_GET['format'] ?? 'csv';
$classeId = (int)($_GET['classe_id'] ?? 0);
$periodeId = !empty($_GET['periode_id']) ? (int)$_GET['periode_id'] : null;

if (!$classeId) {
    // Aucune classe fournie : page de sélection plutôt qu'une erreur.
    $pageTitle = 'Export compétences';
    require_once __DIR__ . '/includes/header.php';
    $classes = $service->getClasses();
    $periodes = $service->getPeriodes();
    $periodeParam = $periodeId ? '&periode_id=' . $periodeId : '';
    ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-file-export"></i> Export compétences</h1>
        </div>

        <div class="comp-selectors">
            <form method="get" class="comp-selector-form">
                <div class="form-group">
                    <label><?= __('label.periode') ?></label>
                    <select name="periode_id" data-fr-change="submitOwn" class="form-select">
                        <option value="0"><?= __('label.toutes') ?></option>
                        <?php foreach ($periodes as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $periodeId ? 'selected' : '' ?>><?= htmlspecialchars($p['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if (empty($classes)): ?>
            <div class="empty-state"><p>Aucune classe disponible.</p></div>
        <?php else: ?>
            <div class="stats-comp-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= __('label.classe') ?></th>
                            <th class="text-center">Évaluations</th>
                            <th class="text-center">Bilan par domaine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): $cid = (int)$c['id']; ?>
                            <tr>
                                <td><?= htmlspecialchars($c['niveau'] . ' – ' . $c['nom']) ?></td>
                                <td class="text-center">
                                    <a href="export.php?type=evaluations&classe_id=<?= $cid ?>&format=csv<?= $periodeParam ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-csv"></i> CSV</a>
                                    <a href="export.php?type=evaluations&classe_id=<?= $cid ?>&format=pdf<?= $periodeParam ?>" class="btn btn-sm btn-outline" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
                                </td>
                                <td class="text-center">
                                    <a href="export.php?type=bilan&classe_id=<?= $cid ?>&format=csv<?= $periodeParam ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-csv"></i> CSV</a>
                                    <a href="export.php?type=bilan&classe_id=<?= $cid ?>&format=pdf<?= $periodeParam ?>" class="btn btn-sm btn-outline" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if ($type === 'bilan') {
    $data = $service->getBilanForExport($classeId, $periodeId);
    $headers = ['Nom', 'Prénom', 'Domaine', 'Niveau moyen', 'Nb évaluations'];
    $title = 'Bilan compétences par domaine';
    $filename = 'bilan_competences';
} else {
    $data = $service->getEvaluationsForExport($classeId, $periodeId);
    $headers = ['Nom', 'Prénom', 'Domaine', 'Code', 'Compétence', 'Niveau', 'Matière', 'Professeur', 'Date'];
    $title = 'Évaluations de compétences';
    $filename = 'evaluations_competences';
}

// Le service renvoie des lignes indexées numériquement : on les recale
// sur les libellés d'en-tête attendus par ExportService.
$data = array_map(fn(array $row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
