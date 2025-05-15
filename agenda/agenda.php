<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';

// L'authentification est déjà vérifiée dans auth.php

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Récupérer les paramètres de filtrage
$view = isset($_GET['view']) ? $_GET['view'] : 'month';
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_types = isset($_GET['types']) ? (is_array($_GET['types']) ? $_GET['types'] : [$_GET['types']]) : [];
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

// Obtenir le jour du mois pour aujourd'hui
$today_day = date('j');
$today_month = date('n');
$today_year = date('Y');

// Noms des mois en français
$month_names = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Noms des jours en français
$day_names = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
$day_names_full = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

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
    if (!empty($filter_types)) {
        $type_placeholders = implode(',', array_fill(0, count($filter_types), '?'));
        $where_clauses[] = "type_evenement IN ($type_placeholders)";
        $params = array_merge($params, $filter_types);
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

// Mini-calendrier pour le mois actuel
function generateMiniCalendar($month, $year, $selected_date = null) {
    global $day_names, $month_names;
    
    $num_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $first_day_timestamp = mktime(0, 0, 0, $month, 1, $year);
    $first_day = date('N', $first_day_timestamp);
    
    $today_day = date('j');
    $today_month = date('n');
    $today_year = date('Y');
    
    $selected_day = $selected_date ? date('j', strtotime($selected_date)) : null;
    $selected_month = $selected_date ? date('n', strtotime($selected_date)) : null;
    $selected_year = $selected_date ? date('Y', strtotime($selected_date)) : null;
    
    $html = '<div class="mini-calendar-header">';
    $html .= '<span class="mini-calendar-title">' . $month_names[$month] . ' ' . $year . '</span>';
    $html .= '<div class="mini-calendar-nav">';
    $html .= '<button class="mini-calendar-nav-btn prev" data-month="' . ($month-1) . '" data-year="' . ($month==1 ? $year-1 : $year) . '">&lt;</button>';
    $html .= '<button class="mini-calendar-nav-btn next" data-month="' . ($month+1) . '" data-year="' . ($month==12 ? $year+1 : $year) . '">&gt;</button>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="mini-calendar-grid">';
    
    // Afficher les noms des jours
    foreach ($day_names as $day) {
        $html .= '<div class="mini-calendar-day-name">' . $day . '</div>';
    }
    
    // Jours du mois précédent pour remplir la première semaine
    $prev_month = $month - 1;
    $prev_year = $year;
    if ($prev_month < 1) {
        $prev_month = 12;
        $prev_year--;
    }
    $prev_month_days = cal_days_in_month(CAL_GREGORIAN, $prev_month, $prev_year);
    
    for ($i = 1; $i < $first_day; $i++) {
        $day_num = $prev_month_days - $first_day + $i + 1;
        $html .= '<div class="mini-calendar-day other-month">' . $day_num . '</div>';
    }
    
    // Jours du mois courant
    for ($day = 1; $day <= $num_days; $day++) {
        $is_today = ($day == $today_day && $month == $today_month && $year == $today_year);
        $is_selected = ($day == $selected_day && $month == $selected_month && $year == $selected_year);
        
        $classes = '';
        if ($is_today) $classes .= ' today';
        if ($is_selected) $classes .= ' selected';
        
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
        $html .= '<div class="mini-calendar-day' . $classes . '" data-date="' . $date . '">' . $day . '</div>';
    }
    
    // Jours du mois suivant pour compléter la dernière semaine
    $days_shown = $first_day - 1 + $num_days;
    $remaining_days = 7 - ($days_shown % 7);
    if ($remaining_days < 7) {
        for ($day = 1; $day <= $remaining_days; $day++) {
            $html .= '<div class="mini-calendar-day other-month">' . $day . '</div>';
        }
    }
    
    $html .= '</div>';
    
    return $html;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agenda Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Agenda</div>
      </div>
      
      <!-- Mini-calendrier pour la navigation -->
      <div class="mini-calendar">
        <?= generateMiniCalendar($month, $year, $date) ?>
      </div>
      
      <!-- Créer un événement -->
      <div class="sidebar-section">
        <a href="ajouter_evenement.php" class="create-button">
          <span>+</span> Créer un événement
        </a>
      </div>
      
      <!-- Filtres par type d'événement -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">
          <span>Types d'événements</span>
          <button class="toggle-button">▾</button>
        </div>
        <div class="calendar-filters">
          <?php foreach ($types_evenements as $code => $nom): ?>
            <div class="filter-option">
              <span class="color-dot color-<?= $code ?>"></span>
              <span class="filter-label"><?= $nom ?></span>
              <input type="checkbox" class="filter-checkbox" 
                     id="filter-<?= $code ?>" 
                     name="types[]" 
                     value="<?= $code ?>" 
                     <?= in_array($code, $filter_types) || empty($filter_types) ? 'checked' : '' ?> 
                     data-filter-type="type">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      
      <!-- Filtres par classe -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">
          <span>Classes</span>
          <button class="toggle-button">▾</button>
        </div>
        <div class="classes-filter">
          <div class="classes-dropdown">
            <button type="button" class="classes-dropdown-toggle" onclick="toggleClassesDropdown()">
              <span id="selected-classes-text">
                <?= empty($filter_classes) ? 'Sélectionner des classes' : count($filter_classes).' classes sélectionnées' ?>
              </span>
              <span>▾</span>
            </button>
            <div class="dropdown-menu" id="classes-dropdown">
              <div class="dropdown-actions">
                <button type="button" class="dropdown-action" onclick="selectAllClasses()">Tout sélectionner</button>
                <button type="button" class="dropdown-action" onclick="deselectAllClasses()">Tout désélectionner</button>
              </div>
              <div class="dropdown-search">
                <input type="text" id="classes-search" placeholder="Rechercher" oninput="filterClasses()">
              </div>
              <div class="dropdown-options" id="classes-options">
                <?php foreach ($classes as $classe): ?>
                  <div class="dropdown-option">
                    <input type="checkbox" 
                           id="class-<?= $classe ?>" 
                           class="filter-checkbox class-checkbox" 
                           name="classes[]" 
                           value="<?= $classe ?>" 
                           <?= in_array($classe, $filter_classes) ? 'checked' : '' ?> 
                           data-filter-type="class" 
                           onchange="updateSelectedClasses()">
                    <label for="class-<?= $classe ?>"><?= $classe ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="dropdown-footer">
                <button type="button" class="apply-button" onclick="applyClassesFilter()">Appliquer</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div class="sidebar-section">
        <a href="/~u22405372/SAE/Pronote/notes/notes.php" class="menu-item">Système de Notes</a>
        <a href="/~u22405372/SAE/Pronote/accueil/accueil.php" class="menu-item">Accueil Pronote</a>
        <a href="/~u22405372/SAE/Pronote/login/public/logout.php" class="menu-item">Déconnexion</a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="calendar-navigation">
          <button class="nav-button prev-button" onclick="navigateToPrevious()">&lt;</button>
          <button class="nav-button next-button" onclick="navigateToNext()">&gt;</button>
          <button class="today-button" onclick="navigateToToday()">Aujourd'hui</button>
          <h2 class="calendar-title">
            <?php if ($view === 'month'): ?>
              <?= $month_names[$month] . ' ' . $year ?>
            <?php elseif ($view === 'day'): ?>
              <?= date('d', strtotime($date)) . ' ' . $month_names[date('n', strtotime($date))] . ' ' . date('Y', strtotime($date)) ?>
            <?php elseif ($view === 'week'): ?>
              <?php
                $date_obj = new DateTime($date);
                $day_of_week = $date_obj->format('N');
                $start_of_week = clone $date_obj;
                $start_of_week->modify('-' . ($day_of_week - 1) . ' days');
                $end_of_week = clone $start_of_week;
                $end_of_week->modify('+6 days');
                echo $start_of_week->format('d') . ' - ' . $end_of_week->format('d') . ' ' . $month_names[$start_of_week->format('n')] . ' ' . $start_of_week->format('Y');
              ?>
            <?php endif; ?>
          </h2>
        </div>
        
        <div class="view-toggle">
          <a href="?view=day&date=<?= $date ?>" class="view-toggle-option <?= $view === 'day' ? 'active' : '' ?>">Jour</a>
          <a href="?view=week&date=<?= $date ?>" class="view-toggle-option <?= $view === 'week' ? 'active' : '' ?>">Semaine</a>
          <a href="?view=month&month=<?= $month ?>&year=<?= $year ?>" class="view-toggle-option <?= $view === 'month' ? 'active' : '' ?>">Mois</a>
          <a href="?view=list" class="view-toggle-option <?= $view === 'list' ? 'active' : '' ?>">Liste</a>
        </div>
        
        <div class="user-info">
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Calendar Container -->
      <div class="calendar-container">
        <?php if ($view === 'month'): ?>
          <!-- Vue mensuelle -->
          <div class="calendar">
            <div class="calendar-header">
              <?php foreach ($day_names_full as $day): ?>
                <div class="calendar-header-day"><?= $day ?></div>
              <?php endforeach; ?>
            </div>
            
            <div class="calendar-body">
              <?php
              // Jours du mois précédent
              $prev_month = $month > 1 ? $month - 1 : 12;
              $prev_year = $month > 1 ? $year : $year - 1;
              $prev_month_days = cal_days_in_month(CAL_GREGORIAN, $prev_month, $prev_year);
              
              for ($i = 1; $i < $first_day; $i++) {
                $day_num = $prev_month_days - $first_day + $i + 1;
                echo '<div class="calendar-day other-month">';
                echo '<div class="calendar-day-number">' . $day_num . '</div>';
                echo '</div>';
              }
              
              // Jours du mois courant
              for ($day = 1; $day <= $num_days; $day++) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $is_today = ($day == $today_day && $month == $today_month && $year == $today_year);
                
                $today_class = $is_today ? ' today' : '';
                
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
                    echo '<span class="event-time">' . $event_time . '</span> ';
                    echo htmlspecialchars($event['titre']);
                    echo '</div>';
                  }
                  echo '</div>';
                }
                
                echo '</div>';
              }
              
              // Jours du mois suivant
              $days_shown = $first_day - 1 + $num_days;
              $remaining_days = 7 - ($days_shown % 7);
              if ($remaining_days < 7) {
                for ($day = 1; $day <= $remaining_days; $day++) {
                  echo '<div class="calendar-day other-month">';
                  echo '<div class="calendar-day-number">' . $day . '</div>';
                  echo '</div>';
                }
              }
              ?>
            </div>
          </div>
          
        <?php elseif ($view === 'day'): ?>
          <!-- Vue jour -->
          <?php include 'views/day_view.php'; ?>
          
        <?php elseif ($view === 'week'): ?>
          <!-- Vue semaine -->
          <?php include 'views/week_view.php'; ?>
          
        <?php elseif ($view === 'list'): ?>
          <!-- Vue liste -->
          <?php include 'views/list_view.php'; ?>
          
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <script>
    // Fonctions pour la navigation
    function navigateToPrevious() {
      const view = '<?= $view ?>';
      let url = '';
      
      if (view === 'month') {
        const month = <?= $month ?>;
        const year = <?= $year ?>;
        
        if (month === 1) {
          url = `?view=month&month=12&year=${year-1}`;
        } else {
          url = `?view=month&month=${month-1}&year=${year}`;
        }
      } else if (view === 'day') {
        const currentDate = new Date('<?= $date ?>');
        currentDate.setDate(currentDate.getDate() - 1);
        const newDate = currentDate.toISOString().split('T')[0];
        url = `?view=day&date=${newDate}`;
      } else if (view === 'week') {
        const currentDate = new Date('<?= $date ?>');
        currentDate.setDate(currentDate.getDate() - 7);
        const newDate = currentDate.toISOString().split('T')[0];
        url = `?view=week&date=${newDate}`;
      }
      
      // Ajouter les filtres
      url += getFilterParams();
      
      window.location.href = url;
    }
    
    function navigateToNext() {
      const view = '<?= $view ?>';
      let url = '';
      
      if (view === 'month') {
        const month = <?= $month ?>;
        const year = <?= $year ?>;
        
        if (month === 12) {
          url = `?view=month&month=1&year=${year+1}`;
        } else {
          url = `?view=month&month=${month+1}&year=${year}`;
        }
      } else if (view === 'day') {
        const currentDate = new Date('<?= $date ?>');
        currentDate.setDate(currentDate.getDate() + 1);
        const newDate = currentDate.toISOString().split('T')[0];
        url = `?view=day&date=${newDate}`;
      } else if (view === 'week') {
        const currentDate = new Date('<?= $date ?>');
        currentDate.setDate(currentDate.getDate() + 7);
        const newDate = currentDate.toISOString().split('T')[0];
        url = `?view=week&date=${newDate}`;
      }
      
      // Ajouter les filtres
      url += getFilterParams();
      
      window.location.href = url;
    }
    
    function navigateToToday() {
      const view = '<?= $view ?>';
      const today = new Date();
      const todayStr = today.toISOString().split('T')[0];
      
      let url = '';
      
      if (view === 'month') {
        url = `?view=month&month=${today.getMonth() + 1}&year=${today.getFullYear()}`;
      } else if (view === 'day' || view === 'week') {
        url = `?view=${view}&date=${todayStr}`;
      } else {
        url = `?view=${view}`;
      }
      
      // Ajouter les filtres
      url += getFilterParams();
      
      window.location.href = url;
    }
    
    // Fonctions pour le mini-calendrier
    document.querySelectorAll('.mini-calendar-day').forEach(day => {
      if (!day.classList.contains('other-month')) {
        day.addEventListener('click', function() {
          const date = this.getAttribute('data-date');
          if (date) {
            let url = `?view=day&date=${date}`;
            url += getFilterParams();
            window.location.href = url;
          }
        });
      }
    });
    
    document.querySelectorAll('.mini-calendar-nav-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        const month = this.getAttribute('data-month');
        const year = this.getAttribute('data-year');
        let url = `?view=month&month=${month}&year=${year}`;
        url += getFilterParams();
        window.location.href = url;
      });
    });
    
    // Fonctions pour les filtres
    document.querySelectorAll('.filter-checkbox[data-filter-type="type"]').forEach(checkbox => {
      checkbox.addEventListener('change', function() {
        applyFilters();
      });
    });
    
    function applyFilters() {
      let url = `?view=<?= $view ?>`;
      
      if ('<?= $view ?>' === 'month') {
        url += `&month=<?= $month ?>&year=<?= $year ?>`;
      } else if ('<?= $view ?>' === 'day' || '<?= $view ?>' === 'week') {
        url += `&date=<?= $date ?>`;
      }
      
      // Ajouter les filtres
      url += getFilterParams();
      
      window.location.href = url;
    }
    
    function getFilterParams() {
      let params = '';
      
      // Filtres de type
      const typeCheckboxes = document.querySelectorAll('.filter-checkbox[data-filter-type="type"]:checked');
      typeCheckboxes.forEach(checkbox => {
        params += `&types[]=${checkbox.value}`;
      });
      
      // Filtres de classe (uniquement les classes sélectionnées)
      const classCheckboxes = document.querySelectorAll('.filter-checkbox[data-filter-type="class"]:checked');
      classCheckboxes.forEach(checkbox => {
        params += `&classes[]=${checkbox.value}`;
      });
      
      return params;
    }
    
    // Fonctions pour le dropdown des classes
    function toggleClassesDropdown() {
      const dropdown = document.getElementById('classes-dropdown');
      dropdown.classList.toggle('show');
    }
    
    function selectAllClasses() {
      document.querySelectorAll('.class-checkbox').forEach(checkbox => {
        checkbox.checked = true;
      });
      updateSelectedClasses();
    }
    
    function deselectAllClasses() {
      document.querySelectorAll('.class-checkbox').forEach(checkbox => {
        checkbox.checked = false;
      });
      updateSelectedClasses();
    }
    
    function updateSelectedClasses() {
      const checkboxes = document.querySelectorAll('.class-checkbox:checked');
      const text = checkboxes.length === 0 
          ? 'Sélectionner des classes' 
          : checkboxes.length + ' classes sélectionnées';
      document.getElementById('selected-classes-text').textContent = text;
    }
    
    function filterClasses() {
      const searchText = document.getElementById('classes-search').value.toLowerCase();
      document.querySelectorAll('.dropdown-option').forEach(option => {
        const className = option.querySelector('label').textContent.toLowerCase();
        option.style.display = className.includes(searchText) ? 'flex' : 'none';
      });
    }
    
    function applyClassesFilter() {
      document.getElementById('classes-dropdown').classList.remove('show');
      applyFilters();
    }
    
    // Fermer le dropdown quand on clique en dehors
    document.addEventListener('click', function(event) {
      if (!event.target.closest('.classes-dropdown')) {
        document.getElementById('classes-dropdown').classList.remove('show');
      }
    });
    
    // Événements pour la navigation par clics sur les jours du calendrier
    document.querySelectorAll('.calendar-day:not(.other-month)').forEach(day => {
      day.addEventListener('click', function(e) {
        if (e.target === this || e.target.classList.contains('calendar-day-number')) {
          const date = this.getAttribute('data-date');
          if (date) {
            let url = `?view=day&date=${date}`;
            url += getFilterParams();
            window.location.href = url;
          }
        }
      });
    });
    
    // Événements pour les clics sur les événements du calendrier
    document.querySelectorAll('.calendar-event').forEach(event => {
      event.addEventListener('click', function(e) {
        e.stopPropagation();
        const eventId = this.getAttribute('data-event-id');
        if (eventId) {
          window.location.href = `details_evenement.php?id=${eventId}`;
        }
      });
    });
  </script>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>