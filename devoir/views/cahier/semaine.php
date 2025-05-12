<?php
/**
 * Vue pour afficher le cahier de texte en vue semaine
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Vue semaine - Cahier de Texte";
$extraCss = ["cahier.css"];
$extraJs = ["cahier.js"];
$currentPage = "cahier";

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';

// Déterminer la semaine à afficher (par défaut: semaine courante)
$currentWeek = isset($_GET['semaine']) ? $_GET['semaine'] : date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($currentWeek)));
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($currentWeek)));

// Calculer les semaines précédente et suivante
$previousWeek = date('Y-m-d', strtotime('-1 week', strtotime($weekStart)));
$nextWeek = date('Y-m-d', strtotime('+1 week', strtotime($weekStart)));
?>

<div class="page-header">
    <h1>Cahier de Texte</h1>
    
    <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR || $_SESSION['is_admin']): ?>
        <div class="page-actions">
            <a href="<?php echo BASE_URL; ?>/cahier/creer.php" class="btn btn-primary">
                <i class="material-icons">add</i> Créer une séance
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="view-navigation">
    <div class="nav-tabs">
        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">
            <i class="material-icons">calendar_month</i> Calendrier
        </a>
        <a href="<?php echo BASE_URL; ?>/cahier/semaine.php" class="active">
            <i class="material-icons">view_week</i> Vue semaine
        </a>
        <a href="<?php echo BASE_URL; ?>/cahier/chapitres.php">
            <i class="material-icons">book</i> Chapitres
        </a>
    </div>
</div>

<div class="calendar-toolbar">
    <div class="calendar-filters">
        <?php if (!empty($classes)): ?>
            <div class="calendar-filter">
                <label for="classe-filter">Classe:</label>
                <select id="classe-filter" class="form-select">
                    <option value="">Toutes les classes</option>
                    <?php foreach ($classes as $classe): ?>
                        <option value="<?php echo $classe['id']; ?>" <?php echo (isset($_GET['classe_id']) && $_GET['classe_id'] == $classe['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($classe['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($matieres)): ?>
            <div class="calendar-filter">
                <label for="matiere-filter">Matière:</label>
                <select id="matiere-filter" class="form-select">
                    <option value="">Toutes les matières</option>
                    <?php foreach ($matieres as $matiere): ?>
                        <option value="<?php echo $matiere['id']; ?>" <?php echo (isset($_GET['matiere_id']) && $_GET['matiere_id'] == $matiere['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($matiere['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="week-navigation">
    <a href="<?php echo BASE_URL; ?>/cahier/semaine.php?semaine=<?php echo $previousWeek; ?><?php echo isset($_GET['classe_id']) ? '&classe_id='.$_GET['classe_id'] : ''; ?><?php echo isset($_GET['matiere_id']) ? '&matiere_id='.$_GET['matiere_id'] : ''; ?>" class="btn btn-primary">
        <i class="material-icons">chevron_left</i> Semaine précédente
    </a>
    
    <h2 class="week-title">
        Semaine du <?php echo date('d/m/Y', strtotime($weekStart)); ?> au <?php echo date('d/m/Y', strtotime($weekEnd)); ?>
    </h2>
    
    <a href="<?php echo BASE_URL; ?>/cahier/semaine.php?semaine=<?php echo $nextWeek; ?><?php echo isset($_GET['classe_id']) ? '&classe_id='.$_GET['classe_id'] : ''; ?><?php echo isset($_GET['matiere_id']) ? '&matiere_id='.$_GET['matiere_id'] : ''; ?>" class="btn btn-primary">
        Semaine suivante <i class="material-icons">chevron_right</i>
    </a>
</div>

<div class="week-grid">
    <?php
    // Générer les jours de la semaine
    $days = [];
    for ($i = 0; $i < 5; $i++) { // Lundi à vendredi
        $currentDate = date('Y-m-d', strtotime("+$i day", strtotime($weekStart)));
        $days[] = [
            'date' => $currentDate,
            'name' => getJourSemaine(date('w', strtotime($currentDate))),
            'is_today' => (date('Y-m-d') === $currentDate),
            'is_weekend' => in_array(date('w', strtotime($currentDate)), [0, 6]) // 0 = dimanche, 6 = samedi
        ];
    }
    
    // Afficher chaque jour
    foreach ($days as $day):
        $dayClass = 'week-day';
        if ($day['is_today']) $dayClass .= ' today';
        if ($day['is_weekend']) $dayClass .= ' weekend';
    ?>
        <div class="<?php echo $dayClass; ?>">
            <div class="week-day-header">
                <div class="week-day-name"><?php echo $day['name']; ?></div>
                <div class="week-day-date"><?php echo date('d/m/Y', strtotime($day['date'])); ?></div>
            </div>
            
            <div class="week-day-content">
                <?php
                // Récupérer les séances du jour
                $seancesJour = [];
                foreach ($seances as $seance) {
                    if (date('Y-m-d', strtotime($seance['date_debut'])) === $day['date']) {
                        $seancesJour[] = $seance;
                    }
                }
                
                // Trier les séances par heure de début
                usort($seancesJour, function($a, $b) {
                    return strtotime($a['date_debut']) - strtotime($b['date_debut']);
                });
                
                // Afficher les séances du jour
                if (!empty($seancesJour)):
                    foreach ($seancesJour as $seance):
                        $seanceClass = 'seance-item';
                        if ($seance['statut'] === STATUT_REALISEE) $seanceClass .= ' status-realisee';
                        if ($seance['statut'] === STATUT_ANNULEE) $seanceClass .= ' status-annulee';
                        
                        $heureDebut = date('H:i', strtotime($seance['date_debut']));
                        $heureFin = date('H:i', strtotime($seance['date_fin']));
                ?>
                    <div class="<?php echo $seanceClass; ?>" onclick="window.location.href='<?php echo BASE_URL; ?>/cahier/details.php?id=<?php echo $seance['id']; ?>'">
                        <div class="seance-time">
                            <?php echo $heureDebut; ?> - <?php echo $heureFin; ?>
                        </div>
                        <div class="seance-title">
                            <?php echo htmlspecialchars($seance['titre']); ?>
                        </div>
                        <div class="seance-info">
                            <span class="seance-matiere"><?php echo htmlspecialchars($seance['matiere_nom']); ?></span>
                            <span class="seance-classe"><?php echo htmlspecialchars($seance['classe_nom']); ?></span>
                        </div>
                    </div>
                <?php
                    endforeach;
                else:
                ?>
                    <p class="text-muted text-center">Aucune séance ce jour</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// Script JavaScript pour la page
$pageScript = "
    // Gérer les filtres de la vue semaine
    document.getElementById('classe-filter').addEventListener('change', function() {
        filterWeekView();
    });
    
    document.getElementById('matiere-filter').addEventListener('change', function() {
        filterWeekView();
    });
    
    function filterWeekView() {
        var classeId = document.getElementById('classe-filter').value;
        var matiereId = document.getElementById('matiere-filter').value;
        
        var url = '" . BASE_URL . "/cahier/semaine.php?semaine=" . $currentWeek . "';
        
        if (classeId) {
            url += '&classe_id=' + classeId;
        }
        
        if (matiereId) {
            url += '&matiere_id=' + matiereId;
        }
        
        window.location.href = url;
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>