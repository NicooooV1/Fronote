<?php
// Fichier inclus depuis absences.php
// Les variables $absences, $date_debut, $date_fin, etc. sont déjà définies

// Organiser les absences par date pour l'affichage calendrier
$absences_par_date = [];

foreach ($absences as $absence) {
    $debut_dt = new DateTime($absence['date_debut']);
    $fin_dt = new DateTime($absence['date_fin']);
    
    // Calculer le nombre de jours entre le début et la fin
    $interval = $debut_dt->diff($fin_dt);
    $nombre_jours = $interval->days;
    
    // Si c'est moins d'un jour
    if ($nombre_jours == 0) {
        $date_key = $debut_dt->format('Y-m-d');
        if (!isset($absences_par_date[$date_key])) {
            $absences_par_date[$date_key] = [];
        }
        $absences_par_date[$date_key][] = $absence;
    } else {
        // Pour les absences sur plusieurs jours, créer une entrée pour chaque jour
        $current_dt = clone $debut_dt;
        while ($current_dt <= $fin_dt) {
            $date_key = $current_dt->format('Y-m-d');
            if (!isset($absences_par_date[$date_key])) {
                $absences_par_date[$date_key] = [];
            }
            $absences_par_date[$date_key][] = $absence;
            $current_dt->modify('+1 day');
        }
    }
}

// Déterminer la plage de dates à afficher
$debut_calendar = new DateTime($date_debut);
$fin_calendar = new DateTime($date_fin);

// Ajuster pour afficher des semaines complètes
$debut_calendar->modify('this week monday');
$fin_calendar->modify('this week sunday');
if ($fin_calendar < new DateTime($date_fin)) {
    $fin_calendar->modify('+1 week sunday');
}

// Créer un tableau de semaines
$semaines = [];
$current_week = [];
$current_day = clone $debut_calendar;

while ($current_day <= $fin_calendar) {
    $date_key = $current_day->format('Y-m-d');
    $day_absences = isset($absences_par_date[$date_key]) ? $absences_par_date[$date_key] : [];
    
    $current_week[] = [
        'date' => clone $current_day,
        'absences' => $day_absences,
        'in_range' => $current_day >= new DateTime($date_debut) && $current_day <= new DateTime($date_fin)
    ];
    
    $current_day->modify('+1 day');
    
    // Nouvelle semaine le lundi
    if ($current_day->format('N') == 1) {
        $semaines[] = $current_week;
        $current_week = [];
    }
}

// Ajouter la dernière semaine si elle n'est pas vide
if (!empty($current_week)) {
    $semaines[] = $current_week;
}

// Noms des jours de la semaine
$jours_semaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
?>

<div class="calendar-container">
  <!-- En-tête du calendrier -->
  <div class="calendar-header">
    <div class="calendar-navigation">
      <a href="?view=calendar&date_debut=<?= date('Y-m-d', strtotime($date_debut . ' -1 month')) ?>&date_fin=<?= date('Y-m-d', strtotime($date_fin . ' -1 month')) ?>&classe=<?= urlencode($classe) ?>&justifie=<?= $justifie ?>" class="calendar-nav-btn">
        <i class="fas fa-chevron-left"></i> Mois précédent
      </a>
      <h2><?= strftime('%B %Y', strtotime($date_debut)) ?></h2>
      <a href="?view=calendar&date_debut=<?= date('Y-m-d', strtotime($date_debut . ' +1 month')) ?>&date_fin=<?= date('Y-m-d', strtotime($date_fin . ' +1 month')) ?>&classe=<?= urlencode($classe) ?>&justifie=<?= $justifie ?>" class="calendar-nav-btn">
        Mois suivant <i class="fas fa-chevron-right"></i>
      </a>
    </div>
    
    <div class="calendar-day-headers">
      <?php foreach ($jours_semaine as $jour): ?>
        <div class="calendar-day-header"><?= $jour ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  
  <!-- Corps du calendrier -->
  <div class="calendar-body">
    <?php foreach ($semaines as $semaine): ?>
      <div class="calendar-week">
        <?php foreach ($semaine as $jour): ?>
          <?php 
          $is_today = $jour['date']->format('Y-m-d') === date('Y-m-d');
          $is_weekend = in_array($jour['date']->format('N'), [6, 7]);
          $has_absences = !empty($jour['absences']);
          ?>
          <div class="calendar-day <?= $is_weekend ? 'weekend' : '' ?> <?= $is_today ? 'today' : '' ?> <?= $jour['in_range'] ? '' : 'out-of-range' ?> <?= $has_absences ? 'has-absences' : '' ?>">
            <div class="calendar-day-number"><?= $jour['date']->format('d') ?></div>
            
            <?php if ($has_absences): ?>
              <div class="calendar-absences">
                <?php foreach ($jour['absences'] as $index => $absence): ?>
                  <?php if ($index < 3): ?>
                    <div class="calendar-absence-item <?= $absence['justifie'] ? 'justified' : 'not-justified' ?>" 
                         title="<?= htmlspecialchars($absence['prenom'] . ' ' . $absence['nom'] . ' - ' . (new DateTime($absence['date_debut']))->format('H:i') . ' à ' . (new DateTime($absence['date_fin']))->format('H:i')) ?>">
                      <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                        <?= htmlspecialchars(substr($absence['prenom'], 0, 1) . '. ' . $absence['nom']) ?>
                      <?php else: ?>
                        <?= (new DateTime($absence['date_debut']))->format('H:i') ?> - <?= (new DateTime($absence['date_fin']))->format('H:i') ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
                
                <?php if (count($jour['absences']) > 3): ?>
                  <div class="calendar-more-absences">+<?= count($jour['absences']) - 3 ?> autres</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            
            <?php if (canManageAbsences() && $jour['in_range']): ?>
              <a href="ajouter_absence.php?date=<?= $jour['date']->format('Y-m-d') ?>" class="calendar-add-absence" title="Ajouter une absence">
                <i class="fas fa-plus"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<style>
  .calendar-container {
    background-color: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  }
  
  .calendar-header {
    padding: 15px;
    background-color: #f9f9f9;
    border-bottom: 1px solid #eee;
  }
  
  .calendar-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
  }
  
  .calendar-navigation h2 {
    margin: 0;
    font-size: 1.2rem;
    color: #333;
    text-transform: capitalize;
  }
  
  .calendar-nav-btn {
    padding: 5px 10px;
    background-color: #f1f3f4;
    border-radius: 4px;
    color: #444;
    text-decoration: none;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
  }
  
  .calendar-nav-btn:hover {
    background-color: #e5e7e9;
  }
  
  .calendar-day-headers {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
  }
  
  .calendar-day-header {
    text-align: center;
    padding: 10px;
    font-weight: 500;
    color: #444;
    font-size: 0.9rem;
  }
  
  .calendar-body {
    padding: 1px;
  }
  
  .calendar-week {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    margin-bottom: 1px;
  }
  
  .calendar-day {
    min-height: 100px;
    background-color: #f9f9f9;
    padding: 10px;
    position: relative;
  }
  
  .calendar-day.weekend {
    background-color: #f5f5f5;
  }
  
  .calendar-day.today {
    background-color: #e6f3ef;
  }
  
  .calendar-day.out-of-range {
    opacity: 0.5;
  }
  
  .calendar-day.has-absences {
    background-color: #fdf5f5;
  }
  
  .calendar-day-number {
    font-size: 0.9rem;
    font-weight: 500;
    color: #444;
    margin-bottom: 10px;
  }
  
  .today .calendar-day-number {
    background-color: #009b72;
    color: white;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .calendar-absences {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  
  .calendar-absence-item {
    background-color: #fadbd8;
    border-left: 3px solid #e74c3c;
    padding: 5px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  
  .calendar-absence-item.justified {
    background-color: #e0f2e9;
    border-left-color: #00843d;
  }
  
  .calendar-more-absences {
    text-align: center;
    font-size: 0.8rem;
    color: #666;
    padding: 5px;
  }
  
  .calendar-add-absence {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background-color: #009b72;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 0.8rem;
    opacity: 0.7;
  }
  
  .calendar-day:hover .calendar-add-absence {
    opacity: 1;
  }
  
  @media (max-width: 992px) {
    .calendar-day {
      min-height: 80px;
    }
    
    .calendar-navigation h2 {
      font-size: 1rem;
    }
    
    .calendar-nav-btn {
      font-size: 0.8rem;
    }
  }
  
  @media (max-width: 768px) {
    .calendar-day-headers {
      display: none;
    }
    
    .calendar-week {
      display: block;
      margin-bottom: 10px;
    }
    
    .calendar-day {
      margin-bottom: 1px;
      display: flex;
      flex-direction: column;
      min-height: auto;
    }
    
    .calendar-day-number:before {
      content: attr(data-day);
      margin-right: 5px;
    }
  }
</style>

<script>
  // Fonction pour afficher un popup avec les détails des absences pour une journée
  document.querySelectorAll('.calendar-more-absences').forEach(function(element) {
    element.addEventListener('click', function() {
      // Ici, vous pourriez implémenter un popup qui affiche toutes les absences
      alert('Fonctionnalité à implémenter : afficher toutes les absences pour cette journée');
    });
  });
</script>