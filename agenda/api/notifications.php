<?php
session_start();
require_once __DIR__ . '/../controllers/NotificationController.php';

// Vérification CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token validation failed']);
        exit;
    }
}

// Traitement de la requête
$controller = new NotificationController();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $controller->getNotificationPreferences();
        break;
    case 'POST':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'save':
                    $controller->saveNotificationPreference();
                    break;
                case 'register_push':
                    $controller->registerPushSubscription();
                    break;
                case 'test_push':
                    $controller->testPushNotification();
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