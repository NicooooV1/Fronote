<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclure les fichiers nécessaires
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Vérifier si l'utilisateur est connecté
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

$user_role = $user['profil'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_initials = strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));

// Initialiser les variables pour éviter les notices
$order = [];
$order['field'] = isset($_GET['order']) ? $_GET['order'] : 'date_rendu';
$order['direction'] = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$filterClass = isset($_GET['classe']) ? $_GET['classe'] : '';
$filterMatiere = isset($_GET['matiere']) ? $_GET['matiere'] : '';
$filterProfesseur = isset($_GET['professeur']) ? $_GET['professeur'] : '';
$displayMode = isset($_GET['mode']) ? $_GET['mode'] : 'list';

// Traitement des messages de notification
$notification = '';
$notificationType = '';

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $_SESSION['success_message'] = "Le devoir a été ajouté avec succès.";
            break;
        case 'updated':
            $_SESSION['success_message'] = "Le devoir a été mis à jour avec succès.";
            break;
        case 'deleted':
            $_SESSION['success_message'] = "Le devoir a été supprimé avec succès.";
            break;
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'notfound':
            $_SESSION['error_message'] = "Le devoir demandé n'existe pas.";
            break;
        case 'unauthorized':
            $_SESSION['error_message'] = "Vous n'avez pas les droits nécessaires pour cette action.";
            break;
        case 'dbfailed':
            $_SESSION['error_message'] = "Une erreur est survenue lors de l'opération.";
            break;
    }
}

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

// Définir les variables pour les filtres
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
    
    // Trier les listes
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

// Variables pour le template
$pageTitle = "Cahier de Textes";
$moduleClass = "cahier";
$moduleColor = "var(--accent-cahier)";

// Contenu du header pour les filtres
$headerActions = <<<HTML
<div class="view-toggle">
  <a href="?mode=list" class="view-toggle-option <?= $displayMode !== 'calendar' ? 'active' : '' ?>">
    <i class="fas fa-list"></i> Liste
  </a>
  <a href="?mode=calendar" class="view-toggle-option <?= $displayMode === 'calendar' ? 'active' : '' ?>">
    <i class="fas fa-calendar-alt"></i> Calendrier
  </a>
</div>
HTML;

// Contenu de la sidebar
$sidebarContent = <<<HTML
<div class="sidebar-section">
  <div class="sidebar-title">Filtres</div>
  <div class="sidebar-menu">
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
  </div>
</div>

<div class="sidebar-section">
  <div class="sidebar-title">Actions</div>
  <div class="sidebar-menu">
    <a href="cahierdetextes.php" class="sidebar-link active">
      <i class="fas fa-list"></i> Liste des devoirs
    </a>
    
    <?php if (canManageDevoirs()): ?>
    <a href="ajouter_devoir.php" class="sidebar-link">
      <i class="fas fa-plus"></i> Ajouter un devoir
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="sidebar-section">
  <div class="sidebar-title">Autres modules</div>
  <div class="sidebar-menu">
    <a href="../notes/notes.php" class="sidebar-link">
      <i class="fas fa-chart-bar"></i> Notes
    </a>
    <a href="../absences/absences.php" class="sidebar-link">
      <i class="fas fa-calendar-times"></i> Absences
    </a>
    <a href="../agenda/agenda.php" class="sidebar-link">
      <i class="fas fa-calendar-alt"></i> Agenda
    </a>
    <a href="../messagerie/index.php" class="sidebar-link">
      <i class="fas fa-envelope"></i> Messagerie
    </a>
    <a href="../accueil/accueil.php" class="sidebar-link">
      <i class="fas fa-home"></i> Accueil
    </a>
  </div>
</div>
HTML;

include '../assets/css/templates/header-template.php';

// Récupération des devoirs selon le rôle de l'utilisateur
if (isStudent()) {
  $stmt_eleve = $pdo->prepare('SELECT classe FROM eleves WHERE prenom = ? AND nom = ?');
  $stmt_eleve->execute([$user['prenom'], $user['nom']]);
  $eleve_data = $stmt_eleve->fetch();
  $classe_eleve = $eleve_data ? $eleve_data['classe'] : '';
  
  if (empty($classe_eleve)) {
    switch ($order['field']) {
      case 'nom_matiere':
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
    switch ($order['field']) {
      case 'nom_matiere':
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
  switch ($order['field']) {
    case 'nom_matiere':
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
  switch ($order['field']) {
    case 'nom_matiere':
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
  switch ($order['field']) {
    case 'nom_matiere':
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
?>

<div class="section">
  <div class="filter-toolbar">
    <div class="filter-buttons">
      <a href="?order=date_rendu" class="btn <?= (!isset($_GET['order']) || $_GET['order'] == 'date_rendu') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas fa-calendar-day"></i> Par date de rendu
      </a>
      <a href="?order=date_ajout" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'date_ajout') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas fa-clock"></i> Par date d'ajout
      </a>
      <a href="?order=nom_matiere" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'nom_matiere') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas fa-book"></i> Par matière
      </a>
      <?php if (!isStudent() && !isParent()): ?>
      <a href="?order=classe" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'classe') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas fa-users"></i> Par classe
      </a>
      <?php endif; ?>
    </div>
    
    <?php if (canManageDevoirs()): ?>
    <div>
      <a href="ajouter_devoir.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Ajouter un devoir
      </a>
    </div>
    <?php endif; ?>
  </div>
  
  <div class="devoirs-list">
    <?php if ($stmt->rowCount() === 0): ?>
      <div class="alert-banner alert-info">
        <i class="fas fa-info-circle"></i>
        <div>Aucun devoir n'a été ajouté pour le moment.</div>
      </div>
    <?php else: ?>
      <?php while ($devoir = $stmt->fetch()): ?>
        <?php
        // Vérifier si le devoir est proche de la date de rendu (dans 3 jours ou moins)
        $date_rendu = new DateTime($devoir['date_rendu']);
        $aujourdhui = new DateTime();
        $diff = $aujourdhui->diff($date_rendu);
        $urgent = $date_rendu >= $aujourdhui && $diff->days <= 3;
        $expire = $date_rendu < $aujourdhui;
        ?>
        <div class="card devoir-card <?= $urgent ? 'urgent' : ($expire ? 'expired' : '') ?>">
          <div class="card-header">
            <div class="devoir-title"><?= htmlspecialchars($devoir['titre']) ?></div>
            <div class="devoir-meta">Ajouté le: <?= date('d/m/Y', strtotime($devoir['date_ajout'])) ?></div>
          </div>
          
          <div class="card-body">
            <div class="devoir-info-grid">
              <div class="devoir-info">
                <div class="info-label">Classe:</div>
                <div class="info-value"><?= htmlspecialchars($devoir['classe']) ?></div>
              </div>
              
              <div class="devoir-info">
                <div class="info-label">Matière:</div>
                <div class="info-value"><?= htmlspecialchars($devoir['nom_matiere']) ?></div>
              </div>
              
              <div class="devoir-info">
                <div class="info-label">Professeur:</div>
                <div class="info-value"><?= htmlspecialchars($devoir['nom_professeur']) ?></div>
              </div>
              
              <div class="devoir-info">
                <div class="info-label">À rendre pour le:</div>
                <div class="info-value date-rendu <?= $urgent ? 'urgent' : ($expire ? 'expired' : '') ?>">
                  <?= date('d/m/Y', strtotime($devoir['date_rendu'])) ?>
                  <?= $urgent ? '<span class="badge badge-urgent">Urgent</span>' : '' ?>
                  <?= $expire ? '<span class="badge badge-expired">Expiré</span>' : '' ?>
                </div>
              </div>
            </div>
            
            <div class="devoir-description">
              <h4>Description:</h4>
              <p><?= nl2br(htmlspecialchars($devoir['description'])) ?></p>
            </div>
            
            <?php if (canManageDevoirs()): ?>
              <!-- Si c'est un professeur, vérifier qu'il est bien l'auteur du devoir -->
              <?php if (!isTeacher() || (isTeacher() && $devoir['nom_professeur'] == $user_fullname)): ?>
                <div class="card-actions">
                  <a href="modifier_devoir.php?id=<?= $devoir['id'] ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-edit"></i> Modifier
                  </a>
                  <a href="supprimer_devoir.php?id=<?= $devoir['id'] ?>" class="btn btn-danger btn-sm" 
                     onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?');">
                    <i class="fas fa-trash"></i> Supprimer
                  </a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// Filtrage des devoirs par semaine/mois
document.addEventListener('DOMContentLoaded', function() {
  const filterSemaine = document.getElementById('filter-semaine');
  const filterMois = document.getElementById('filter-mois');
  const devoirItems = document.querySelectorAll('.devoir-card');
  
  // Fonction pour appliquer les filtres
  function applyFilters() {
    const today = new Date();
    const endOfWeek = new Date(today);
    endOfWeek.setDate(today.getDate() + (7 - today.getDay()));
    
    const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    
    devoirItems.forEach(item => {
      const dateRenduText = item.querySelector('.date-rendu').textContent.trim().split(' ')[0];
      const dateParts = dateRenduText.split('/');
      const dateRendu = new Date(dateParts[2], dateParts[1] - 1, dateParts[0]);
      
      let shouldShow = true;
      
      if (filterSemaine.checked && filterMois.checked) {
        // Si les deux filtres sont cochés, montrer les devoirs à rendre dans le mois
        shouldShow = dateRendu <= endOfMonth && dateRendu >= today;
      } else if (filterSemaine.checked) {
        // Montrer les devoirs à rendre cette semaine
        shouldShow = dateRendu <= endOfWeek && dateRendu >= today;
      } else if (filterMois.checked) {
        // Montrer les devoirs à rendre ce mois
        shouldShow = dateRendu <= endOfMonth && dateRendu >= today;
      }
      
      item.style.display = shouldShow ? '' : 'none';
    });
  }
  
  // Ajouter les écouteurs d'événements
  if (filterSemaine) filterSemaine.addEventListener('change', applyFilters);
  if (filterMois) filterMois.addEventListener('change', applyFilters);
});

// Pour fermer les messages d'alerte
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.alert-close').forEach(button => {
    button.addEventListener('click', function() {
      const alert = this.parentElement;
      alert.style.opacity = '0';
      setTimeout(() => {
        alert.style.display = 'none';
      }, 300);
    });
  });
});
</script>

<?php
include '../assets/css/templates/footer-template.php';
ob_end_flush();
?>