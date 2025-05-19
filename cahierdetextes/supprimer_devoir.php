<?php
// Démarrer la mise en mémoire tampon de sortie
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
$user_role = getUserRole();
$user_initials = strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));

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

// Calculer l'état du devoir
$date_rendu = new DateTime($devoir['date_rendu']);
$aujourdhui = new DateTime();
$diff = $aujourdhui->diff($date_rendu);

$statusClass = '';
$statusText = '';

if ($date_rendu < $aujourdhui) {
    $statusClass = 'expired';
    $statusText = 'Expiré';
} elseif ($diff->days <= 3) {
    $statusClass = 'urgent';
    $statusText = 'Urgent (< 3 jours)';
} elseif ($diff->days <= 7) {
    $statusClass = 'soon';
    $statusText = 'Cette semaine';
} else {
    $statusText = 'À venir';
}

// Variables pour le template
$pageTitle = "Supprimer un devoir";

// Si c'est une requête GET, afficher la page de confirmation
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - PRONOTE</title>
    <link rel="stylesheet" href="assets/css/cahierdetextes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>

<div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <div class="app-logo">P</div>
            <div class="app-title">PRONOTE</div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Navigation</div>
            <div class="sidebar-nav">
                <a href="../accueil/accueil.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
                    <span>Accueil</span>
                </a>
                <a href="../notes/notes.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Notes</span>
                </a>
                <a href="../agenda/agenda.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
                    <span>Agenda</span>
                </a>
                <a href="cahierdetextes.php" class="sidebar-nav-item active">
                    <span class="sidebar-nav-icon"><i class="fas fa-book"></i></span>
                    <span>Cahier de textes</span>
                </a>
                <a href="../messagerie/index.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
                    <span>Messagerie</span>
                </a>
                <?php if ($user_role === 'vie_scolaire' || $user_role === 'administrateur'): ?>
                <a href="../absences/absences.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
                    <span>Absences</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Actions</div>
            <div class="sidebar-nav">
                <a href="cahierdetextes.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-list"></i></span>
                    <span>Liste des devoirs</span>
                </a>
                <a href="ajouter_devoir.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-plus"></i></span>
                    <span>Ajouter un devoir</span>
                </a>
            </div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Informations</div>
            <div class="info-item">
                <div class="info-label">Date</div>
                <div class="info-value"><?= date('d/m/Y') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Utilisateur</div>
                <div class="info-value"><?= htmlspecialchars($user_fullname) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Profil</div>
                <div class="info-value"><?= ucfirst(htmlspecialchars($user_role)) ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="top-header">
            <div class="page-title">
                <h1>Supprimer un devoir</h1>
            </div>
            
            <div class="header-actions">
                <a href="cahierdetextes.php" class="btn btn-secondary" title="Retour à la liste">
                    <i class="fas fa-arrow-left"></i> Retour
                </a>
                <a href="/~u22405372/SAE/Pronote/login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
                <div class="user-avatar"><?= $user_initials ?></div>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-content">
                <h2>Supprimer un devoir</h2>
                <p>Vous êtes sur le point de supprimer définitivement ce devoir</p>
            </div>
            <div class="welcome-logo">
                <i class="fas fa-trash-alt"></i>
            </div>
        </div>
        
        <!-- Main Dashboard Content -->
        <div class="dashboard-content">
            <div class="alert-banner alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Attention : Cette action est irréversible. Le devoir sera définitivement supprimé de la base de données.</p>
            </div>
            
            <div class="devoir-card <?= $statusClass ?>" style="margin-top: 20px;">
                <div class="card-header">
                    <div class="devoir-title">
                        <i class="fas fa-book"></i> <?= htmlspecialchars($devoir['titre']) ?>
                        <?php if ($statusClass): ?>
                            <span class="badge badge-<?= $statusClass ?>"><?= $statusText ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="devoir-meta">
                        Ajouté le: <?= date('d/m/Y', strtotime($devoir['date_ajout'])) ?>
                    </div>
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
                            <div class="info-label">Date de rendu:</div>
                            <div class="info-value date-rendu <?= $statusClass ?>">
                                <?= date('d/m/Y', strtotime($devoir['date_rendu'])) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="devoir-description">
                        <h4>Description:</h4>
                        <p><?= nl2br(htmlspecialchars($devoir['description'])) ?></p>
                    </div>
                    
                    <form method="post" style="margin-top: 20px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="form-actions">
                            <a href="cahierdetextes.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Annuler
                            </a>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Confirmer la suppression
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="footer-content">
                    <div class="footer-links">
                        <a href="#">Mentions Légales</a>
                    </div>
                    <div class="footer-copyright">
                        &copy; <?= date('Y') ?> PRONOTE - Tous droits réservés
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Fermer automatiquement les alertes après 5 secondes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert-banner:not(.alert-warning)').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
    
    document.querySelectorAll('.alert-close').forEach(function(button) {
        button.addEventListener('click', function() {
            const alert = this.parentElement;
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 300);
        });
    });
});
</script>

</body>
</html>

<?php
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