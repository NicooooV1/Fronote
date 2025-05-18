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

// Fonction pour déterminer si l'utilisateur peut modifier un événement
if (!function_exists('canEditEventLocal')) {
    function canEditEventLocal($event) {
        if (function_exists('canEditEvent')) {
            return canEditEvent($event);
        }
        
        // Fallback si la fonction canEditEvent n'existe pas
        $user = $_SESSION['user'] ?? null;
        if (!$user) return false;
        
        // Admin/vie scolaire peuvent tout modifier
        if ($user['profil'] === 'administrateur' || $user['profil'] === 'vie_scolaire') {
            return true;
        }
        
        // Un prof peut modifier ses propres événements
        if ($user['profil'] === 'professeur') {
            $user_fullname = $user['prenom'] . ' ' . $user['nom'];
            return $event['createur'] === $user_fullname;
        }
        
        return false;
    }
}

// Fonction pour déterminer si l'utilisateur peut supprimer un événement
if (!function_exists('canDeleteEventLocal')) {
    function canDeleteEventLocal($event) {
        if (function_exists('canDeleteEvent')) {
            return canDeleteEvent($event);
        }
        
        // Par défaut, mêmes règles que pour la modification
        return canEditEventLocal($event);
    }
}

// Trier les événements
usort($filtered_events, function($a, $b) {
    return strtotime($a['date_debut']) - strtotime($b['date_debut']);
});

// Calculer la pagination
$total_events = count($filtered_events);
$total_pages = ceil($total_events / $per_page);
$page = max(1, min($page, $total_pages)); // S'assurer que la page est valide
$offset = ($page - 1) * $per_page;
$events_page = array_slice($filtered_events, $offset, $per_page);
?>

<!-- Interface utilisateur pour les filtres de période -->
<div class="list-filters">
    <div class="period-filter">
        <a href="?view=list&period=upcoming" class="period-option <?= $period === 'upcoming' ? 'active' : '' ?>">À venir</a>
        <a href="?view=list&period=past" class="period-option <?= $period === 'past' ? 'active' : '' ?>">Passés</a>
        <a href="?view=list&period=all" class="period-option <?= $period === 'all' ? 'active' : '' ?>">Tous</a>
    </div>
</div>

<?php if (empty($events_page)): ?>
    <div class="no-events">
        <p>Aucun événement trouvé pour cette période.</p>
    </div>
<?php else: ?>
    <div class="events-list">
        <?php foreach ($events_page as $event): ?>
            <?php 
            // Déterminer les classes CSS en fonction du type d'événement et du statut
            $event_class = 'event-' . strtolower($event['type_evenement']);
            if ($event['statut'] === 'annulé') {
                $event_class .= ' event-cancelled';
            } elseif ($event['statut'] === 'reporté') {
                $event_class .= ' event-postponed';
            }
            
            // Formater les dates
            $date_debut = new DateTime($event['date_debut']);
            $date_fin = new DateTime($event['date_fin']);
            ?>
            <div class="event-item <?= $event_class ?>">
                <div class="event-date">
                    <?php if ($date_debut->format('Y-m-d') === $date_fin->format('Y-m-d')): ?>
                        <div class="event-day"><?= $date_debut->format('d/m/Y') ?></div>
                        <div class="event-time"><?= $date_debut->format('H:i') ?> - <?= $date_fin->format('H:i') ?></div>
                    <?php else: ?>
                        <div class="event-day">Du <?= $date_debut->format('d/m/Y') ?></div>
                        <div class="event-day">au <?= $date_fin->format('d/m/Y') ?></div>
                    <?php endif; ?>
                </div>
                <div class="event-details">
                    <h3 class="event-title"><?= htmlspecialchars($event['titre']) ?></h3>
                    <?php if (!empty($event['lieu'])): ?>
                        <div class="event-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['lieu']) ?></div>
                    <?php endif; ?>
                    <div class="event-creator">Créé par <?= htmlspecialchars($event['createur']) ?></div>
                </div>
                <div class="event-actions">
                    <a href="details_evenement.php?id=<?= $event['id'] ?>" class="btn btn-sm">Voir</a>
                    <?php if (canEditEventLocal($event)): ?>
                        <a href="modifier_evenement.php?id=<?= $event['id'] ?>" class="btn btn-sm">Modifier</a>
                    <?php endif; ?>
                    <?php if (canDeleteEventLocal($event)): ?>
                        <a href="supprimer_evenement.php?id=<?= $event['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet événement ?');">Supprimer</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?view=list&period=<?= $period ?>&page=<?= $page - 1 ?>" class="page-link">&laquo; Précédent</a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
            <a href="?view=list&period=<?= $period ?>&page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?view=list&period=<?= $period ?>&page=<?= $page + 1 ?>" class="page-link">Suivant &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>