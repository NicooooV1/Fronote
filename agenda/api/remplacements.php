<?php
session_start();
require_once __DIR__ . '/../controllers/RemplacementController.php';

// Vérification CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token validation failed']);
        exit;
    }
}

// Traitement de la requête
$controller = new RemplacementController();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $controller->getReplacements();
        break;
    case 'POST':
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $controller->createReplacement();
                    break;
                case 'delete':
                    $controller->deleteReplacement();
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing action']);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}