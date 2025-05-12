<?php
session_start();
require_once __DIR__ . '/../controllers/AgendaController.php';

// Vérification CSRF si nécessaire
if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    if ($_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token validation failed']);
        exit;
    }
}

// Traitement de la requête
$controller = new AgendaController();
$controller->getEvents();