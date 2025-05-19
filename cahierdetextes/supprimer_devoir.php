<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

include 'includes/db.php';
include 'includes/auth.php';

// Vérifier si l'utilisateur a les permissions pour supprimer des devoirs
if (!canManageDevoirs()) {
  header('Location: cahierdetextes.php');
  exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
$user_fullname = getUserFullName();

// Vérifier que l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  header('Location: cahierdetextes.php');
  exit;
}

// Générer ou vérifier le token CSRF
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Vérifier le token CSRF si le formulaire est soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
    $_SESSION['error_message'] = "Erreur de validation du formulaire. Veuillez réessayer.";
    header('Location: cahierdetextes.php');
    exit;
  }
}

$id = intval($_GET['id']); // Sanitize with intval to ensure numeric value

// Vérifier que le devoir existe avant d'essayer de le supprimer
$check_stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
$check_stmt->execute([$id]);
$devoir = $check_stmt->fetch();

if (!$devoir) {
  // Le devoir n'existe pas
  $_SESSION['error_message'] = "Le devoir demandé n'existe pas.";
  header('Location: cahierdetextes.php?error=notfound');
  exit;
}

// Si l'utilisateur est un professeur (et pas un admin ou vie scolaire), 
// il peut seulement supprimer ses propres devoirs
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
  if ($devoir['nom_professeur'] !== $user_fullname) {
    // Le devoir n'appartient pas au professeur connecté
    $_SESSION['error_message'] = "Vous n'avez pas les droits nécessaires pour supprimer ce devoir.";
    header('Location: cahierdetextes.php?error=unauthorized');
    exit;
  }
}

// Variables pour le template
$pageTitle = "Supprimer un devoir";
$moduleClass = "cahier";
$moduleColor = "var(--accent-cahier)";

// Si c'est une requête GET, afficher la page de confirmation
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  // Contenu de la sidebar
  $sidebarContent = <<<HTML
  <div class="sidebar-section">
    <div class="sidebar-title">Navigation</div>
    <div class="sidebar-menu">
      <a href="cahierdetextes.php" class="sidebar-link">
        <i class="fas fa-list"></i> Liste des devoirs
      </a>
      <a href="ajouter_devoir.php" class="sidebar-link">
        <i class="fas fa-plus"></i> Ajouter un devoir
      </a>
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

  // Actions du header
  $headerActions = <<<HTML
  <a href="cahierdetextes.php" class="header-icon-button" title="Retour à la liste">
    <i class="fas fa-arrow-left"></i>
  </a>
  HTML;

  include '../assets/css/templates/header-template.php';
  ?>

  <div class="section">
    <div class="section-header">
      <h2>Confirmer la suppression</h2>
      <p class="text-muted">Voulez-vous vraiment supprimer ce devoir ?</p>
    </div>
    
    <div class="card">
      <div class="card-body">
        <div class="alert-banner alert-warning">
          <i class="fas fa-exclamation-triangle"></i>
          <p>Attention : Cette action est irréversible. Le devoir sera définitivement supprimé.</p>
        </div>
        
        <div class="devoir-details mb-4">
          <h3><?= htmlspecialchars($devoir['titre']) ?></h3>
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
              <div class="info-label">Date de rendu:</div>
              <div class="info-value"><?= date('d/m/Y', strtotime($devoir['date_rendu'])) ?></div>
            </div>
          </div>
        </div>
        
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <div class="form-actions">
            <a href="cahierdetextes.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn btn-danger">
              <i class="fas fa-trash"></i> Confirmer la suppression
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php
  include '../assets/css/templates/footer-template.php';
} else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Traitement de la suppression
  try {
    // Supprimer le devoir
    $stmt = $pdo->prepare('DELETE FROM devoirs WHERE id = ?');
    $stmt->execute([$id]);
    
    $_SESSION['success_message'] = "Le devoir a été supprimé avec succès.";
    header('Location: cahierdetextes.php?success=deleted');
  } catch (PDOException $e) {
    // Journal d'erreurs
    error_log("Erreur de suppression dans supprimer_devoir.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Une erreur est survenue lors de la suppression du devoir.";
    header('Location: cahierdetextes.php?error=dbfailed');
  }
}
exit;

// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>