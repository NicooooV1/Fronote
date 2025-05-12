<?php
require_once __DIR__ . '/../models/EmploiDuTemps.php';
require_once __DIR__ . '/../models/Evenement.php';

class AgendaController {
    private $edtModel;
    private $eventModel;

    public function __construct() {
        $this->edtModel   = new EmploiDuTemps();
        $this->eventModel = new Evenement();
    }

    // Affiche la vue principale
    public function index() {
        include __DIR__ . '/../views/agenda/index.php';
    }

    // Renvoie les cours et événements au format JSON
    public function getEvents() {
        header('Content-Type: application/json');
        $userId = $_SESSION['user_id'];
        $start  = $_GET['start'] ?? null;
        $end    = $_GET['end']   ?? null;
        $events = [];

        if ($start && $end) {
            // Récupère les emplois du temps
            $cours = $this->edtModel->fetchBetween($userId, $start, $end);
            foreach ($cours as $c) {
                $events[] = [
                    'id'    => $c['id'],
                    'title' => $c['matiere_label'],
                    'start' => $c['date'] . 'T' . $c['heure_debut'],
                    'end'   => $c['date'] . 'T' . $c['heure_fin'],
                    'color' => $c['couleur']
                ];
            }
            // Récupère les événements spéciaux
            $evts = $this->eventModel->fetchBetween($start, $end);
            foreach ($evts as $e) {
                $events[] = [
                    'id'    => 'E' . $e['id'],
                    'title' => $e['titre'],
                    'start' => $e['date_debut'],
                    'end'   => $e['date_fin'],
                    'color' => $e['couleur']
                ];
            }
        }
        echo json_encode($events);
    }

    // Génère et envoie un .ics
    public function exportIcs() {
        $userId = $_SESSION['user_id'];
        $start  = $_GET['start'] ?? date('Y-m-d');
        $end    = $_GET['end']   ?? date('Y-m-d', strtotime('+1 week'));

        $cours = $this->edtModel->fetchBetween($userId, $start, $end);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="agenda.ics"');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//MonPronoteWeb//AGENDA//FR\r\n";

        foreach ($cours as $c) {
            $dtstart = str_replace(['-',':'], ['', ''], $c['date'] . 'T' . $c['heure_debut'] . '00');
            $dtend   = str_replace(['-',':'], ['', ''], $c['date'] . 'T' . $c['heure_fin']   . '00');
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . uniqid() . "\r\n";
            echo "DTSTAMP:" . gmdate('Ymd').'T'. gmdate('His') . "Z\r\n";
            echo "DTSTART;TZID=Europe/Paris:$dtstart\r\n";
            echo "DTEND;TZID=Europe/Paris:$dtend\r\n";
            echo "SUMMARY:{$c['matiere_label']} – Salle {$c['salle_label']}\r\n";
            echo "DESCRIPTION:Professeur {$c['prof_label']}\r\n";
            echo "END:VEVENT\r\n";
        }

        echo "END:VCALENDAR";
    }
}