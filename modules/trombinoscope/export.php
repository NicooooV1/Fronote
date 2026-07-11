<?php
declare(strict_types=1);
/**
 * M15 – Trombinoscope — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire() && !isProfesseur()) { deny_access(false, 'Accès refusé.'); }

require_once __DIR__ . '/includes/TrombinoscopeService.php';
$service = new TrombinoscopeService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'eleves';
$format = $_GET['format'] ?? 'csv';

if ($type === 'professeurs') {
    $data = $service->getProfesseursForExport(!empty($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null);
    $headers = ['Nom', 'Prénom', 'Email', 'Spécialité', 'Matière'];
    $title = 'Annuaire des professeurs';
    $filename = 'professeurs';
} else {
    $data = $service->getElevesForExport(!empty($_GET['classe_id']) ? (int)$_GET['classe_id'] : null);
    $headers = ['Nom', 'Prénom', 'Classe', 'Niveau', 'Date naissance', 'Email', 'Genre'];
    $title = 'Annuaire des élèves';
    $filename = 'eleves';
}

// Le service renvoie des lignes indexées numériquement : on les recale sur les labels.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
