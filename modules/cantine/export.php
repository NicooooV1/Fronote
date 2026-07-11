<?php
declare(strict_types=1);
/**
 * M31 – Cantine — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) { deny_access(false, 'Accès refusé.'); }

require_once __DIR__ . '/includes/CantineService.php';
$service = new CantineService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'reservations';
$format = $_GET['format'] ?? 'csv';
$dateDebut = $_GET['date_debut'] ?? date('Y-m-01');
$dateFin = $_GET['date_fin'] ?? date('Y-m-t');

if ($type === 'menus') {
    $data = $service->getMenusForExport($dateDebut, $dateFin);
    $headers = ['Date', 'Type', 'Entrée', 'Plat', 'Accompagnement', 'Dessert'];
    $title = 'Menus cantine';
    $filename = 'menus_cantine';
} else {
    $data = $service->getReservationsForExport($dateDebut, $dateFin);
    $headers = ['Date', 'Élève', 'Classe', 'Type repas', 'Régime', 'Statut'];
    $title = 'Réservations cantine';
    $filename = 'reservations_cantine';
}

// Le service renvoie des lignes indexées numériquement : on les recale sur les libellés.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
