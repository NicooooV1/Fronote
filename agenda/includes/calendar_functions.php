<?php
/**
 * Fonctions utilitaires pour la manipulation et l'affichage du calendrier
 */

/**
 * Génère le HTML du calendrier pour un mois spécifique
 * 
 * @param int $month Le mois (1-12)
 * @param int $year L'année
 * @param array $events Liste des événements
 * @return string Le HTML du calendrier
 */
function generateCalendarMonth($month, $year, $events = []) {
    // Vérifier les valeurs
    $month = (int)$month;
    $year = (int)$year;
    
    if ($month < 1 || $month > 12) {
        $month = date('n');
    }
    if ($year < 1900 || $year > 2100) {
        $year = date('Y');
    }
    
    // Noms des jours en français
    $day_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    
    // Obtenir le nombre de jours dans le mois
    $num_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    // Obtenir le premier jour du mois (1 = lundi, 7 = dimanche)
    $first_day_timestamp = mktime(0, 0, 0, $month, 1, $year);
    $first_day = date('N', $first_day_timestamp);
    
    // Organiser les événements par jour
    $events_by_day = [];
    foreach ($events as $event) {
        $event_date = new DateTime($event['date_debut']);
        $day = (int)$event_date->format('j');
        
        if ($event_date->format('n') == $month && $event_date->format('Y') == $year) {
            if (!isset($events_by_day[$day])) {
                $events_by_day[$day] = [];
            }
            $events_by_day[$day][] = $event;
        }
    }
    
    // Commencer à construire le HTML
    $html = '<div class="calendar-header">';
    foreach ($day_names as $day) {
        $html .= '<div class="calendar-header-day">' . $day . '</div>';
    }
    $html .= '</div>';
    
    $html .= '<div class="calendar-body">';
    
    // Ajouter des cellules vides pour les jours avant le début du mois
    for ($i = 1; $i < $first_day; $i++) {
        $html .= '<div class="calendar-day empty"></div>';
    }
    
    // Ajouter les jours du mois
    for ($day = 1; $day <= $num_days; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $today_class = ($date == date('Y-m-d')) ? ' today' : '';
        
        $html .= '<div class="calendar-day' . $today_class . '" data-date="' . $date . '">';
        $html .= '<div class="calendar-day-number">' . $day . '</div>';
        
        // Afficher les événements de ce jour
        if (isset($events_by_day[$day]) && count($events_by_day[$day]) > 0) {
            $html .= '<div class="calendar-day-events">';
            foreach ($events_by_day[$day] as $event) {
                $event_time = date('H:i', strtotime($event['date_debut']));
                $event_class = 'event-' . strtolower($event['type_evenement']);
                
                if ($event['statut'] === 'annulé') {
                    $event_class .= ' event-cancelled';
                } elseif ($event['statut'] === 'reporté') {
                    $event_class .= ' event-postponed';
                }
                
                $html .= '<div class="calendar-event ' . $event_class . '" data-event-id="' . $event['id'] . '">';
                $html .= '<span class="event-time">' . $event_time . '</span>';
                $html .= '<a href="details_evenement.php?id=' . $event['id'] . '" class="event-title">';
                $html .= htmlspecialchars($event['titre']);
                $html .= '</a>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Si c'est la fin de la semaine, passer à la ligne suivante
        if (($day + $first_day - 1) % 7 == 0) {
            $html .= '<div class="calendar-row-end"></div>';
        }
    }
    
    // Ajouter des cellules vides pour compléter la dernière semaine
    $last_day = ($num_days + $first_day - 1) % 7;
    if ($last_day > 0) {
        for ($i = $last_day; $i < 7; $i++) {
            $html .= '<div class="calendar-day empty"></div>';
        }
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Génère le HTML de la vue jour pour une date spécifique
 * 
 * @param string $date La date au format YYYY-MM-DD
 * @param array $events Liste des événements
 * @return string Le HTML de la vue jour
 */
function generateDayView($date, $events = []) {
    // Vérifier le format de la date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    
    $date_obj = new DateTime($date);
    $formatted_date = $date_obj->format('d/m/Y');
    
    // Filtrer les événements pour cette date
    $events_today = array_filter($events, function($event) use ($date) {
        $event_date = new DateTime($event['date_debut']);
        return $event_date->format('Y-m-d') === $date;
    });
    
    // Trier les événements par heure de début
    usort($events_today, function($a, $b) {
        return strtotime($a['date_debut']) - strtotime($b['date_debut']);
    });
    
    // Commencer à construire le HTML
    $html = '<div class="agenda-jour-date">' . $formatted_date . '</div>';
    
    if (count($events_today) > 0) {
        $html .= '<div class="agenda-jour-events">';
        
        foreach ($events_today as $event) {
            $debut = new DateTime($event['date_debut']);
            $fin = new DateTime($event['date_fin']);
            
            $event_class = 'event-' . strtolower($event['type_evenement']);
            
            if ($event['statut'] === 'annulé') {
                $event_class .= ' event-cancelled';
            } elseif ($event['statut'] === 'reporté') {
                $event_class .= ' event-postponed';
            }
            
            $html .= '<div class="agenda-jour-event ' . $event_class . '">';
            $html .= '<div class="event-time">' . $debut->format('H:i') . ' - ' . $fin->format('H:i') . '</div>';
            $html .= '<div class="event-title"><a href="details_evenement.php?id=' . $event['id'] . '">' . htmlspecialchars($event['titre']) . '</a></div>';
            
            if (!empty($event['lieu'])) {
                $html .= '<div class="event-location">Lieu: ' . htmlspecialchars($event['lieu']) . '</div>';
            }
            
            $html .= '<div class="event-creator">Par: ' . htmlspecialchars($event['createur']) . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
    } else {
        $html .= '<div class="no-events">Aucun événement prévu pour cette journée.</div>';
    }
    
    return $html;
}

/**
 * Génère le HTML de la vue semaine pour une date spécifique
 * 
 * @param string $date Une date dans la semaine au format YYYY-MM-DD
 * @param array $events Liste des événements
 * @return string Le HTML de la vue semaine
 */
function generateWeekView($date, $events = []) {
    // Vérifier le format de la date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    
    $date_obj = new DateTime($date);
    
    // Obtenir le premier jour de la semaine (lundi)
    $day_of_week = $date_obj->format('N');
    $date_obj->modify('-' . ($day_of_week - 1) . ' days');
    
    $start_of_week = $date_obj->format('Y-m-d');
    
    // Tableau des jours de la semaine
    $weekdays = [];
    $weekday_names = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    
    for ($i = 0; $i < 7; $i++) {
        $day = clone $date_obj;
        $day->modify('+' . $i . ' days');
        $weekdays[] = [
            'date' => $day->format('Y-m-d'),
            'display' => $day->format('d/m'),
            'name' => $weekday_names[$i],
            'is_today' => ($day->format('Y-m-d') === date('Y-m-d'))
        ];
    }
    
    // Fin de la semaine pour filtrer les événements
    $date_obj->modify('+6 days');
    $end_of_week = $date_obj->format('Y-m-d');
    
    // Filtrer les événements pour cette semaine
    $events_week = array_filter($events, function($event) use ($start_of_week, $end_of_week) {
        $event_date = new DateTime($event['date_debut']);
        $event_date_str = $event_date->format('Y-m-d');
        return $event_date_str >= $start_of_week && $event_date_str <= $end_of_week;
    });
    
    // Organiser les événements par jour
    $events_by_day = [];
    foreach ($events_week as $event) {
        $event_date = new DateTime($event['date_debut']);
        $day = $event_date->format('Y-m-d');
        
        if (!isset($events_by_day[$day])) {
            $events_by_day[$day] = [];
        }
        $events_by_day[$day][] = $event;
    }
    
    // Trier les événements par heure de début pour chaque jour
    foreach ($events_by_day as $day => $day_events) {
        usort($events_by_day[$day], function($a, $b) {
            return strtotime($a['date_debut']) - strtotime($b['date_debut']);
        });
    }
    
    // Commencer à construire le HTML
    $html = '<div class="week-header">';
    foreach ($weekdays as $day) {
        $today_class = $day['is_today'] ? ' today' : '';
        $html .= '<div class="week-day' . $today_class . '">';
        $html .= '<div class="week-day-name">' . $day['name'] . '</div>';
        $html .= '<div class="week-day-date">' . $day['display'] . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    
    $html .= '<div class="week-body">';
    
    foreach ($weekdays as $day) {
        $day_date = $day['date'];
        $today_class = $day['is_today'] ? ' today' : '';
        
        $html .= '<div class="week-column' . $today_class . '" data-date="' . $day_date . '">';
        
        if (isset($events_by_day[$day_date]) && count($events_by_day[$day_date]) > 0) {
            foreach ($events_by_day[$day_date] as $event) {
                $debut = new DateTime($event['date_debut']);
                $event_class = 'event-' . strtolower($event['type_evenement']);
                
                if ($event['statut'] === 'annulé') {
                    $event_class .= ' event-cancelled';
                } elseif ($event['statut'] === 'reporté') {
                    $event_class .= ' event-postponed';
                }
                
                $html .= '<div class="week-event ' . $event_class . '" data-event-id="' . $event['id'] . '">';
                $html .= '<div class="event-time">' . $debut->format('H:i') . '</div>';
                $html .= '<a href="details_evenement.php?id=' . $event['id'] . '" class="event-title">';
                $html .= htmlspecialchars($event['titre']);
                $html .= '</a>';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Génère le HTML de la vue liste des événements
 * 
 * @param array $events Liste des événements
 * @param int $limit Nombre maximum d'événements à afficher (0 = sans limite)
 * @param string $filter_type Type d'événement à filtrer (vide = tous)
 * @return string Le HTML de la vue liste
 */
function generateEventsList($events, $limit = 0, $filter_type = '') {
    // Filtrer par type si nécessaire
    if (!empty($filter_type)) {
        $events = array_filter($events, function($event) use ($filter_type) {
            return $event['type_evenement'] === $filter_type;
        });
    }
    
    // Trier les événements par date (les plus récents d'abord)
    usort($events, function($a, $b) {
        return strtotime($a['date_debut']) - strtotime($b['date_debut']);
    });
    
    // Limiter le nombre d'événements si nécessaire
    if ($limit > 0 && count($events) > $limit) {
        $events = array_slice($events, 0, $limit);
    }
    
    // Commencer à construire le HTML
    $html = '<div class="event-list">';
    
    if (count($events) > 0) {
        foreach ($events as $event) {
            $debut = new DateTime($event['date_debut']);
            $fin = new DateTime($event['date_fin']);
            
            $event_class = 'event-' . strtolower($event['type_evenement']);
            
            if ($event['statut'] === 'annulé') {
                $event_class .= ' event-cancelled';
            } elseif ($event['statut'] === 'reporté') {
                $event_class .= ' event-postponed';
            }
            
            $html .= '<div class="event-list-item ' . $event_class . '">';
            
            // Date et heure
            $html .= '<div class="event-list-date">';
            if ($debut->format('Y-m-d') === $fin->format('Y-m-d')) {
                $html .= '<div>' . $debut->format('d/m/Y') . '</div>';
                $html .= '<div>' . $debut->format('H:i') . ' - ' . $fin->format('H:i') . '</div>';
            } else {
                $html .= '<div>Du ' . $debut->format('d/m/Y H:i') . '</div>';
                $html .= '<div>au ' . $fin->format('d/m/Y H:i') . '</div>';
            }
            $html .= '</div>';
            
            // Titre et détails
            $html .= '<div class="event-list-details">';
            $html .= '<div class="event-list-title">' . htmlspecialchars($event['titre']) . '</div>';
            
            if (!empty($event['lieu'])) {
                $html .= '<div class="event-list-location">Lieu: ' . htmlspecialchars($event['lieu']) . '</div>';
            }
            
            $html .= '<div class="event-list-creator">Par: ' . htmlspecialchars($event['createur']) . '</div>';
            $html .= '</div>';
            
            // Actions
            $html .= '<div class="event-list-actions">';
            $html .= '<a href="details_evenement.php?id=' . $event['id'] . '" class="button">Voir</a>';
            
            // Si l'utilisateur a les permissions
            if (canEditEvent($event)) {
                $html .= ' <a href="modifier_evenement.php?id=' . $event['id'] . '" class="button">Modifier</a>';
            }
            
            if (canDeleteEvent($event)) {
                $html .= ' <a href="supprimer_evenement.php?id=' . $event['id'] . '" class="button button-secondary" onclick="return confirm(\'Êtes-vous sûr de vouloir supprimer cet événement ?\');">Supprimer</a>';
            }
            
            $html .= '</div>';
            $html .= '</div>';
        }
    } else {
        $html .= '<div class="no-events">Aucun événement trouvé.</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Génère les options de navigation du calendrier (mois précédent/suivant, etc.)
 * 
 * @param int $month Le mois actuel (1-12)
 * @param int $year L'année actuelle
 * @return string Le HTML des options de navigation
 */
function generateCalendarNavigation($month, $year) {
    // Calculer le mois et l'année précédents
    $prev_month = $month - 1;
    $prev_year = $year;
    if ($prev_month < 1) {
        $prev_month = 12;
        $prev_year--;
    }
    
    // Calculer le mois et l'année suivants
    $next_month = $month + 1;
    $next_year = $year;
    if ($next_month > 12) {
        $next_month = 1;
        $next_year++;
    }
    
    // Noms des mois en français
    $month_names = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    
    $html = '<div class="calendar-navigation">';
    $html .= '<a href="?month=' . $prev_month . '&year=' . $prev_year . '" class="button button-secondary">&lt; Mois précédent</a>';
    $html .= '<h2>' . $month_names[$month] . ' ' . $year . '</h2>';
    $html .= '<a href="?month=' . $next_month . '&year=' . $next_year . '" class="button button-secondary">Mois suivant &gt;</a>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Récupère les événements pour un utilisateur en fonction de ses permissions
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param array $user Données de l'utilisateur courant
 * @param string $date_debut Date de début (format YYYY-MM-DD, optionnel)
 * @param string $date_fin Date de fin (format YYYY-MM-DD, optionnel)
 * @return array Liste des événements
 */
function getUserEvents($pdo, $user, $date_debut = null, $date_fin = null) {
    $user_fullname = $user['prenom'] . ' ' . $user['nom'];
    $user_role = $user['profil'];
    
    // Construire la requête de base
    $sql = "SELECT * FROM evenements WHERE 1=1";
    $params = [];
    
    // Ajouter les filtres de date si nécessaire
    if ($date_debut) {
        $sql .= " AND date_debut >= ?";
        $params[] = $date_debut . ' 00:00:00';
    }
    
    if ($date_fin) {
        $sql .= " AND date_debut <= ?";
        $params[] = $date_fin . ' 23:59:59';
    }
    
    // Filtrer selon le rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // Récupérer la classe de l'élève (à implémenter)
        
        $sql .= " AND (visibilite = 'public' 
                OR visibilite = 'eleves' 
                OR visibilite LIKE ? 
                OR createur = ?)";
        
        $params[] = "%$classe%";
        $params[] = $user_fullname;
        
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics/professeurs
        $sql .= " AND (visibilite = 'public' 
                OR visibilite = 'professeurs' 
                OR visibilite LIKE '%professeurs%'
                OR createur = ?)";
        
        $params[] = $user_fullname;
        
    } elseif ($user_role === 'parent') {
        // Pour un parent, récupérer les événements de ses enfants et les événements publics
        // (à adapter selon votre modèle de données pour les relations parent-enfant)
        $sql .= " AND (visibilite = 'public' OR visibilite = 'parents')";
    }
    // Pour les administrateurs, vie scolaire et autres rôles, montrer tous les événements (pas de filtre)
    
    // Trier par date de début
    $sql .= " ORDER BY date_debut";
    
    // Exécuter la requête
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>