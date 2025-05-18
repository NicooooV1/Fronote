<?php
// Ce fichier sera inclus depuis agenda.php lorsque view=week
// Les variables suivantes sont déjà disponibles:
// $date - la date au format Y-m-d
// $events - les événements filtrés pour cette semaine
// $filter_type, $filter_classes - les filtres actifs

// Créer un objet DateTime pour manipuler la date
$date_obj = new DateTime($date);
$day_of_week = $date_obj->format('N'); // 1 (lundi) à 7 (dimanche)

// Obtenir le premier jour de la semaine (lundi)
$start_of_week = clone $date_obj;
$start_of_week->modify('-' . ($day_of_week - 1) . ' days');

// Obtenir le dernier jour de la semaine (dimanche)
$end_of_week = clone $start_of_week;
$end_of_week->modify('+6 days');

// Tableau des jours de la semaine
$weekdays = [];
$weekday_names = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

for ($i = 0; $i < 7; $i++) {
    $day = clone $start_of_week;
    $day->modify('+' . $i . ' days');
    $weekdays[] = [
        'date' => $day->format('Y-m-d'),
        'display' => $day->format('d/m'),
        'name' => $weekday_names[$i],
        'is_today' => ($day->format('Y-m-d') === date('Y-m-d'))
    ];
}

// Organiser les événements par jour
$events_by_day = [];
foreach ($events as $event) {
    $event_date = date('Y-m-d', strtotime($event['date_debut']));
    
    if (!isset($events_by_day[$event_date])) {
        $events_by_day[$event_date] = [];
    }
    
    $events_by_day[$event_date][] = $event;
}

// Trier les événements par heure de début pour chaque jour
foreach ($events_by_day as $day => $day_events) {
    usort($events_by_day[$day], function($a, $b) {
        return strtotime($a['date_debut']) - strtotime($b['date_debut']);
    });
}

// Navigation
$prev_week = clone $start_of_week;
$prev_week->modify('-7 days');

$next_week = clone $start_of_week;
$next_week->modify('+7 days');

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

<div class="week-navigation">
    <a href="?view=week&date=<?= $prev_week->format('Y-m-d') . $filter_params ?>" class="button button-secondary">&lt; Semaine précédente</a>
    <h2>Semaine du <?= $start_of_week->format('d/m/Y') ?> au <?= $end_of_week->format('d/m/Y') ?></h2>
    <a href="?view=week&date=<?= $next_week->format('Y-m-d') . $filter_params ?>" class="button button-secondary">Semaine suivante &gt;</a>
</div>

<div class="week-view">
    <div class="week-header">
        <?php foreach ($weekdays as $day): ?>
            <?php $today_class = $day['is_today'] ? ' today' : ''; ?>
            <div class="week-day<?= $today_class ?>">
                <div class="week-day-name"><?= $day['name'] ?></div>
                <div class="week-day-date"><?= $day['display'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="week-body">
        <?php foreach ($weekdays as $day): ?>
            <?php 
            $day_date = $day['date'];
            $today_class = $day['is_today'] ? ' today' : '';
            ?>
            <div class="week-column<?= $today_class ?>" data-date="<?= $day_date ?>">
                <?php if (isset($events_by_day[$day_date])): ?>
                    <?php foreach ($events_by_day[$day_date] as $event): ?>
                        <?php
                        $event_start = new DateTime($event['date_debut']);
                        $event_class = 'event-' . strtolower($event['type_evenement']);
                        
                        if ($event['statut'] === 'annulé') {
                            $event_class .= ' event-cancelled';
                        } elseif ($event['statut'] === 'reporté') {
                            $event_class .= ' event-postponed';
                        }
                        ?>
                        <div class="week-event <?= $event_class ?>">
                            <div class="event-time"><?= $event_start->format('H:i') ?></div>
                            <a href="details_evenement.php?id=<?= $event['id'] ?>" class="event-title">
                                <?= htmlspecialchars($event['titre']) ?>
                            </a>
                            <?php if (!empty($event['lieu'])): ?>
                                <div class="event-location"><?= htmlspecialchars($event['lieu']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-events-day"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Script pour permettre le clic sur les jours de la semaine
document.querySelectorAll('.week-column').forEach(column => {
    column.addEventListener('click', function(e) {
        // Ne pas déclencher si on a cliqué sur un événement
        if (e.target.closest('.week-event') || e.target.closest('a')) {
            return;
        }
        
        const dayDate = this.getAttribute('data-date');
        if (dayDate) {
            window.location.href = `?view=day&date=${dayDate}<?= $filter_params ?>`;
        }
    });
});
</script>