<?php
declare(strict_types=1);
/**
 * M32 – Transports — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) { deny_access(false, 'Accès refusé.'); }

require_once __DIR__ . '/includes/TransportInternatService.php';
$service = new TransportInternatService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'lignes';
$format = $_GET['format'] ?? 'csv';

if ($type === 'inscrits' && !empty($_GET['ligne_id'])) {
    $data = $service->getInscritsForExport((int)$_GET['ligne_id']);
    $headers = ['Ligne', 'Élève', 'Classe', 'Arrêt'];
    $title = 'Inscrits transport';
    $filename = 'inscrits_transport';
} else {
    $data = $service->getLignesForExport($_GET['type_transport'] ?? null);
    $headers = ['Nom', 'Type', 'Itinéraire', 'Horaire départ', 'Horaire arrivée', 'Capacité', 'Inscrits'];
    $title = 'Lignes de transport';
    $filename = 'lignes_transport';
}

// Les lignes du service sont indexées numériquement : on les recale sur les libellés d'en-tête.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
