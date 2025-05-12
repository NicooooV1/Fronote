<?php
require_once __DIR__ . '/../models/EmploiDuTemps.php';
require_once __DIR__ . '/../models/Evenement.php';
require_once __DIR__ . '/../models/Remplacement.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/permissions.php';

class AgendaController {
    private $edtModel;
    private $eventModel;
    private $remplacementModel;
    private $userModel;

    public function __construct() {
        $this->edtModel = new EmploiDuTemps();
        $this->eventModel = new Evenement();
        $this->remplacementModel = new Remplacement();
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

    // Affiche la vue principale
    public function index() {
        $this->checkPermission('view');
        $view = $_GET['view'] ?? 'hebdomadaire';
        $userType = $_SESSION['user_type'];
        $canModify = Permissions::hasPermission($userType, 'agenda', 'modify');
        
        // Déterminer la vue à afficher
        switch ($view) {
            case 'journalier':
                include __DIR__ . '/../views/agenda/journalier.php';
                break;
            case 'mensuel':
                include __DIR__ . '/../views/agenda/mensuel.php';
                break;
            default:
                include __DIR__ . '/../views/agenda/hebdomadaire.php';
                break;
        }
    }

    // Renvoie les cours et événements au format JSON
    public function getEvents() {
        $this->checkPermission('view');
        header('Content-Type: application/json');
        
        $userId = $_SESSION['user_id'];
        $userType = $_SESSION['user_type'];
        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;
        $view = $_GET['view'] ?? 'hebdomadaire';
        $showReplacements = isset($_GET['remplacements']) && $_GET['remplacements'] === '1';
        
        $events = [];

        if ($start && $end) {
            // Récupère les emplois du temps
            $cours = $this->edtModel->fetchBetween($userId, $start, $end, $userType);
            foreach ($cours as $c) {
                // Ajout des informations détaillées
                $events[] = [
                    'id' => $c['id'],
                    'title' => $c['matiere_label'],
                    'start' => $c['date'] . 'T' . $c['heure_debut'],
                    'end' => $c['date'] . 'T' . $c['heure_fin'],
                    'color' => $c['couleur'],
                    'sala' => $c['salle_label'],
                    'prof' => $c['prof_label'],
                    'type' => 'cours',
                    'hasReplacement' => $c['has_replacement'] ?? false
                ];
            }
            
            // Récupère les événements spéciaux
            $evts = $this->eventModel->fetchBetween($start, $end);
            foreach ($evts as $e) {
                $events[] = [
                    'id' => 'E' . $e['id'],
                    'title' => $e['titre'],
                    'start' => $e['date_debut'],
                    'end' => $e['date_fin'],
                    'color' => $e['couleur'],
                    'description' => $e['description'],
                    'type' => 'evenement'
                ];
            }
            
            // Récupère les remplacements si demandé
            if ($showReplacements) {
                $remplacements = $this->remplacementModel->fetchBetween($start, $end);
                foreach ($remplacements as $r) {
                    $events[] = [
                        'id' => 'R' . $r['id'],
                        'title' => 'Remplacement: ' . $r['matiere_label'],
                        'start' => $r['date'] . 'T' . $r['heure_debut'],
                        'end' => $r['date'] . 'T' . $r['heure_fin'],
                        'color' => '#FF9800', // Couleur spécifique pour remplacements
                        'original_id' => $r['emploi_id_src'],
                        'sala' => $r['salle_label'],
                        'prof' => $r['prof_label'],
                        'motif' => $r['motif'],
                        'type' => 'remplacement'
                    ];
                }
            }
        }
        
        echo json_encode($events);
    }

    // Modification d'un cours (pour les enseignants et administration)
    public function updateEvent() {
        $this->checkPermission('modify');
        
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        // Récupération des données POST
        $eventId = $_POST['id'] ?? null;
        $salle = $_POST['salle'] ?? null;
        $heureDebut = $_POST['heure_debut'] ?? null;
        $heureFin = $_POST['heure_fin'] ?? null;
        
        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing event ID']);
            return;
        }
        
        try {
            $result = $this->edtModel->update($eventId, [
                'salle_id' => $salle,
                'heure_debut' => $heureDebut,
                'heure_fin' => $heureFin
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Update failed']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // Génère et envoie un .ics
    public function exportIcs() {
        $this->checkPermission('export');
        
        $userId = $_SESSION['user_id'];
        $userType = $_SESSION['user_type'];
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));

        $cours = $this->edtModel->fetchBetween($userId, $start, $end, $userType);
        $events = $this->eventModel->fetchBetween($start, $end);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="agenda.ics"');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//MonPronoteWeb//AGENDA//FR\r\n";

        // Ajouter les cours
        foreach ($cours as $c) {
            $dtstart = str_replace(['-',':'], ['', ''], $c['date'] . 'T' . $c['heure_debut'] . '00');
            $dtend = str_replace(['-',':'], ['', ''], $c['date'] . 'T' . $c['heure_fin'] . '00');
            
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . uniqid() . "\r\n";
            echo "DTSTAMP:" . gmdate('Ymd').'T'. gmdate('His') . "Z\r\n";
            echo "DTSTART;TZID=Europe/Paris:$dtstart\r\n";
            echo "DTEND;TZID=Europe/Paris:$dtend\r\n";
            echo "SUMMARY:{$c['matiere_label']} – Salle {$c['salle_label']}\r\n";
            echo "DESCRIPTION:Professeur {$c['prof_label']}\r\n";
            echo "LOCATION:Salle {$c['salle_label']}\r\n";
            echo "END:VEVENT\r\n";
        }
        
        // Ajouter les événements
        foreach ($events as $e) {
            $dtstart = str_replace(['-',':'], ['', ''], $e['date_debut']);
            $dtend = str_replace(['-',':'], ['', ''], $e['date_fin']);
            
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . uniqid() . "\r\n";
            echo "DTSTAMP:" . gmdate('Ymd').'T'. gmdate('His') . "Z\r\n";
            echo "DTSTART;TZID=Europe/Paris:$dtstart\r\n";
            echo "DTEND;TZID=Europe/Paris:$dtend\r\n";
            echo "SUMMARY:{$e['titre']}\r\n";
            echo "DESCRIPTION:{$e['description']}\r\n";
            echo "END:VEVENT\r\n";
        }

        echo "END:VCALENDAR";
    }
    
    // Import d'un fichier ICS
    public function importIcs() {
        $this->checkPermission('import');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /agenda/?error=method_not_allowed');
            exit;
        }
        
        if (!isset($_FILES['icsfile']) || $_FILES['icsfile']['error'] != UPLOAD_ERR_OK) {
            header('Location: /agenda/?error=upload_failed');
            exit;
        }
        
        $icsContent = file_get_contents($_FILES['icsfile']['tmp_name']);
        $userId = $_SESSION['user_id'];
        
        try {
            // Traitement du fichier ICS
            $result = $this->processIcsFile($icsContent, $userId);
            header('Location: /agenda/?success=import_success&count=' . $result);
        } catch (Exception $e) {
            header('Location: /agenda/?error=import_failed&message=' . urlencode($e->getMessage()));
        }
    }
    
    // Traite le contenu d'un fichier ICS pour l'importer
    private function processIcsFile($icsContent, $userId) {
        // Implémentation de l'analyse et de l'import ICS
        // ...
        return 0; // Nombre d'événements importés
    }
}