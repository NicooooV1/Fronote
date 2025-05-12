<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

echo json_encode([
    'profil' => $_SESSION['user']['profil'],
    'id' => $_SESSION['user']['id'],
    'nom' => $_SESSION['user']['nom'],
    'prenom' => $_SESSION['user']['prenom']
]);