<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../API/auth_central.php';
require_once 'includes/db.php';

// Vérifier les permissions pour gérer les notes
if (!canManageNotes()) {
    header('Location: notes.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_initials = isset($user['prenom'], $user['nom']) ? 
    strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1)) : '';

// Validation de l'ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    $_SESSION['error_message'] = "Identifiant de note invalide.";
    header('Location: notes.php');
    exit;
}

// Vérification des autorisations spécifiques selon le rôle
try {
    if (isTeacher() && !isAdmin() && !isVieScolaire()) {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ? AND nom_professeur = ?');
        $stmt->execute([$id, $user_fullname]);
        
        if ($stmt->rowCount() === 0) {
            $_SESSION['error_message'] = "Vous n'êtes pas autorisé à supprimer cette note.";
            header('Location: notes.php');
            exit;
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            $_SESSION['error_message'] = "Note introuvable.";
            header('Location: notes.php');
            exit;
        }
    }

    // Récupérer les informations sur la note à supprimer
    $stmt = $pdo->prepare('SELECT * FROM notes WHERE id = ?');
    $stmt->execute([$id]);
    $note = $stmt->fetch();

    // Traitement de la suppression
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Vérification du token CSRF
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Erreur de sécurité. Veuillez réessayer.";
            header('Location: notes.php');
            exit;
        }
        
        $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        
        // Journalisation de l'action
        $action = "Suppression de la note ID=$id, Élève={$note['nom_eleve']}, Matière={$note['matiere']}, Note={$note['note']}";
        error_log($action);
        
        $_SESSION['success_message'] = "La note a été supprimée avec succès.";
        header('Location: notes.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la suppression d'une note: " . $e->getMessage());
    $_SESSION['error_message'] = "Une erreur est survenue lors du traitement de votre demande.";
    header('Location: notes.php');
    exit;
}

// Générer un token CSRF pour protéger le formulaire
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Définir la configuration de la page
$pageTitle = "Supprimer une note";
$moduleClass = "notes";
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Pronote</title>
    <link rel="stylesheet" href="../assets/css/pronote-theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .confirmation-box {
            background-color: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-light);
            padding: var(--space-lg);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .note-details {
            background-color: var(--background-color);
            border-radius: var(--radius-sm);
            padding: var(--space-md);
            margin: var(--space-md) 0;
        }
        
        .warning {
            display: flex;
            align-items: center;
            padding: var(--space-md);
            background-color: rgba(255, 59, 48, 0.1);
            color: var(--error-color);
            border-radius: var(--radius-sm);
            margin: var(--space-md) 0;
        }
        
        .warning i {
            font-size: 24px;
            margin-right: var(--space-md);
        }
        
        .confirmation-actions {
            display: flex;
            justify-content: space-between;
            margin-top: var(--space-lg);
        }
        
        .btn-danger {
            background-color: var(--error-color);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background-color: #d63030;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo-container">
                <div class="app-logo">P</div>
                <div class="app-title">Pronote</div>
            </div>
            
            <!-- Navigation -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Navigation</div>
                <a href="<?= defined('HOME_URL') ? HOME_URL : '../accueil/accueil.php' ?>" class="sidebar-link">
                    <i class="fas fa-home"></i> Accueil
                </a>
                <a href="notes.php" class="sidebar-link active">
                    <i class="fas fa-chart-bar"></i> Notes
                </a>
                <a href="../absences/absences.php" class="sidebar-link">
                    <i class="fas fa-calendar-times"></i> Absences
                </a>
                <a href="../agenda/agenda.php" class="sidebar-link">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
                <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-link">
                    <i class="fas fa-book"></i> Cahier de textes
                </a>
                <a href="../messagerie/index.php" class="sidebar-link">
                    <i class="fas fa-envelope"></i> Messagerie
                </a>
            </div>
            
            <!-- Actions -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Actions</div>
                <a href="notes.php" class="create-button">
                    <i class="fas fa-arrow-left"></i> Retour aux notes
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-header">
                <div class="page-title">
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                </div>
                
                <div class="header-actions">
                    <a href="<?= defined('LOGOUT_URL') ? LOGOUT_URL : '../login/public/logout.php' ?>" class="logout-button" title="Déconnexion">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                    <div class="user-avatar" title="<?= htmlspecialchars($user_fullname) ?>">
                        <?= htmlspecialchars($user_initials) ?>
                    </div>
                </div>
            </div>
            
            <div class="content-container">
                <div class="confirmation-box">
                    <h2>Confirmer la suppression</h2>
                    <p>Vous êtes sur le point de supprimer définitivement la note suivante :</p>
                    
                    <div class="note-details">
                        <p><strong>Élève :</strong> <?= htmlspecialchars($note['nom_eleve']) ?></p>
                        <p><strong>Matière :</strong> <?= htmlspecialchars($note['matiere']) ?></p>
                        <p><strong>Note :</strong> <?= htmlspecialchars($note['note']) ?>/<?= htmlspecialchars($note['note_sur'] ?? '20') ?></p>
                        <p><strong>Date :</strong> <?= date('d/m/Y', strtotime($note['date_ajout'] ?? $note['date_evaluation'] ?? date('Y-m-d'))) ?></p>
                        <?php if (!empty($note['commentaire'])): ?>
                            <p><strong>Commentaire :</strong> <?= htmlspecialchars($note['commentaire']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Attention : Cette action est irréversible.</p>
                    </div>
                    
                    <div class="confirmation-actions">
                        <a href="notes.php" class="btn btn-secondary">Annuler</a>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <button type="submit" class="btn btn-danger">Confirmer la suppression</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>