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
$formatted_date = $date_obj->format('d/m/Y');
$day_of_week = $date_obj->format('N'); // 1 (lundi) à 7 (dimanche)
$day_name = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'][$day_of_week];

// Récupérer les événements pour cette journée
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
    // Construire la requête SQL en fonction du rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // La classe de l'élève (à adapter selon votre système)
        
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) = ? 
                AND (visibilite = 'public' 
                    OR visibilite = 'eleves' 
                    OR visibilite LIKE '%élèves%'
                    OR classes LIKE ? 
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date, "%$classe%", $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) = ? 
                AND (visibilite = 'public' 
                    OR visibilite = 'professeurs' 
                    OR visibilite LIKE '%professeurs%'
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date, $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // Pour les administrateurs, vie scolaire et autres rôles, montrer tous les événements
        $sql = "SELECT * FROM evenements 
                WHERE DATE(date_debut) = ? 
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Trier les événements par heure de début
usort($events, function($a, $b) {
    return strtotime($a['date_debut']) - strtotime($b['date_debut']);
});
?>

<div class="container">
    <div class="user-info">
        <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
    </div>

    <?php if (canManageEvents()): ?>
    <div class="actions">
        <a href="ajouter_evenement.php?date=<?= $date ?>" class="button">Ajouter un événement</a>
    </div>
    <?php endif; ?>

    <div class="day-navigation">
        <?php
        // Navigation jour précédent
        $prev_day = clone $date_obj;
        $prev_day->modify('-1 day');
        
        // Navigation jour suivant
        $next_day = clone $date_obj;
        $next_day->modify('+1 day');
        ?>
        <a href="?date=<?= $prev_day->format('Y-m-d') ?>" class="button button-secondary">&lt; Jour précédent</a>
        <h2><?= $day_name . ' ' . $formatted_date ?></h2>
        <a href="?date=<?= $next_day->format('Y-m-d') ?>" class="button button-secondary">Jour suivant &gt;</a>
    </div>

    <div class="agenda-jour">
        <?php
        // Créer les plages horaires (de 8h à 19h)
        $start_hour = 8;
        $end_hour = 19;
        
        // Tableau pour stocker les événements par heure
        $events_by_hour = [];
        
        // Organiser les événements par heure de début
        foreach ($events as $event) {
            $event_start = new DateTime($event['date_debut']);
            $hour = (int)$event_start->format('G'); // Heure sans zéro initial
            
            if (!isset($events_by_hour[$hour])) {
                $events_by_hour[$hour] = [];
            }
            
            $events_by_hour[$hour][] = $event;
        }
        
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

    <div class="calendar-view-options">
        <a href="agenda_jour.php" class="button">Vue Jour</a>
        <a href="agenda_semaine.php?date=<?= $date ?>" class="button button-secondary">Vue Semaine</a>
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