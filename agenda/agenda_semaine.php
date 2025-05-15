<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/header.php';
include 'includes/calendar_functions.php';
include 'includes/event_helpers.php';

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];

// Récupérer la date demandée ou utiliser la date du jour
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Vérifier le format de la date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

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

// Récupérer les événements pour cette semaine
$events = [];

// Vérifier si la table evenements existe
$table_exists = false;
try {
    $stmt_check = $pdo->query("SHOW TABLES LIKE 'evenements'");
    $table_exists = $stmt_check->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
}

// Si la table existe, récupérer les événements
if ($table_exists) {
    // Formater les dates pour la requête SQL
    $week_start = $start_of_week->format('Y-m-d');
    $week_end = $end_of_week->format('Y-m-d');
    
    // Construire la requête SQL en fonction du rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // La classe de l'élève (à adapter selon votre système)
        
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) BETWEEN ? AND ?
                AND (visibilite = 'public' 
                    OR visibilite = 'eleves' 
                    OR visibilite LIKE '%élèves%'
                    OR classes LIKE ? 
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$week_start, $week_end, "%$classe%", $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) BETWEEN ? AND ?
                AND (visibilite = 'public' 
                    OR visibilite = 'professeurs' 
                    OR visibilite LIKE '%professeurs%'
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$week_start, $week_end, $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // Pour les administrateurs, vie scolaire et autres rôles, montrer tous les événements
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) BETWEEN ? AND ?
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$week_start, $week_end]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
?>

<div class="container">
    <div class="user-info">
        <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
    </div>

    <?php if (canManageEvents()): ?>
    <div class="actions">
        <a href="ajouter_evenement.php" class="button">Ajouter un événement</a>
    </div>
    <?php endif; ?>

    <div class="week-navigation">
        <?php
        // Navigation semaine précédente
        $prev_week = clone $start_of_week;
        $prev_week->modify('-7 days');
        
        // Navigation semaine suivante
        $next_week = clone $start_of_week;
        $next_week->modify('+7 days');
        ?>
        <a href="?date=<?= $prev_week->format('Y-m-d') ?>" class="button button-secondary">&lt; Semaine précédente</a>
        <h2>Semaine du <?= $start_of_week->format('d/m/Y') ?> au <?= $end_of_week->format('d/m/Y') ?></h2>
        <a href="?date=<?= $next_week->format('Y-m-d') ?>" class="button button-secondary">Semaine suivante &gt;</a>
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
                <div class="week-column<?= $today_class ?>">
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

    <div class="calendar-view-options">
        <a href="agenda_jour.php" class="button button-secondary">Vue Jour</a>
        <a href="agenda_semaine.php" class="button">Vue Semaine</a>
        <a href="agenda.php" class="button button-secondary">Vue Mois</a>
        <a href="agenda_liste.php" class="button button-secondary">Vue Liste</a>
    </div>
</div>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>