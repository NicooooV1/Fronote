<?php
// Ce fichier sera inclus depuis agenda.php lorsque view=day
// Les variables suivantes sont déjà disponibles:
// $date - la date au format Y-m-d
// $events - les événements filtrés pour cette date

// Créer un objet DateTime pour manipuler la date
$date_obj = new DateTime($date);
$formatted_date = $date_obj->format('d/m/Y');
$day_of_week = $date_obj->format('N'); // 1 (lundi) à 7 (dimanche)
$day_name = $day_names_full[$day_of_week - 1];

// Organiser les événements par heure
$events_by_hour = [];
foreach ($events as $event) {
    $event_start = new DateTime($event['date_debut']);
    $hour = (int)$event_start->format('G'); // Heure sans zéro initial
    $minute = (int)$event_start->format('i');
    
    // Calculer la position et la hauteur de l'événement
    $event_end = new DateTime($event['date_fin']);
    $duration_minutes = ($event_end->getTimestamp() - $event_start->getTimestamp()) / 60;
    $top_position = $minute / 60 * 100; // Position relative en pourcentage dans l'heure
    $height = $duration_minutes / 60 * 100; // Hauteur relative en pourcentage (par rapport à une heure)
    
    if (!isset($events_by_hour[$hour])) {
        $events_by_hour[$hour] = [];
    }
    
    // Ajouter les informations de position
    $event['top_position'] = $top_position;
    $event['height'] = $height;
    $event['duration_minutes'] = $duration_minutes;
    
    $events_by_hour[$hour][] = $event;
}
?>

<div class="day-view">
  <div class="day-header">
    <div class="day-title"><?= $day_name . ' ' . $formatted_date ?></div>
  </div>
  
  <div class="day-body">
    <div class="day-timeline">
      <?php for ($hour = 8; $hour <= 19; $hour++): ?>
        <div class="timeline-hour"><?= sprintf('%02d:00', $hour) ?></div>
      <?php endfor; ?>
    </div>
    
    <div class="day-events">
      <?php for ($hour = 8; $hour <= 19; $hour++): ?>
        <div class="hour-slot" data-hour="<?= $hour ?>">
          <?php if (isset($events_by_hour[$hour])): ?>
            <?php foreach ($events_by_hour[$hour] as $event): ?>
              <?php
              $event_class = 'event-' . strtolower($event['type_evenement']);
              if ($event['statut'] === 'annulé') {
                  $event_class .= ' event-cancelled';
              } elseif ($event['statut'] === 'reporté') {
                  $event_class .= ' event-postponed';
              }
              
              // Limiter la hauteur à la journée affichée
              $max_height = min($event['height'], (19 - $hour) * 100);
              ?>
              <div class="day-event <?= $event_class ?>" 
                   style="top: <?= $event['top_position'] ?>%; height: <?= $max_height ?>%;"
                   onclick="location.href='details_evenement.php?id=<?= $event['id'] ?>'">
                <div class="event-time">
                  <?= (new DateTime($event['date_debut']))->format('H:i') ?> - 
                  <?= (new DateTime($event['date_fin']))->format('H:i') ?>
                </div>
                <div class="event-title"><?= htmlspecialchars($event['titre']) ?></div>
                <?php if (!empty($event['lieu'])): ?>
                  <div class="event-location"><?= htmlspecialchars($event['lieu']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<?php if (empty($events)): ?>
<div class="no-events">
  <p>Aucun événement prévu pour cette journée.</p>
</div>
<?php endif; ?>