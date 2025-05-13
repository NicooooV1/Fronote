<?php
header('Content-Type: application/json');
session_start();

// Vérification de l'authentification
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupérer les classes du fichier JSON de l'établissement
$etablissementFile = __DIR__ . '/../login/data/etablissement.json';

// Vérifier si le fichier existe
if (!file_exists($etablissementFile)) {
    // Si le fichier n'existe pas, essayer avec un autre chemin
    $etablissementFile = __DIR__ . '/../../login/data/etablissement.json';
    
    if (!file_exists($etablissementFile)) {
        echo json_encode([]);
        exit;
    }
}

$data = json_decode(file_get_contents($etablissementFile), true);
echo json_encode($data['classes'] ?? []);