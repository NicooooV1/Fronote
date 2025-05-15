<?php
// api/etablissement/index.php - Centralized API for classes and subjects
header('Content-Type: application/json');
require_once '../../config.php';
require_once __DIR__ . '/../../../../API/data.php';

// Authentication check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get establishment data from the API
$etablissementData = getEtablissementData();

if (!$etablissementData) {
    http_response_code(500);
    echo json_encode(['error' => 'Error reading etablissement.json file']);
    exit;
}

// Determine which resource is requested
$type = $_GET['type'] ?? '';

if ($type === 'classes') {
    // Return only classes
    echo json_encode(getAvailableClasses());
} elseif ($type === 'matieres') {
    // Return only subjects
    echo json_encode(getAvailableMatieres());
} else {
    // Return all data by default
    echo json_encode($etablissementData);
}