<?php
// Ce fichier sera inclus depuis agenda.php lorsque view=day
// Les variables suivantes sont déjà disponibles:
// $date - la date au format Y-m-d
// $events - les événements filtrés pour cette date
// $filter_type, $filter_classes - les filtres actifs

// Créer un objet DateTime pour manipuler la date
$date_obj = new DateTime($date);
$formatted_date = $date_obj->format('d/m/Y');
$day_of_week = $date_obj->format('N'); // 1 (lundi) à 7 (dimanche)
$day_name = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'][$day_of_week];

// Trier les événements par heure de début
usort($events, function($a, $b) {
    return strtotime($a['date_debut']) - strtotime($b['date_debut']);
});

// Préparer les événements par heure
$events_by_hour = [];
foreach ($events as $event) {
    $event_start = new DateTime($event['date_debut']);
    $hour = (int)$event_start->format('G'); // Heure sans zéro initial
    
    if (!isset($events_by_hour[$hour])) {
        $events_by_hour[$hour] = [];
    }
    
    $events_by_hour[$hour][] = $event;
}

// Navigation
$prev_day = clone $date_obj;
$prev_day->modify('-1 day');

$next_day = clone $date_obj;
$next_day->modify('+1 day');

$filter_params = '';
if (!empty($filter_type)) {
    $filter_params .= '&type=' . $filter_type;
}
if (!empty($filter_classes)) {
    foreach ($filter_classes as $class) {
        $filter_params .= '&classes[]=' . urlencode($class);
    }
}
?>

<div class="day-navigation">
    <a href="?view=day&date=<?= $prev_day->format('Y-m-d') . $filter_params ?>" class="button button-secondary">&lt; Jour précédent</a>
    <h2><?= $day_name . ' ' . $formatted_date ?></h2>
    <a href="?view=day&date=<?= $next_day->format('Y-m-d') . $filter_params ?>" class="button button-secondary">Jour suivant &gt;</a>
</div>

<div class="agenda-jour">
    <?php
    // Créer les plages horaires (de 8h à 19h)
    $start_hour = 8;
    $end_hour = 19;
    
    // Générer les plages horaires et les événements
    for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
        echo '<div class="time-slot">' . sprintf('%02d:00', $hour) . '</div>';
        echo '<div class="event-slot">';
        
        if (isset($events_by_hour[$hour])) {
            foreach ($events_by_hour[$hour] as $event) {
                $event_start = new DateTime($event['date_debut']);
                $event_end = new DateTime($event['date_fin']);
                
                $time_display = $event_start->format('H:i') . ' - ' . $event_end->format('H:i');
                $event_class = 'event-' . strtolower($event['type_evenement']);
                
                if ($event['statut'] === 'annulé') {
                    $event_class .= ' event-cancelled';
                } elseif ($event['statut'] === 'reporté') {
                    $event_class .= ' event-postponed';
                }
                
                echo '<div class="agenda-event ' . $event_class . '">';
                echo '<div class="event-time">' . $time_display . '</div>';
                echo '<a href="details_evenement.php?id=' . $event['id'] . '" class="event-title">';
                echo htmlspecialchars($event['titre']);
                echo '</a>';
                
                if (!empty($event['lieu'])) {
                    echo '<div class="event-location">Lieu: ' . htmlspecialchars($event['lieu']) . '</div>';
                }
                
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    ?>
</div>

<?php if (empty($events)): ?>
<div class="no-events">
    <p>Aucun événement prévu pour cette journée.</p>
</div>
<?php endif; ?>