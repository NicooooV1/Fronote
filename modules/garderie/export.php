<?php
declare(strict_types=1);
/**
 * M20 – Garderie — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) { deny_access(false, 'Accès refusé.'); }

require_once __DIR__ . '/includes/GarderieService.php';
$service = new GarderieService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'inscriptions';
$format = $_GET['format'] ?? 'csv';

if ($type === 'presences' && !empty($_GET['date'])) {
    $data = $service->getPresencesForExport($_GET['date'], !empty($_GET['creneau_id']) ? (int)$_GET['creneau_id'] : null);
    $headers = ['Élève', 'Classe', 'Créneau', 'Date', 'Présence', 'Heure arrivée', 'Remarques'];
    $title = 'Présences garderie — ' . $_GET['date'];
    $filename = 'presences_garderie_' . $_GET['date'];
} else {
    $data = $service->getInscriptionsForExport(!empty($_GET['creneau_id']) ? (int)$_GET['creneau_id'] : null);
    $headers = ['Élève', 'Classe', 'Créneau', 'Type', 'Jour', 'Date début', 'Statut'];
    $title = 'Inscriptions garderie';
    $filename = 'inscriptions_garderie';
}

// Le service renvoie des lignes indexées numériquement : on les réaligne sur les libellés.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
