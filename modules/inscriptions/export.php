<?php
/**
 * M26 – Inscriptions : Export CSV/PDF
 */
require_once __DIR__ . '/includes/header.php';

if (!isAdmin() && !isPersonnelVS()) {
    http_response_code(403);
    exit('Accès refusé');
}

$exportService = new \API\Services\ExportService(getPDO());
$format = $_GET['format'] ?? 'csv';
$filters = [];
if (!empty($_GET['statut'])) $filters['statut'] = $_GET['statut'];

$data = $inscriptionService->getInscriptionsForExport($filters);
$columns = ['ID', 'Nom', 'Prénom', 'Date naissance', 'Sexe', 'Classe demandée', 'Statut', 'Date soumission', 'Email contact', 'Téléphone'];

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $columns, 'Inscriptions'), 'Inscriptions', 'inscriptions.pdf');
} else {
    $exportService->csv($data, $columns, 'inscriptions');
}
