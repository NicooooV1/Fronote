<?php
require_once __DIR__ . '/../models/Remplacement.php';
require_once __DIR__ . '/../models/EmploiDuTemps.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/permissions.php';

class RemplacementController {
    private $remplacementModel;
    private $edtModel;
    private $userModel;

    public function __construct() {
        $this->remplacementModel = new Remplacement();
        $this->edtModel = new EmploiDuTemps();
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

    // Liste des remplacements
    public function index() {
        $this->checkPermission('view');
        include __DIR__ . '/../views/agenda/remplacements.php';
    }

    // Récupération des remplacements
    public function getReplacements() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
        
        $replacements = $this->remplacementModel->fetchBetween($start, $end);
        echo json_encode($replacements);
    }

    // Créer un remplacement
    public function createReplacement() {
        $this->checkPermission('modify');
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Récupérer les données
        $sourceId = $_POST['source_id'] ?? null;
        $destId = $_POST['dest_id'] ?? null;
        $date = $_POST['date'] ?? null;
        $motif = $_POST['motif'] ?? '';
        
        if (!$sourceId || !$destId || !$date) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }
        
        try {
            $result = $this->remplacementModel->create($sourceId, $destId, $date, $motif);
            
            if ($result) {
                // Notification aux utilisateurs concernés
                $this->notifyReplacement($sourceId, $destId, $date);
                
                echo json_encode(['success' => true, 'id' => $this->remplacementModel->getLastInsertId()]);
            } else {
                echo json_encode(['error' => 'Failed to create replacement']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // Supprimer un remplacement
    public function deleteReplacement() {
        $this->checkPermission('modify');
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $id = $_POST['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID']);
            return;
        }
        
        try {
            $result = $this->remplacementModel->delete($id);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to delete replacement']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // Notification aux utilisateurs concernés
    private function notifyReplacement($sourceId, $destId, $date) {
        // Récupérer les détails des cours
        $sourceCourse = $this->edtModel->fetchById($sourceId);
        $destCourse = $this->edtModel->fetchById($destId);
        
        if (!$sourceCourse || !$destCourse) {
            return false;
        }
        
        // Récupérer les IDs des utilisateurs concernés
        $userIds = [];
        
        // Professeur du cours source
        $profId = $sourceCourse['prof_id'];
        $profUser = $this->userModel->getUserIdByProfId($profId);
        if ($profUser) {
            $userIds[] = $profUser;
        }
        
        // Professeur du cours de remplacement
        $replacementProfId = $destCourse['prof_id'];
        $replacementProfUser = $this->userModel->getUserIdByProfId($replacementProfId);
        if ($replacementProfUser) {
            $userIds[] = $replacementProfUser;
        }
        
        // Élèves concernés
        $studentIds = $this->userModel->getStudentIdsByClass($sourceCourse['classe_id']);
        $userIds = array_merge($userIds, $studentIds);
        
        // Créer les notifications
        $userIds = array_unique($userIds);
        foreach ($userIds as $userId) {
            // Créer une notification dans la base de données
            // Cette fonction sera implémentée dans un NotificationController
            // mais pour le moment, nous laissons cette fonction comme placeholder
        }
        
        return true;
    }
}