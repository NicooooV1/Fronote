<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclure les fichiers nécessaires - s'assurer que db.php est chargé avant functions.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Vérifier que l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: ../login/public/index.php');
    exit;
}

// S'assurer que la connection PDO est disponible
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log("Erreur critique: Connexion PDO non disponible dans absences.php");
    die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur du système.");
}

$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Vérifier si la table absences existe avec protection contre les erreurs
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'absences'");
    $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
    
    if (!$tableExists) {
        // Créer la table si elle n'existe pas
        createAbsencesTableIfNotExists($pdo);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification de la table absences: " . $e->getMessage());
    // Continuer l'exécution, mais préparer un message pour l'utilisateur
    $dbError = "Un problème est survenu avec la base de données. Certaines fonctionnalités peuvent être limitées.";
}

// Définir les filtres par défaut
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d', strtotime('-30 days'));
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d');
$classe = isset($_GET['classe']) ? $_GET['classe'] : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'list';
$justifie = isset($_GET['justifie']) ? $_GET['justifie'] : '';

// Récupérer la liste des absences selon le rôle de l'utilisateur
$absences = [];

// Pour déboguer: journaliser le rôle de l'utilisateur
error_log("absences.php - Rôle de l'utilisateur: " . $user_role);

if (isAdmin() || isVieScolaire()) {
    error_log("absences.php - Utilisateur admin ou vie scolaire détecté");
    if (!empty($classe)) {
        $absences = getAbsencesClasse($pdo, $classe, $date_debut, $date_fin, $justifie);
    } else {
        $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                FROM absences a 
                JOIN eleves e ON a.id_eleve = e.id 
                WHERE (
                    (a.date_debut BETWEEN ? AND ?) OR  /* Commence dans la période */
                    (a.date_fin BETWEEN ? AND ?) OR    /* Finit dans la période */
                    (a.date_debut <= ? AND a.date_fin >= ?) /* Chevauche complètement la période */
                )";
                
        if ($justifie !== '') {
            $sql .= "AND a.justifie = ? ";
            $params = [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui'];
        } else {
            $params = [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin];
        }
        
        $sql .= "ORDER BY a.date_debut DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Journaliser le nombre d'absences récupérées
        error_log("absences.php - Récupération de " . count($absences) . " absences pour admin/vie scolaire");
    }
} elseif (isTeacher()) {
    error_log("absences.php - Utilisateur professeur détecté: ID=" . $user['id']);
    try {
        // Récupérer les classes du professeur
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.nom_classe as classe
            FROM professeur_classes c
            WHERE c.id_professeur = ?
        ");
        $stmt->execute([$user['id']]);
        $prof_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        error_log("absences.php - Classes du professeur: " . implode(", ", $prof_classes));
        
        // Si pas de classes trouvées, utiliser un tableau vide
        if (empty($prof_classes)) {
            $prof_classes = [];
        }
        
        if (!empty($classe) && in_array($classe, $prof_classes)) {
            $absences = getAbsencesClasse($pdo, $classe, $date_debut, $date_fin, $justifie);
        } else if (!empty($prof_classes)) {
            $placeholders = implode(',', array_fill(0, count($prof_classes), '?'));
            $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
                    FROM absences a 
                    JOIN eleves e ON a.id_eleve = e.id 
                    WHERE e.classe IN ($placeholders) 
                    AND (
                        (a.date_debut BETWEEN ? AND ?) OR  /* Commence dans la période */
                        (a.date_fin BETWEEN ? AND ?) OR    /* Finit dans la période */
                        (a.date_debut <= ? AND a.date_fin >= ?) /* Chevauche complètement la période */
                    )";
                
            if ($justifie !== '') {
                $sql .= "AND a.justifie = ? ";
                $params = array_merge($prof_classes, [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui']);
            } else {
                $params = array_merge($prof_classes, [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin]);
            }
            
            $sql .= "ORDER BY e.classe, e.nom, e.prenom, a.date_debut DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("absences.php - Récupération de " . count($absences) . " absences pour professeur");
        }
    } catch (PDOException $e) {
        error_log("absences.php - Erreur dans la requête pour professeur: " . $e->getMessage());
        // Pas d'action corrective, l'application continue avec $absences vide
    }
} elseif (isStudent()) {
    error_log("absences.php - Utilisateur élève détecté: ID=" . $user['id']);
    $absences = getAbsencesEleve($pdo, $user['id'], $date_debut, $date_fin);
    error_log("absences.php - Récupération de " . count($absences) . " absences pour élève");
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
                AND (
                    (a.date_debut BETWEEN ? AND ?) OR  /* Commence dans la période */
                    (a.date_fin BETWEEN ? AND ?) OR    /* Finit dans la période */
                    (a.date_debut <= ? AND a.date_fin >= ?) /* Chevauche complètement la période */
                )";
                
        if ($justifie !== '') {
            $sql .= "AND a.justifie = ? ";
            $params = array_merge($enfants, [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui']);
        } else {
            $params = array_merge($enfants, [$date_debut, $date_fin, $date_debut, $date_fin, $date_debut, $date_fin]);
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
    // Aucune vérification si mkdir réussit
    
    // Écriture de fichiers sans vérification du succès de l'opération
    file_put_contents($views_dir . '/list_view.php', '<?php // Basic list view implementation ?>
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
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Absences - Pronote</title>
  <link rel="stylesheet" href="../notes/assets/css/style.css">
  <link rel="stylesheet" href="assets/css/absences.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Absences</div>
      </div>

      <!-- Filtres par période -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Périodes</div>
        <div class="folder-menu">
          <a href="?periode=semaine" class="<?= ($periode_active == 'semaine' ? 'active' : '') ?>">
            <i class="fas fa-calendar-week"></i> Cette semaine
          </a>
          <a href="?periode=mois" class="<?= ($periode_active == 'mois' ? 'active' : '') ?>">
            <i class="fas fa-calendar-alt"></i> Ce mois
          </a>
          <a href="?periode=trimestre" class="<?= ($periode_active == 'trimestre' ? 'active' : '') ?>">
            <i class="fas fa-calendar"></i> Ce trimestre
          </a>
        </div>
      </div>

      <!-- Filtres par type -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Type d'absences</div>
        <div class="folder-menu">
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" name="type[]" value="non_justifiee" <?= (in_array('non_justifiee', $selected_types) ? 'checked' : '') ?>>
              <span class="filter-label">Non justifiées</span>
            </label>
          </div>
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" name="type[]" value="justifiee" <?= (in_array('justifiee', $selected_types) ? 'checked' : '') ?>>
              <span class="filter-label">Justifiées</span>
            </label>
          </div>
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" name="type[]" value="retard" <?= (in_array('retard', $selected_types) ? 'checked' : '') ?>>
              <span class="filter-label">Retards</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <?php if (canManageAbsences()): ?>
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="ajouter_absence.php" class="create-button">
          <i class="fas fa-plus"></i> Signaler une absence
        </a>
        <a href="appel.php" class="button button-secondary">
          <i class="fas fa-clipboard-list"></i> Faire l'appel
        </a>
      </div>
      <?php endif; ?>

      <!-- Autres modules -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Autres modules</div>
        <div class="folder-menu">
          <a href="../notes/notes.php" class="module-link">
            <i class="fas fa-chart-bar"></i> Notes
          </a>
          <a href="../messagerie/index.php" class="module-link">
            <i class="fas fa-envelope"></i> Messagerie
          </a>
          <a href="../agenda/agenda.php" class="module-link">
            <i class="fas fa-calendar"></i> Agenda
          </a>
          <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
            <i class="fas fa-book"></i> Cahier de textes
          </a>
          <a href="../accueil/accueil.php" class="module-link">
            <i class="fas fa-home"></i> Accueil
          </a>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <h1>Gestion des Absences</h1>
        </div>
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?? '' ?></div>
        </div>
      </div>
      
      <!-- Contenu principal de la page -->
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