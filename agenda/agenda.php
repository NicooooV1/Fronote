<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/header.php';
include 'includes/calendar_functions.php';
include 'includes/event_helpers.php';

// L'authentification est déjà vérifiée dans auth.php

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];

// Récupérer les paramètres de filtrage
$view = isset($_GET['view']) ? $_GET['view'] : 'month';
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$filter_classes = isset($_GET['classes']) ? (is_array($_GET['classes']) ? $_GET['classes'] : [$_GET['classes']]) : [];

// Assurer que le mois est entre 1 et 12
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Vérifier le format de la date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Obtenir le nombre de jours dans le mois
$num_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Obtenir le premier jour du mois (1 = lundi, 7 = dimanche)
$first_day_timestamp = mktime(0, 0, 0, $month, 1, $year);
$first_day = date('N', $first_day_timestamp);

// Noms des mois en français
$month_names = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Noms des jours en français
$day_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

// Types d'événements pour le filtre
$types_evenements = [
    'cours' => 'Cours',
    'devoirs' => 'Devoirs',
    'reunion' => 'Réunion',
    'examen' => 'Examen',
    'sortie' => 'Sortie scolaire',
    'autre' => 'Autre'
];

// Récupérer la liste des classes
$classes = [];
$json_file = __DIR__ . '/../login/data/etablissement.json';
if (file_exists($json_file)) {
    $etablissement_data = json_decode(file_get_contents($json_file), true);
    
    // Extraire les classes du secondaire
    if (!empty($etablissement_data['classes'])) {
        foreach ($etablissement_data['classes'] as $niveau => $niveaux) {
            foreach ($niveaux as $sousniveau => $classe_array) {
                foreach ($classe_array as $classe) {
                    $classes[] = $classe;
                }
            }
        }
    }
    
    // Extraire les classes du primaire
    if (!empty($etablissement_data['primaire'])) {
        foreach ($etablissement_data['primaire'] as $niveau => $classe_array) {
            foreach ($classe_array as $classe) {
                $classes[] = $classe;
            }
        }
    }
}

// Récupérer les événements
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

// Si la table existe, récupérer les événements en fonction de la vue
if ($table_exists) {
    // Paramètres de filtrage
    $params = [];
    $where_clauses = [];
    
    // Filtre de date selon la vue
    if ($view === 'month') {
        $where_clauses[] = "MONTH(date_debut) = ? AND YEAR(date_debut) = ?";
        $params[] = $month;
        $params[] = $year;
    } elseif ($view === 'day') {
        $where_clauses[] = "DATE(date_debut) = ?";
        $params[] = $date;
    } elseif ($view === 'week') {
        // Calculer le début et la fin de la semaine
        $date_obj = new DateTime($date);
        $day_of_week = $date_obj->format('N'); // 1 (lundi) à 7 (dimanche)
        $start_of_week = clone $date_obj;
        $start_of_week->modify('-' . ($day_of_week - 1) . ' days');
        $end_of_week = clone $start_of_week;
        $end_of_week->modify('+6 days');
        
        $where_clauses[] = "DATE(date_debut) BETWEEN ? AND ?";
        $params[] = $start_of_week->format('Y-m-d');
        $params[] = $end_of_week->format('Y-m-d');
    }
    
    // Filtre par type d'événement
    if (!empty($filter_type)) {
        $where_clauses[] = "type_evenement = ?";
        $params[] = $filter_type;
    }
    
    // Filtre par classe
    if (!empty($filter_classes)) {
        $class_conditions = [];
        foreach ($filter_classes as $class) {
            $class_conditions[] = "classes LIKE ?";
            $params[] = "%$class%";
        }
        if (!empty($class_conditions)) {
            $where_clauses[] = "(" . implode(" OR ", $class_conditions) . ")";
        }
    }
    
    // Filtrage en fonction du rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // Classe de l'élève (à adapter)
        
        $where_clauses[] = "(visibilite = 'public' 
                OR visibilite = 'eleves' 
                OR visibilite LIKE '%élèves%'
                OR classes LIKE ? 
                OR createur = ?)";
        $params[] = "%$classe%";
        $params[] = $user_fullname;
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics/professeurs
        $where_clauses[] = "(visibilite = 'public' 
                OR visibilite = 'professeurs' 
                OR visibilite LIKE '%professeurs%'
                OR createur = ?)";
        $params[] = $user_fullname;
    }
    // Pour les autres rôles (admin, vie scolaire), aucun filtrage supplémentaire
    
    // Construire la requête SQL
    $sql = "SELECT * FROM evenements";
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql .= " ORDER BY date_debut";
    
    // Exécuter la requête
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Organiser les événements par jour pour la vue mois
$events_by_day = [];
if ($view === 'month') {
    foreach ($events as $event) {
        $day = intval(date('j', strtotime($event['date_debut'])));
        if (!isset($events_by_day[$day])) {
            $events_by_day[$day] = [];
        }
        $events_by_day[$day][] = $event;
    }
}

// Navigation pour chaque vue
$month_nav = [
    'prev' => [
        'month' => ($month > 1) ? $month - 1 : 12,
        'year' => ($month > 1) ? $year : $year - 1
    ],
    'next' => [
        'month' => ($month < 12) ? $month + 1 : 1,
        'year' => ($month < 12) ? $year : $year + 1
    ]
];

?>

<div class="sidebar">
    <div class="sidebar-section">
        <div class="sidebar-header">
            <h3>Vues</h3>
        </div>
        <div class="sidebar-content">
            <div class="view-options">
                <a href="?view=month&month=<?= $month ?>&year=<?= $year ?>" class="view-option <?= $view === 'month' ? 'active' : '' ?>">
                    <i class="icon-calendar"></i> Vue Mois
                </a>
                <a href="?view=week&date=<?= $date ?>" class="view-option <?= $view === 'week' ? 'active' : '' ?>">
                    <i class="icon-week"></i> Vue Semaine
                </a>
                <a href="?view=day&date=<?= $date ?>" class="view-option <?= $view === 'day' ? 'active' : '' ?>">
                    <i class="icon-day"></i> Vue Jour
                </a>
                <a href="?view=list" class="view-option <?= $view === 'list' ? 'active' : '' ?>">
                    <i class="icon-list"></i> Vue Liste
                </a>
            </div>
        </div>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-header">
            <h3>Filtres</h3>
        </div>
        <div class="sidebar-content">
            <form id="filterForm" method="get" action="">
                <input type="hidden" name="view" value="<?= $view ?>">
                <?php if ($view === 'month'): ?>
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                <?php elseif ($view === 'day' || $view === 'week'): ?>
                    <input type="hidden" name="date" value="<?= $date ?>">
                <?php endif; ?>
                
                <div class="filter-group">
                    <label for="type">Type d'événement</label>
                    <div class="select-wrapper">
                        <select id="type" name="type" onchange="this.form.submit()">
                            <option value="">Tous les types</option>
                            <?php foreach ($types_evenements as $code => $nom): ?>
                                <option value="<?= $code ?>" <?= $filter_type === $code ? 'selected' : '' ?>>
                                    <?= $nom ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filter-group">
                    <label>Classes</label>
                    <div class="multiselect-wrapper">
                        <div class="multiselect-header" onclick="toggleMultiselect('classesDropdown')">
                            <span id="classesSelectedText">
                                <?= empty($filter_classes) ? 'Sélectionner des classes' : count($filter_classes).' classes sélectionnées' ?>
                            </span>
                            <i class="dropdown-icon"></i>
                        </div>
                        <div id="classesDropdown" class="multiselect-dropdown">
                            <div class="multiselect-actions">
                                <button type="button" class="action-button" onclick="selectAllClasses()">
                                    <i class="icon-check"></i> Tout sélectionner
                                </button>
                                <button type="button" class="action-button" onclick="deselectAllClasses()">
                                    <i class="icon-uncheck"></i> Tout désélectionner
                                </button>
                            </div>
                            <div class="multiselect-search">
                                <input type="text" id="classSearch" placeholder="Rechercher" onkeyup="filterClasses()">
                                <i class="icon-search"></i>
                            </div>
                            <div class="multiselect-options">
                                <?php foreach ($classes as $classe): ?>
                                    <div class="multiselect-option">
                                        <label>
                                            <input type="checkbox" name="classes[]" value="<?= $classe ?>" 
                                                   <?= in_array($classe, $filter_classes) ? 'checked' : '' ?> 
                                                   onchange="updateClassesSelected()">
                                            <span><?= $classe ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="multiselect-footer">
                                <button type="submit" class="apply-button">Appliquer</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="content">
    <div class="user-info">
        <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
    </div>

    <?php if (canManageEvents()): ?>
    <div class="actions">
        <a href="ajouter_evenement.php<?= ($view === 'day' || $view === 'week') ? '?date='.$date : '' ?>" class="button">Ajouter un événement</a>
    </div>
    <?php endif; ?>

    <?php if ($view === 'month'): ?>
        <div class="calendar-navigation">
            <a href="?view=month&month=<?= $month_nav['prev']['month'] ?>&year=<?= $month_nav['prev']['year'] ?>&type=<?= $filter_type ?><?= !empty($filter_classes) ? '&classes[]='.implode('&classes[]=', $filter_classes) : '' ?>" class="button button-secondary">&lt; Mois précédent</a>
            <h2><?= $month_names[$month] . ' ' . $year ?></h2>
            <a href="?view=month&month=<?= $month_nav['next']['month'] ?>&year=<?= $month_nav['next']['year'] ?>&type=<?= $filter_type ?><?= !empty($filter_classes) ? '&classes[]='.implode('&classes[]=', $filter_classes) : '' ?>" class="button button-secondary">Mois suivant &gt;</a>
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
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $today_class = ($date_str == date('Y-m-d')) ? ' today' : '';
                    
                    echo '<div class="calendar-day' . $today_class . '" data-date="' . $date_str . '">';
                    echo '<div class="calendar-day-number">' . $day . '</div>';
                    
                    // Afficher les événements de ce jour
                    if (isset($events_by_day[$day])) {
                        echo '<div class="calendar-day-events">';
                        foreach ($events_by_day[$day] as $event) {
                            $event_time = date('H:i', strtotime($event['date_debut']));
                            $event_class = 'event-' . strtolower($event['type_evenement']);
                            
                            if ($event['statut'] === 'annulé') {
                                $event_class .= ' event-cancelled';
                            } elseif ($event['statut'] === 'reporté') {
                                $event_class .= ' event-postponed';
                            }
                            
                            echo '<div class="calendar-event ' . $event_class . '" data-event-id="' . $event['id'] . '">';
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
    <?php elseif ($view === 'day'): ?>
        <?php include 'views/day_view.php'; ?>
    <?php elseif ($view === 'week'): ?>
        <?php include 'views/week_view.php'; ?>
    <?php elseif ($view === 'list'): ?>
        <?php include 'views/list_view.php'; ?>
    <?php endif; ?>
</div>

<script>
// Fonction pour la navigation par clic sur les jours du calendrier
document.querySelectorAll('.calendar-day').forEach(day => {
    if (!day.classList.contains('empty')) {
        day.addEventListener('click', function() {
            const dayDate = this.getAttribute('data-date');
            if (dayDate) {
                window.location.href = `?view=day&date=${dayDate}&type=<?= $filter_type ?><?= !empty($filter_classes) ? '&classes[]='.implode('&classes[]=', $filter_classes) : '' ?>`;
            }
        });
    }
});

// Fonctions pour le multi-select des classes
function toggleMultiselect(id) {
    const dropdown = document.getElementById(id);
    dropdown.classList.toggle('open');
}

function selectAllClasses() {
    document.querySelectorAll('input[name="classes[]"]').forEach(checkbox => {
        checkbox.checked = true;
    });
    updateClassesSelected();
}

function deselectAllClasses() {
    document.querySelectorAll('input[name="classes[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateClassesSelected();
}

function updateClassesSelected() {
    const checkboxes = document.querySelectorAll('input[name="classes[]"]:checked');
    const text = checkboxes.length === 0 
        ? 'Sélectionner des classes' 
        : checkboxes.length + ' classes sélectionnées';
    document.getElementById('classesSelectedText').textContent = text;
}

function filterClasses() {
    const searchText = document.getElementById('classSearch').value.toLowerCase();
    document.querySelectorAll('.multiselect-option').forEach(option => {
        const className = option.querySelector('span').textContent.toLowerCase();
        option.style.display = className.includes(searchText) ? 'block' : 'none';
    });
}

// Fermer le dropdown quand on clique en dehors
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multiselect-wrapper')) {
        document.querySelectorAll('.multiselect-dropdown').forEach(dropdown => {
            dropdown.classList.remove('open');
        });
    }
});
</script>

<?php
include 'includes/footer.php';

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>