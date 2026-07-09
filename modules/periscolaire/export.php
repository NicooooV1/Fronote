<?php
declare(strict_types=1);
/**
 * M16 – Périscolaire — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) { die('Accès refusé'); }

require_once __DIR__ . '/includes/PeriscolaireService.php';
$service = new PeriscolaireService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'inscriptions';
$format = $_GET['format'] ?? 'csv';

if ($type === 'services') {
    $data = $service->getServicesForExport($_GET['service_type'] ?? null);
    $headers = ['Nom', 'Type', 'Description', 'Horaires', 'Tarif', 'Places max', 'Inscrits'];
    $title = 'Services périscolaires';
    $filename = 'services_periscolaires';
} else {
    $serviceId = !empty($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $data = $service->getInscriptionsForExport($serviceId);
    $headers = ['Élève', 'Classe', 'Service', 'Type', 'Jour', 'Date début', 'Statut'];
    $title = 'Inscriptions périscolaires';
    $filename = 'inscriptions_periscolaires';
}

// Les lignes du service sont indexées numériquement : on les recale sur les libellés d'en-tête.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
