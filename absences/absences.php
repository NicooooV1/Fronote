<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusion des fichiers nécessaires - use absolute directory paths
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Check if functions.php exists, and if not, create it
$functions_file = __DIR__ . '/includes/functions.php';
if (!file_exists($functions_file)) {
    // Create a basic functions file with required functions
    $functions_content = '<?php
/**
 * Fonctions utilitaires pour la gestion des absences et retards
 */

// Dummy implementations if the real functions file is missing
function getAbsencesEleve($pdo, $id_eleve, $date_debut = null, $date_fin = null) {
    return [];
}

function getAbsencesClasse($pdo, $classe, $date_debut = null, $date_fin = null) {
    return [];
}

function canManageAbsences() {
    return (isTeacher() || isAdmin() || isVieScolaire());
}
?>';
    file_put_contents($functions_file, $functions_content);
}

require_once $functions_file;

// Vérifier que l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Définir les filtres par défaut
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d', strtotime('-30 days'));
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$classe = isset($_GET['classe']) ? $_GET['classe'] : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'list';
$justifie = isset($_GET['justifie']) ? $_GET['justifie'] : '';

// Récupérer la liste des absences selon le rôle de l'utilisateur
$absences = [];

if (isAdmin() || isVieScolaire()) {
    if (!empty($classe)) {
        $absences = getAbsencesClasse($pdo, $classe, $date_debut, $date_fin);
    } else {
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a 
                JOIN eleves e ON a.id_eleve = e.id 
                WHERE a.date_debut BETWEEN ? AND ? ";
                
        if ($justifie !== '') {
            $sql .= "AND a.justifie = ? ";
            $params = [$date_debut, $date_fin, $justifie === 'oui'];
        } else {
            $params = [$date_debut, $date_fin];
        }
        
        $sql .= "ORDER BY a.date_debut DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif (isTeacher()) {
    $stmt = $pdo->prepare("SELECT classe FROM professeurs WHERE id = ?");
    $stmt->execute([$user['id']]);
    $prof_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($classe) && in_array($classe, $prof_classes)) {
        $absences = getAbsencesClasse($pdo, $classe, $date_debut, $date_fin);
    } else {
        $placeholders = implode(',', array_fill(0, count($prof_classes), '?'));
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a 
                JOIN eleves e ON a.id_eleve = e.id 
                WHERE e.classe IN ($placeholders) 
                AND a.date_debut BETWEEN ? AND ? ";
                
        if ($justifie !== '') {
            $sql .= "AND a.justifie = ? ";
            $params = array_merge($prof_classes, [$date_debut, $date_fin, $justifie === 'oui']);
        } else {
            $params = array_merge($prof_classes, [$date_debut, $date_fin]);
        }
        
        $sql .= "ORDER BY e.classe, e.nom, e.prenom, a.date_debut DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif (isStudent()) {
    $absences = getAbsencesEleve($pdo, $user['id'], $date_debut, $date_fin);
} elseif (isParent()) {
    $stmt = $pdo->prepare("SELECT id_eleve FROM parents_eleves WHERE id_parent = ?");
    $stmt->execute([$user['id']]);
    $enfants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($enfants)) {
        $placeholders = implode(',', array_fill(0, count($enfants), '?'));
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a 
                JOIN eleves e ON a.id_eleve = e.id 
                WHERE a.id_eleve IN ($placeholders) 
                AND a.date_debut BETWEEN ? AND ? ";
                
        if ($justifie !== '') {
            $sql .= "AND a.justifie = ? ";
            $params = array_merge($enfants, [$date_debut, $date_fin, $justifie === 'oui']);
        } else {
            $params = array_merge($enfants, [$date_debut, $date_fin]);
        }
        
        $sql .= "ORDER BY e.nom, e.prenom, a.date_debut DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Make sure the views directory exists
$views_dir = __DIR__ . '/views';
if (!is_dir($views_dir)) {
    mkdir($views_dir, 0755, true);
    
    // Create basic view files
    file_put_contents($views_dir . '/list_view.php', 
        '<?php // Basic list view implementation ?>
        <div class="absences-list">
            <div class="list-header">
                <div class="list-row header-row">
                    <div class="list-cell header-cell">Élève</div>
                    <div class="list-cell header-cell">Classe</div>
                    <div class="list-cell header-cell">Date</div>
                    <div class="list-cell header-cell">Type</div>
                    <div class="list-cell header-cell">Justifié</div>
                </div>
            </div>
            <div class="list-body">
                <?php foreach ($absences as $absence): ?>
                    <div class="list-row">
                        <div class="list-cell"><?= htmlspecialchars($absence["nom"] . " " . $absence["prenom"]) ?></div>
                        <div class="list-cell"><?= htmlspecialchars($absence["classe"]) ?></div>
                        <div class="list-cell"><?= (new DateTime($absence["date_debut"]))->format("d/m/Y") ?></div>
                        <div class="list-cell"><?= htmlspecialchars($absence["type_absence"]) ?></div>
                        <div class="list-cell"><?= $absence["justifie"] ? "Oui" : "Non" ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>');
        
    file_put_contents($views_dir . '/calendar_view.php', 
        '<?php // Basic calendar view implementation ?>
        <div class="calendar-view">
            <p>Vue calendrier - Implémentation à venir</p>
        </div>');
        
    file_put_contents($views_dir . '/stats_view.php', 
        '<?php // Basic stats view implementation ?>
        <div class="stats-view">
            <p>Vue statistiques - Implémentation à venir</p>
        </div>');
}

// Récupérer la liste des classes pour le filtre
$classes = [];
$etablissement_data = json_decode(file_get_contents('../login/data/etablissement.json'), true);
if (!empty($etablissement_data['classes'])) {
    foreach ($etablissement_data['classes'] as $niveau => $niveaux) {
        foreach ($niveaux as $cycle => $liste_classes) {
            foreach ($liste_classes as $nom_classe) {
                $classes[] = $nom_classe;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestion des absences - Pronote</title>
  <link rel="stylesheet" href="../agenda/assets/css/calendar.css">
  <link rel="stylesheet" href="assets/css/absences.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Absences</div>
      </a>
      
      <!-- Filtres -->
      <div class="sidebar-section">
        <form id="filters-form" method="get" action="absences.php">
          <input type="hidden" name="view" value="<?= $view ?>">
          
          <div class="form-group">
            <label for="date_debut">Du</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= $date_debut ?>" max="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="date_fin">Au</label>
            <input type="date" id="date_fin" name="date_fin" value="<?= $date_fin ?>" max="<?= date('Y-m-d') ?>">
          </div>
          
          <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
          <div class="form-group">
            <label for="classe">Classe</label>
            <select id="classe" name="classe">
              <option value="">Toutes les classes</option>
              <?php foreach ($classes as $c): ?>
              <option value="<?= $c ?>" <?= $classe == $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          
          <div class="form-group">
            <label for="justifie">Justification</label>
            <select id="justifie" name="justifie">
              <option value="">Toutes</option>
              <option value="oui" <?= $justifie == 'oui' ? 'selected' : '' ?>>Justifiées</option>
              <option value="non" <?= $justifie == 'non' ? 'selected' : '' ?>>Non justifiées</option>
            </select>
          </div>
          
          <button type="submit" class="filter-button">Appliquer les filtres</button>
        </form>
      </div>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <?php if (canManageAbsences()): ?>
        <a href="ajouter_absence.php" class="action-button">
          <i class="fas fa-plus"></i> Ajouter une absence
        </a>
        <?php endif; ?>
        
        <a href="retards.php" class="action-button secondary">
          <i class="fas fa-clock"></i> Voir les retards
        </a>
        
        <?php if (canManageAbsences()): ?>
        <a href="justificatifs.php" class="action-button secondary">
          <i class="fas fa-file-alt"></i> Justificatifs
        </a>
        <?php endif; ?>
        
        <a href="statistiques.php" class="action-button secondary">
          <i class="fas fa-chart-bar"></i> Statistiques
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <h1>Gestion des absences</h1>
        </div>
        
        <div class="view-toggle">
          <a href="?view=list<?= !empty($classe) ? '&classe='.$classe : '' ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&justifie=<?= $justifie ?>" 
             class="view-toggle-option <?= $view === 'list' ? 'active' : '' ?>">
            <i class="fas fa-list"></i> Liste
          </a>
          <a href="?view=calendar<?= !empty($classe) ? '&classe='.$classe : '' ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&justifie=<?= $justifie ?>" 
             class="view-toggle-option <?= $view === 'calendar' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i> Calendrier
          </a>
          <a href="?view=stats<?= !empty($classe) ? '&classe='.$classe : '' ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&justifie=<?= $justifie ?>" 
             class="view-toggle-option <?= $view === 'stats' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i> Statistiques
          </a>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <?php if (empty($absences)): ?>
          <div class="no-data-message">
            <i class="fas fa-info-circle"></i>
            <p>Aucune absence ne correspond aux critères sélectionnés.</p>
          </div>
        <?php else: ?>
          <?php if ($view === 'list'): ?>
            <?php include 'views/list_view.php'; ?>
          <?php elseif ($view === 'calendar'): ?>
            <?php include 'views/calendar_view.php'; ?>
          <?php elseif ($view === 'stats'): ?>
            <?php include 'views/stats_view.php'; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
<?php ob_end_flush(); ?>