<?php
// api/etablissement/index.php - API centralisée pour les classes et matières
header('Content-Type: application/json');
require_once '../../config.php';

// Vérification de l'authentification
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupération du fichier etablissement.json
$etablissementData = getEtablissementData();

if (!$etablissementData) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de lecture du fichier etablissement.json']);
    exit;
}

// Déterminer quelle ressource est demandée
$type = $_GET['type'] ?? '';

if ($type === 'classes') {
    // Renvoyer uniquement les classes
    echo json_encode($etablissementData['classes'] ?? []);
} elseif ($type === 'matieres') {
    // Renvoyer uniquement les matières
    echo json_encode($etablissementData['matieres'] ?? []);
} else {
    // Renvoyer toutes les données par défaut
    echo json_encode($etablissementData);
}