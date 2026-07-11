<?php
declare(strict_types=1);
/**
 * M11 – Annonces : Export CSV/PDF
 */
require_once __DIR__ . '/includes/AnnonceService.php';
require_once __DIR__ . '/../../API/core.php';
requireAuth();

if (!isAdmin() && !isVieScolaire()) {
    deny_access(false, 'Accès refusé.');
}

$pdo = getPDO();
$service = new AnnonceService($pdo);
$exportService = new \API\Services\ExportService($pdo);

$format = $_GET['format'] ?? 'csv';
$filters = [];
if (!empty($_GET['type'])) $filters['type'] = $_GET['type'];

$data = $service->getAnnoncesForExport($filters);
$columns = ['ID', 'Titre', 'Type', 'Publie', 'Epingle', 'Date publication', 'Date expiration', 'Nb lectures', 'Rôles ciblés'];

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $columns, 'Annonces'), 'Annonces', 'annonces.pdf');
} else {
    $exportService->csv($data, $columns, 'annonces');
}
