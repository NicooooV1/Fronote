<?php
// Ce fichier sera inclus depuis agenda.php lorsque view=list
// Les variables suivantes sont déjà disponibles:
// $events - les événements filtrés
// $filter_type, $filter_classes - les filtres actifs

// Configuration de la pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20; // Nombre d'événements par page

// Filtrer par période
$period = isset($_GET['period']) ? $_GET['period'] : 'upcoming';

// Appliquer le filtre de période
$now = date('Y-m-d H:i:s');
$filtered_events = [];

if ($period === 'upcoming') {
    // Événements à venir
    foreach ($events as $event) {
        if (strtotime($event['date_debut']) >= strtotime($now)) {
            $filtered_events[] = $event;
        }
    }
} elseif ($period === 'past') {
    // Événements passés
    foreach ($events as $event) {
        if (strtotime($event['date_debut']) < strtotime($now)) {
            $filtered_events[] = $event;
        }
    }
} else {
    // Tous les événements
    $filtered_events = $events;
}

// Trier les événements
if ($period === 'past') {
    // Événements passés: du plus récent au plus ancien
    usort($filtered_events, function($a, $b) {
        return strtotime($b['date_debut']) - strtotime($a['date_debut']);
    });
} else {
    // Événements à venir ou tous: du plus proche au plus éloigné
    usort($filtered_events, function($a, $b) {
        return strtotime($a['date_debut']) - strtotime($b['date_debut']);
    });
}

// Calculer le nombre total de pages
$total_events = count($filtered_events);
$total_pages = ceil($total_events / $per_page);

// S'assurer que la page est dans les limites
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Extraire les événements pour la page courante
$start = ($page - 1) * $per_page;
$paged_events = array_slice($filtered_events, $start, $per_page);

// Construire les paramètres pour les liens de pagination
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

<div class="list-filters">
    <h2>Liste des événements</h2>
    
    <div class="period-tabs">
        <a href="?view=list&period=upcoming<?= $filter_params ?>" 
           class="period-tab <?= $period === 'upcoming' ? 'active' : '' ?>">À venir</a>
        <a href="?view=list&period=past<?= $filter_params ?>" 
           class="period-tab <?= $period === 'past' ? 'active' : '' ?>">Passés</a>
        <a href="?view=list&period=all<?= $filter_params ?>" 
           class="period-tab <?= $period === 'all' ? 'active' : '' ?>">Tous</a>
    </div>
</div>

<?php if (count($paged_events) > 0): ?>
    <div class="event-list">
        <?php foreach ($paged_events as $event): ?>
            <?php
            $event_debut = new DateTime($event['date_debut']);
            $event_fin = new DateTime($event['date_fin']);
            $event_class = 'event-' . strtolower($event['type_evenement']);
            
            if ($event['statut'] === 'annulé') {
                $event_class .= ' event-cancelled';
            } elseif ($event['statut'] === 'reporté') {
                $event_class .= ' event-postponed';
            }
            ?>
            <div class="event-list-item <?= $event_class ?>">
                <div class="event-list-date">
                    <?php if ($event_debut->format('Y-m-d') === $event_fin->format('Y-m-d')): ?>
                        <div><?= $event_debut->format('d/m/Y') ?></div>
                        <div><?= $event_debut->format('H:i') ?> - <?= $event_fin->format('H:i') ?></div>
                    <?php else: ?>
                        <div>Du <?= $event_debut->format('d/m/Y') ?></div>
                        <div>au <?= $event_fin->format('d/m/Y') ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="event-list-details">
                    <div class="event-list-title"><?= htmlspecialchars($event['titre']) ?></div>
                    
                    <?php if (!empty($event['lieu'])): ?>
                        <div class="event-list-location">Lieu: <?= htmlspecialchars($event['lieu']) ?></div>
                    <?php endif; ?>
                    
                    <div class="event-list-creator">Par: <?= htmlspecialchars($event['createur']) ?></div>
                    
                    <?php if (!empty($event['matieres'])): ?>
                        <div class="event-list-subject">Matière: <?= htmlspecialchars($event['matieres']) ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="event-list-actions">
                    <a href="details_evenement.php?id=<?= $event['id'] ?>" class="button">Voir</a>
                    
                    <?php if (canEditEvent($event)): ?>
                        <a href="modifier_evenement.php?id=<?= $event['id'] ?>" class="button">Modifier</a>
                    <?php endif; ?>
                    
                    <?php if (canDeleteEvent($event)): ?>
                        <a href="supprimer_evenement.php?id=<?= $event['id'] ?>" class="button button-secondary" 
                           onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet événement ?');">Supprimer</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?view=list&period=<?= $period ?>&page=<?= $page - 1 . $filter_params ?>" 
                       class="button button-secondary">Précédent</a>
                <?php endif; ?>
                
                <div class="page-info">Page <?= $page ?> sur <?= $total_pages ?></div>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?view=list&period=<?= $period ?>&page=<?= $page + 1 . $filter_params ?>" 
                       class="button button-secondary">Suivant</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="no-events">
        <p>Aucun événement trouvé pour les critères sélectionnés.</p>
    </div>
<?php endif; ?>