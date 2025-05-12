<?php
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/permissions.php';

class NotificationController {
    private $notificationModel;
    private $userModel;

    public function __construct() {
        $this->notificationModel = new Notification();
        $this->userModel = new User();
    }

    // Vérifie les droits d'accès
    private function checkPermission($action) {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
            header('Location: /login.php');
            exit;
        }
        
        $userType = $_SESSION['user_type'];
        if (!Permissions::hasPermission($userType, 'agenda', $action)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
        return true;
    }

    // Récupère les préférences de notification
    public function getNotificationPreferences() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        $userId = $_SESSION['user_id'];
        $preferences = $this->notificationModel->getUserPreferences($userId);
        
        echo json_encode($preferences);
    }

    // Enregistre une préférence de notification
    public function saveNotificationPreference() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $userId = $_SESSION['user_id'];
        $type = $_POST['type'] ?? null;
        $delaiMinute = $_POST['delai_minute'] ?? 15;
        $emploiId = $_POST['emploi_id'] ?? null;
        
        if (!$type) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing notification type']);
            return;
        }
        
        try {
            $result = $this->notificationModel->savePreference($userId, $type, $delaiMinute, $emploiId);
            
            if ($result) {
                // Vérifier si le navigateur supporte les notifications push
                $webPushEnabled = $this->checkWebPushEnabled($userId);
                
                echo json_encode([
                    'success' => true,
                    'web_push_enabled' => $webPushEnabled
                ]);
            } else {
                echo json_encode(['error' => 'Failed to save notification preference']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // Enregistrer un abonnement aux notifications push
    public function registerPushSubscription() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $userId = $_SESSION['user_id'];
        $subscription = json_decode(file_get_contents('php://input'), true);
        
        if (!$subscription || !isset($subscription['endpoint'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid subscription data']);
            return;
        }
        
        try {
            // Enregistrer l'abonnement dans la base de données
            $result = $this->userModel->savePushSubscription($userId, json_encode($subscription));
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to register push subscription']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // Vérifier si le navigateur supporte les notifications push
    private function checkWebPushEnabled($userId) {
        $subscription = $this->userModel->getPushSubscription($userId);
        return !empty($subscription);
    }

    // Tester l'envoi d'une notification push
    public function testPushNotification() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        $userId = $_SESSION['user_id'];
        $subscription = $this->userModel->getPushSubscription($userId);
        
        if (!$subscription) {
            echo json_encode(['error' => 'No push subscription found']);
            return;
        }
        
        try {
            // Ici, nous simulons l'envoi d'une notification push
            // Dans une implémentation réelle, il faudrait utiliser une bibliothèque
            // comme web-push-php/web-push
            
            echo json_encode(['success' => true, 'message' => 'Notification test sent']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}