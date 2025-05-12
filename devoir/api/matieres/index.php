<?php
header('Content-Type: application/json');
session_start();

// Vérification de l'authentification
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupérer les matières du fichier JSON de l'établissement
$jsonFile = __DIR__ . '/~u22405372/SAE/Pronote/login/data/etablissement.json';

if (!file_exists($jsonFile)) {
    echo json_encode([]);
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);
echo json_encode($data['matieres'] ?? []);