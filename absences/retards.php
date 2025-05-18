<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/functions.php';

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
$justifie = isset($_GET['justifie']) ? $_GET['justifie'] : '';

// Vérifier si la table retards existe
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'retards'");
    if ($tableCheck->rowCount() == 0) {
        // Créer la table si elle n'existe pas
        createRetardsTableIfNotExists($pdo);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification de la table retards: " . $e->getMessage());
}

// Récupérer la liste des retards selon le rôle de l'utilisateur
$retards = [];

if (isAdmin() || isVieScolaire()) {
    // Administrateurs et vie scolaire voient tous les retards
    if (!empty($classe)) {
        $retards = getRetardsClasse($pdo, $classe, $date_debut, $date_fin);
    } else {
        // Requête pour tous les retards
        $sql = "SELECT r.*, e.nom, e.prenom, e.classe 
                FROM retards r 
                JOIN eleves e ON r.id_eleve = e.id 
                WHERE ((r.date_retard BETWEEN ? AND ?) OR 
                       (DATE(r.date_retard) BETWEEN ? AND ?))";
                
        if ($justifie !== '') {
            $sql .= "AND r.justifie = ? ";
            $params = [$date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui'];
        } else {
            $params = [$date_debut, $date_fin, $date_debut, $date_fin];
        }
        
        $sql .= "ORDER BY r.date_retard DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $retards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif (isTeacher()) {
    // Fix the query to get the classes taught by the teacher
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.nom_classe as classe
        FROM professeur_classes c
        WHERE c.id_professeur = ?
    ");
    $stmt->execute([$user['id']]);
    $prof_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If no classes found for this professor, use an empty array to avoid SQL errors
    if (empty($prof_classes)) {
        $prof_classes = [];
    }
    
    if (!empty($classe) && in_array($classe, $prof_classes)) {
        $retards = getRetardsClasse($pdo, $classe, $date_debut, $date_fin);
    } else {
        // Tous les retards des classes du professeur
        $placeholders = implode(',', array_fill(0, count($prof_classes), '?'));
        $sql = "SELECT r.*, e.nom, e.prenom, e.classe 
                FROM retards r 
                JOIN eleves e ON r.id_eleve = e.id 
                WHERE e.classe IN ($placeholders) 
                AND ((r.date_retard BETWEEN ? AND ?) OR 
                     (DATE(r.date_retard) BETWEEN ? AND ?))";
                
        if ($justifie !== '') {
            $sql .= "AND r.justifie = ? ";
            $params = array_merge($prof_classes, [$date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui']);
        } else {
            $params = array_merge($prof_classes, [$date_debut, $date_fin, $date_debut, $date_fin]);
        }
        
        $sql .= "ORDER BY e.classe, e.nom, e.prenom, r.date_retard DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $retards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif (isStudent()) {
    // Élèves voient leurs propres retards
    $retards = getRetardsEleve($pdo, $user['id'], $date_debut, $date_fin);
} elseif (isParent()) {
    // Parents voient les retards de leurs enfants
    $stmt = $pdo->prepare("SELECT id_eleve FROM parents_eleves WHERE id_parent = ?");
    $stmt->execute([$user['id']]);
    $enfants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($enfants)) {
        $placeholders = implode(',', array_fill(0, count($enfants), '?'));
        $sql = "SELECT r.*, e.nom, e.prenom, e.classe 
                FROM retards r 
                JOIN eleves e ON r.id_eleve = e.id 
                WHERE r.id_eleve IN ($placeholders) 
                AND ((r.date_retard BETWEEN ? AND ?) OR 
                     (DATE(r.date_retard) BETWEEN ? AND ?))";
                
        if ($justifie !== '') {
            $sql .= "AND r.justifie = ? ";
            $params = array_merge($enfants, [$date_debut, $date_fin, $date_debut, $date_fin, $justifie === 'oui']);
        } else {
            $params = array_merge($enfants, [$date_debut, $date_fin, $date_debut, $date_fin]);
        }
        
        $sql .= "ORDER BY e.nom, e.prenom, r.date_retard DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $retards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
  <title>Gestion des retards - Pronote</title>
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
        <div class="app-title">Pronote Retards</div>
      </a>
      
      <!-- Filtres -->
      <div class="sidebar-section">
        <form id="filters-form" method="get" action="retards.php">
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
              <option value="oui" <?= $justifie == 'oui' ? 'selected' : '' ?>>Justifiés</option>
              <option value="non" <?= $justifie == 'non' ? 'selected' : '' ?>>Non justifiés</option>
            </select>
          </div>
          
          <button type="submit" class="filter-button">Appliquer les filtres</button>
        </form>
      </div>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <?php if (canManageAbsences()): ?>
        <a href="ajouter_retard.php" class="action-button">
          <i class="fas fa-plus"></i> Ajouter un retard
        </a>
        <?php endif; ?>
        
        <a href="absences.php" class="action-button secondary">
          <i class="fas fa-calendar"></i> Voir les absences
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
          <h1>Gestion des retards</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <?php if (empty($retards)): ?>
          <div class="no-data-message">
            <i class="fas fa-info-circle"></i>
            <p>Aucun retard ne correspond aux critères sélectionnés.</p>
          </div>
        <?php else: ?>
          <!-- Liste des retards -->
          <div class="absences-list">
            <div class="list-header">
              <div class="list-row header-row">
                <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                  <div class="list-cell header-cell">Élève</div>
                  <div class="list-cell header-cell">Classe</div>
                <?php endif; ?>
                <div class="list-cell header-cell">Date</div>
                <div class="list-cell header-cell">Durée</div>
                <div class="list-cell header-cell">Motif</div>
                <div class="list-cell header-cell">Justifié</div>
                <div class="list-cell header-cell">Actions</div>
              </div>
            </div>
            
            <div class="list-body">
              <?php foreach ($retards as $retard): ?>
                <div class="list-row retard-row <?= $retard['justifie'] ? 'justified' : 'not-justified' ?>">
                  <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                    <div class="list-cell">
                      <?= htmlspecialchars($retard['nom'] . ' ' . $retard['prenom']) ?>
                    </div>
                    <div class="list-cell">
                      <?= htmlspecialchars($retard['classe']) ?>
                    </div>
                  <?php endif; ?>
                  <div class="list-cell">
                    <?= (new DateTime($retard['date']))->format('d/m/Y H:i') ?>
                  </div>
                  <div class="list-cell">
                    <?= $retard['duree'] ?> min
                  </div>
                  <div class="list-cell">
                    <?= !empty($retard['motif']) ? htmlspecialchars($retard['motif']) : '<em>Non spécifié</em>' ?>
                  </div>
                  <div class="list-cell">
                    <?php if ($retard['justifie']): ?>
                      <span class="badge badge-success">Oui</span>
                    <?php else: ?>
                      <span class="badge badge-danger">Non</span>
                    <?php endif; ?>
                  </div>
                  <div class="list-cell">
                    <div class="action-buttons">
                      <a href="details_retard.php?id=<?= $retard['id'] ?>" class="btn-icon" title="Voir les détails">
                        <i class="fas fa-eye"></i>
                      </a>
                      <?php if (canManageAbsences()): ?>
                        <a href="modifier_retard.php?id=<?= $retard['id'] ?>" class="btn-icon" title="Modifier">
                          <i class="fas fa-edit"></i>
                        </a>
                        <a href="supprimer_retard.php?id=<?= $retard['id'] ?>" class="btn-icon btn-danger" title="Supprimer" 
                           onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce retard ?');">
                          <i class="fas fa-trash"></i>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
<?php ob_end_flush(); ?>