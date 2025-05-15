<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/header.php';

// L'authentification est déjà vérifiée dans auth.php

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];

// Déterminer le mois et l'année à afficher
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Assurer que le mois est entre 1 et 12
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Obtenir le nombre de jours dans le mois
$num_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Obtenir le premier jour du mois (0 = dimanche, 1 = lundi, etc.)
$first_day_timestamp = mktime(0, 0, 0, $month, 1, $year);
$first_day = date('N', $first_day_timestamp); // 1 (pour lundi) à 7 (pour dimanche)

// Noms des mois en français
$month_names = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Noms des jours en français
$day_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

// Récupérer les événements pour ce mois
$events = [];

// Vérifier si la table evenements existe
$table_exists = false;
try {
    $stmt_check = $pdo->query("SHOW TABLES LIKE 'evenements'");
    $table_exists = $stmt_check->rowCount() > 0;
} catch (PDOException $e) {
    // La table n'existe probablement pas
    $table_exists = false;
}

// Si la table n'existe pas, essayer de la créer
if (!$table_exists) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS evenements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titre VARCHAR(100) NOT NULL,
            description TEXT,
            date_debut DATETIME NOT NULL,
            date_fin DATETIME NOT NULL,
            type_evenement VARCHAR(50) NOT NULL,
            statut VARCHAR(30) DEFAULT 'actif',
            createur VARCHAR(100) NOT NULL,
            visibilite VARCHAR(255) NOT NULL,
            lieu VARCHAR(100),
            classes VARCHAR(255),
            matieres VARCHAR(100),
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);
        $table_exists = true;
    } catch (PDOException $e) {
        // Erreur lors de la création de la table
        echo "Erreur lors de la création de la table: " . $e->getMessage();
    }
}

// Si la table existe, récupérer les événements
if ($table_exists) {
    // Construire la requête SQL en fonction du rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // On suppose que la classe de l'élève est stockée quelque part
        
        // Cette requête devra être ajustée selon votre modèle de données final
        $sql = "SELECT * FROM evenements 
                WHERE (MONTH(date_debut) = ? AND YEAR(date_debut) = ?) 
                AND (visibilite = 'public' 
                    OR visibilite = 'eleves' 
                    OR visibilite LIKE '%élèves%'
                    OR classes LIKE ? 
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$month, $year, "%$classe%", $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics
        $sql = "SELECT * FROM evenements 
                WHERE (MONTH(date_debut) = ? AND YEAR(date_debut) = ?) 
                AND (visibilite = 'public' 
                    OR visibilite = 'professeurs' 
                    OR visibilite LIKE '%professeurs%'
                    OR createur = ?)
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$month, $year, $user_fullname]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // Pour les administrateurs, vie scolaire et autres rôles, montrer tous les événements
        $sql = "SELECT * FROM evenements 
                WHERE (MONTH(date_debut) = ? AND YEAR(date_debut) = ?) 
                ORDER BY date_debut";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$month, $year]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Organiser les événements par jour
$events_by_day = [];
foreach ($events as $event) {
    $day = intval(date('j', strtotime($event['date_debut'])));
    if (!isset($events_by_day[$day])) {
        $events_by_day[$day] = [];
    }
    $events_by_day[$day][] = $event;
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

    <div class="calendar-navigation">
        <a href="?month=<?= $month-1 ?>&year=<?= $year ?>" class="button button-secondary">&lt; Mois précédent</a>
        <h2><?= $month_names[$month] . ' ' . $year ?></h2>
        <a href="?month=<?= $month+1 ?>&year=<?= $year ?>" class="button button-secondary">Mois suivant &gt;</a>
    </div>

    <div class="calendar">
        <div class="calendar-header">
            <?php foreach ($day_names as $day): ?>
                <div class="calendar-header-day"><?= $day ?></div>
            <?php endforeach; ?>
        </div>
        
        <div class="calendar-body">
            <?php
            // Ajouter des cellules vides pour les jours avant le début du mois
            for ($i = 1; $i < $first_day; $i++) {
                echo '<div class="calendar-day empty"></div>';
            }
            
            // Ajouter les jours du mois
            for ($day = 1; $day <= $num_days; $day++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $today_class = ($date == date('Y-m-d')) ? ' today' : '';
                
                echo '<div class="calendar-day' . $today_class . '">';
                echo '<div class="calendar-day-number">' . $day . '</div>';
                
                // Afficher les événements de ce jour
                if (isset($events_by_day[$day])) {
                    echo '<div class="calendar-day-events">';
                    foreach ($events_by_day[$day] as $event) {
                        $event_time = date('H:i', strtotime($event['date_debut']));
                        $event_class = 'event-' . strtolower($event['type_evenement']);
                        
                        echo '<div class="calendar-event ' . $event_class . '">';
                        echo '<span class="event-time">' . $event_time . '</span>';
                        echo '<a href="details_evenement.php?id=' . $event['id'] . '" class="event-title">';
                        echo htmlspecialchars($event['titre']);
                        echo '</a>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                
                echo '</div>';
                
                // Si c'est la fin de la semaine, passer à la ligne suivante
                if (($day + $first_day - 1) % 7 == 0) {
                    echo '<div class="calendar-row-end"></div>';
                }
            }
            
            // Ajouter des cellules vides pour compléter la dernière semaine
            $last_day = ($num_days + $first_day - 1) % 7;
            if ($last_day > 0) {
                for ($i = $last_day; $i < 7; $i++) {
                    echo '<div class="calendar-day empty"></div>';
                }
            }
            ?>
        </div>
    </div>

    <div class="calendar-view-options">
        <a href="agenda_jour.php" class="button button-secondary">Vue Jour</a>
        <a href="agenda_semaine.php" class="button button-secondary">Vue Semaine</a>
        <a href="agenda.php" class="button">Vue Mois</a>
        <a href="agenda_liste.php" class="button button-secondary">Vue Liste</a>
    </div>
</div>

<script>
// Script pour permettre le clic sur les jours du calendrier
document.querySelectorAll('.calendar-day').forEach(day => {
    if (!day.classList.contains('empty')) {
        day.addEventListener('click', function() {
            const dayNumber = this.querySelector('.calendar-day-number').textContent;
            const date = new Date(<?= $year ?>, <?= $month-1 ?>, parseInt(dayNumber));
            const formattedDate = date.toISOString().split('T')[0];
            window.location.href = 'agenda_jour.php?date=' + formattedDate;
        });
    }
});
</script>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>