<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclure les fichiers nécessaires
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    // Utiliser un chemin absolu pour la redirection
    $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'] ?? null;
if (!$user) {
    // Utiliser un chemin absolu pour la redirection
    $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

$user_role = $user['profil'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Initialiser les variables pour éviter les notices
$order = [];
$order['field'] = isset($_GET['order']) ? $_GET['order'] : 'date_rendu';
$order['direction'] = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$filterClass = isset($_GET['classe']) ? $_GET['classe'] : '';
$filterMatiere = isset($_GET['matiere']) ? $_GET['matiere'] : '';
$filterProfesseur = isset($_GET['professeur']) ? $_GET['professeur'] : '';
$displayMode = isset($_GET['mode']) ? $_GET['mode'] : 'list';

// Charger la liste des devoirs
try {
    // Vérifier si la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'devoirs'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        $tableExists = false;
    }
    
    if (!$tableExists) {
        // Créer la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devoirs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                titre VARCHAR(255) NOT NULL,
                description TEXT,
                classe VARCHAR(50) NOT NULL,
                nom_matiere VARCHAR(100) NOT NULL,
                nom_professeur VARCHAR(100) NOT NULL,
                date_ajout DATE NOT NULL,
                date_rendu DATE NOT NULL,
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    // Construire la requête SQL en fonction des filtres
    $sql = "SELECT * FROM devoirs WHERE 1=1";
    $params = [];
    
    if (!empty($filterClass)) {
        $sql .= " AND classe = ?";
        $params[] = $filterClass;
    }
    
    if (!empty($filterMatiere)) {
        $sql .= " AND nom_matiere = ?";
        $params[] = $filterMatiere;
    }
    
    if (!empty($filterProfesseur)) {
        $sql .= " AND nom_professeur = ?";
        $params[] = $filterProfesseur;
    }
    
    // Tri
    $validFields = ['date_rendu', 'titre', 'nom_matiere', 'classe', 'nom_professeur'];
    $orderField = in_array($order['field'], $validFields) ? $order['field'] : 'date_rendu';
    $orderDir = strtoupper($order['direction']) === 'ASC' ? 'ASC' : 'DESC';
    
    $sql .= " ORDER BY " . $orderField . " " . $orderDir;
    
    // Exécuter la requête
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $devoirs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Journal d'erreurs
    error_log("Erreur dans cahierdetextes.php: " . $e->getMessage());
    $devoirs = [];
}

// Définir les variables pour les filtres de l'interface utilisateur
$classes = [];
$matieres = [];
$professeurs = [];

// Si des devoirs existent, extraire les valeurs uniques pour les filtres
if (!empty($devoirs)) {
    foreach ($devoirs as $devoir) {
        if (!in_array($devoir['classe'], $classes)) {
            $classes[] = $devoir['classe'];
        }
        
        if (!in_array($devoir['nom_matiere'], $matieres)) {
            $matieres[] = $devoir['nom_matiere'];
        }
        
        if (!in_array($devoir['nom_professeur'], $professeurs)) {
            $professeurs[] = $devoir['nom_professeur'];
        }
    }
    
    // Trier les listes pour une meilleure présentation
    sort($classes);
    sort($matieres);
    sort($professeurs);
}

// Fonction pour vérifier l'existence des fonctions d'autorisation
if (!function_exists('canManageDevoirs')) {
    function canManageDevoirs() {
        $role = isset($_SESSION['user']) ? $_SESSION['user']['profil'] : '';
        return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cahier de Textes - Pronote</title>
  <link rel="stylesheet" href="../notes/assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
  <div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Cahier de Textes</div>
      </div>
      
      <!-- Section des filtres -->
      <div class="sidebar-section">
        <div class="sidebar-section-header">Filtres</div>
        <div class="folder-menu">
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" id="filter-semaine">
              <span class="filter-label">À rendre cette semaine</span>
            </label>
          </div>
          
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" id="filter-mois">
              <span class="filter-label">À rendre ce mois</span>
            </label>
          </div>
          
          <?php if (!isStudent() && !isParent()): ?>
          <div class="filter-option">
            <label>
              <input type="checkbox" class="filter-checkbox" id="filter-tous" checked>
              <span class="filter-label">Tous les devoirs</span>
            </label>
          </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Actions -->
      <?php if (canManageDevoirs()): ?>
      <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="ajouter_devoir.php" class="create-button">
          <i class="fas fa-plus"></i> Ajouter un devoir
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
          <a href="../absences/absences.php" class="module-link">
            <i class="fas fa-calendar-times"></i> Absences
          </a>
          <a href="../agenda/agenda.php" class="module-link">
            <i class="fas fa-calendar"></i> Agenda
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
          <h1>Cahier de Textes</h1>
        </div>
        
        <div class="filter-buttons">
          <a href="?order=date_rendu" class="button <?php echo (!isset($_GET['order']) || $_GET['order'] == 'date_rendu') ? 'button-primary' : 'button-secondary'; ?>">
            <i class="fas fa-calendar-day"></i> Par date de rendu
          </a>
          <a href="?order=date_ajout" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'date_ajout') ? 'button-primary' : 'button-secondary'; ?>">
            <i class="fas fa-clock"></i> Par date d'ajout
          </a>
          <a href="?order=matiere" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') ? 'button-primary' : 'button-secondary'; ?>">
            <i class="fas fa-book"></i> Par matière
          </a>
          <?php if (!isStudent() && !isParent()): ?>
          <a href="?order=classe" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'classe') ? 'button-primary' : 'button-secondary'; ?>">
            <i class="fas fa-users"></i> Par classe
          </a>
          <?php endif; ?>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <div class="devoirs-list">
          <?php
          // Récupération des devoirs selon le rôle de l'utilisateur
          if (isStudent()) {
            $stmt_eleve = $pdo->prepare('SELECT classe FROM eleves WHERE prenom = ? AND nom = ?');
            $stmt_eleve->execute([$user['prenom'], $user['nom']]);
            $eleve_data = $stmt_eleve->fetch();
            $classe_eleve = $eleve_data ? $eleve_data['classe'] : '';
            
            if (empty($classe_eleve)) {
              switch ($order) {
                case 'matiere':
                  $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
                  break;
                case 'date_ajout':
                  $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
                  break;
                default:
                  $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
              }
              
              $stmt = $pdo->query($sql);
            } else {
              switch ($order) {
                case 'matiere':
                  $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY nom_matiere ASC, date_rendu ASC';
                  break;
                case 'date_ajout':
                  $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_ajout DESC';
                  break;
                default:
                  $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_rendu ASC';
              }
              
              $stmt = $pdo->prepare($sql);
              $stmt->execute([$classe_eleve]);
            }
          }
          elseif (isParent()) {
            // Future implementation: get children's classes
            switch ($order) {
              case 'matiere':
                $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
                break;
              case 'classe':
                $sql = 'SELECT * FROM devoirs ORDER BY classe ASC, date_rendu ASC';
                break;
              case 'date_ajout':
                $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
                break;
              default:
                $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
            }
            
            $stmt = $pdo->query($sql);
          }
          elseif (isTeacher()) {
            switch ($order) {
              case 'matiere':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY nom_matiere ASC, date_rendu ASC';
                break;
              case 'classe':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY classe ASC, date_rendu ASC';
                break;
              case 'date_ajout':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_ajout DESC';
                break;
              default:
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_rendu ASC';
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_fullname]);
          }
          else {
            switch ($order) {
              case 'matiere':
                $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ASC, date_rendu ASC';
                break;
              case 'classe':
                $sql = 'SELECT * FROM devoirs ORDER BY classe ASC, date_rendu ASC';
                break;
              case 'date_ajout':
                $sql = 'SELECT * FROM devoirs ORDER BY date_ajout DESC';
                break;
              default:
                $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ASC';
            }
            
            $stmt = $pdo->query($sql);
          }
          
          // Afficher les devoirs
          if ($stmt->rowCount() === 0) {
            echo '<div class="alert alert-info">
              <i class="fas fa-info-circle"></i>
              <div>Aucun devoir n\'a été ajouté pour le moment.</div>
            </div>';
          } else {
            // Afficher les devoirs
            while ($devoir = $stmt->fetch()) {
              // Vérifier si le devoir est proche de la date de rendu (dans 3 jours ou moins)
              $date_rendu = new DateTime($devoir['date_rendu']);
              $aujourdhui = new DateTime();
              $diff = $aujourdhui->diff($date_rendu);
              $urgent = $date_rendu >= $aujourdhui && $diff->days <= 3;
              $expire = $date_rendu < $aujourdhui;
              
              echo '<div class="devoir-item' . ($urgent ? ' urgent' : '') . ($expire ? ' expired' : '') . '">
                <div class="devoir-header">
                  <div class="devoir-title">' . htmlspecialchars($devoir['titre']) . '</div>
                  <div class="devoir-date">Ajouté le: ' . date('d/m/Y', strtotime($devoir['date_ajout'])) . '</div>
                </div>
                
                <div class="devoir-details">
                  <div class="devoir-detail">
                    <div>Classe:</div>
                    <div class="devoir-value">' . htmlspecialchars($devoir['classe']) . '</div>
                  </div>
                  
                  <div class="devoir-detail">
                    <div>Matière:</div>
                    <div class="devoir-value">' . htmlspecialchars($devoir['nom_matiere']) . '</div>
                  </div>
                  
                  <div class="devoir-detail">
                    <div>Professeur:</div>
                    <div class="devoir-value">' . htmlspecialchars($devoir['nom_professeur']) . '</div>
                  </div>
                  
                  <div class="devoir-detail">
                    <div>À rendre pour le:</div>
                    <div class="devoir-value" style="color: ' . ($urgent ? '#e74c3c' : ($expire ? '#777' : '#d33')) . '; font-weight: bold;">
                      ' . date('d/m/Y', strtotime($devoir['date_rendu'])) . '
                      ' . ($urgent ? '<span class="badge badge-danger">Urgent</span>' : '') . '
                      ' . ($expire ? '<span class="badge badge-secondary">Expiré</span>' : '') . '
                    </div>
                  </div>
                </div>
                
                <div class="devoir-description">
                  <h4>Description:</h4>
                  <p>' . nl2br(htmlspecialchars($devoir['description'])) . '</p>
                </div>';
                
                // Afficher les boutons de modification/suppression pour les utilisateurs autorisés
                if (canManageDevoirs()) {
                  // Si c'est un professeur, vérifier qu'il est bien l'auteur du devoir
                  if (!isTeacher() || (isTeacher() && $devoir['nom_professeur'] == $user_fullname)) {
                    echo '<div class="devoir-actions">
                      <a href="modifier_devoir.php?id=' . $devoir['id'] . '" class="button button-secondary">
                        <i class="fas fa-edit"></i> Modifier
                      </a>
                      <a href="supprimer_devoir.php?id=' . $devoir['id'] . '" class="button button-danger" 
                         onclick="return confirm(\'Êtes-vous sûr de vouloir supprimer ce devoir ?\');">
                        <i class="fas fa-trash"></i> Supprimer
                      </a>
                    </div>';
                  }
                }
                
              echo '</div>';
            }
          }
          ?>
        </div>
      </div>
    </div>
  </div>
  
  <script>
    // Filtrage des devoirs par semaine/mois
    document.addEventListener('DOMContentLoaded', function() {
      const filterSemaine = document.getElementById('filter-semaine');
      const filterMois = document.getElementById('filter-mois');
      const filterTous = document.getElementById('filter-tous');
      const devoirItems = document.querySelectorAll('.devoir-item');
      
      // Fonction pour appliquer les filtres
      function applyFilters() {
        const today = new Date();
        const endOfWeek = new Date(today);
        endOfWeek.setDate(today.getDate() + (7 - today.getDay()));
        
        const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        
        devoirItems.forEach(item => {
          const dateRenduText = item.querySelector('.devoir-detail:nth-child(4) .devoir-value').textContent.trim().split(' ')[0];
          const dateParts = dateRenduText.split('/');
          const dateRendu = new Date(dateParts[2], dateParts[1] - 1, dateParts[0]);
          
          if (filterSemaine.checked && dateRendu <= endOfWeek && dateRendu >= today) {
            item.style.display = '';
          } else if (filterMois.checked && dateRendu <= endOfMonth && dateRendu >= today) {
            item.style.display = '';
          } else if (filterTous && filterTous.checked) {
            item.style.display = '';
          } else if (!filterSemaine.checked && !filterMois.checked && (!filterTous || !filterTous.checked)) {
            item.style.display = '';
          } else {
            item.style.display = 'none';
          }
        });
      }
      
      // Ajouter les écouteurs d'événements
      if (filterSemaine) filterSemaine.addEventListener('change', applyFilters);
      if (filterMois) filterMois.addEventListener('change', applyFilters);
      if (filterTous) filterTous.addEventListener('change', applyFilters);
      
      // Appliquer les filtres au chargement
      applyFilters();
    });
  </script>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>