<?php
/**
 * M32 – Salles & Matériel — Export CSV/PDF
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
if (!isAdmin() && !isVieScolaire() && !isTeacher()) { die('Accès refusé'); }

require_once __DIR__ . '/includes/SallesMaterielService.php';
$service = new SallesMaterielService(getPDO());
$exportService = new \API\Services\ExportService(getPDO());

$type = $_GET['type'] ?? 'reservations';
$format = $_GET['format'] ?? 'csv';

if ($type === 'materiels') {
    $data = $service->getMaterielsForExport($_GET);
    $headers = ['Nom', 'Référence', 'Catégorie', 'État', 'Quantité', 'Localisation'];
    $title = 'Inventaire matériel';
    $filename = 'inventaire_materiel';
} else {
    $data = $service->getReservationsForExport($_GET);
    $headers = ['Salle', 'Date', 'Début', 'Fin', 'Motif', 'Demandeur', 'Statut'];
    $title = 'Réservations de salles';
    $filename = 'reservations_salles';
}

// Le service renvoie des lignes indexées numériquement : on les recale sur les labels.
$data = array_map(fn($row) => array_combine($headers, $row), $data);

if ($format === 'pdf') {
    $exportService->pdf($exportService->buildTable($data, $headers, $title), $title, $filename);
} else {
    $exportService->csv($data, $headers, $filename);
}
