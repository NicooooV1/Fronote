<?php
/**
 * M45 – Signalements / Anti-harcèlement — Export CSV/PDF
 * Note: Les données sensibles (description tronquée, pas de noms de victimes)
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) { die('Accès refusé'); }

require_once __DIR__ . '/includes/SignalementService.php';
$service = new SignalementService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$format = $_GET['format'] ?? 'csv';
$filters = array_filter([
    'statut' => $_GET['statut'] ?? null,
    'type' => $_GET['type_signalement'] ?? null,
    'urgence' => $_GET['urgence'] ?? null,
]);

$data = $service->getSignalementsForExport($filters);
$headers = ['ID', 'Date', 'Type', 'Urgence', 'Statut', 'Anonyme', 'Lieu', 'Description (extrait)', 'Date traitement'];
$title = 'Signalements';
$filename = 'signalements';

// Les lignes du service sont indexées numériquement : on les recale sur les libellés d'en-tête.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
