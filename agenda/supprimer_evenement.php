<?php
ob_start();

include 'includes/db.php';
include 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login/public/login.php');
    exit;
}

$user = getCurrentUser();
$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: agenda.php');
    exit;
}

$id = $_GET['id'];

$stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
$stmt->execute([$id]);
$evenement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evenement) {
    header('Location: agenda.php');
    exit;
}

$can_delete = false;

if (isAdmin() || isVieScolaire()) {
    $can_delete = true;
} 
elseif (isTeacher() && $evenement['createur'] === $user_fullname) {
    $can_delete = true;
}

if (!$can_delete) {
    header('Location: details_evenement.php?id=' . $id);
    exit;
}

$date_debut = new DateTime($evenement['date_debut']);
$date_fin = new DateTime($evenement['date_fin']);
$format_date = 'd/m/Y';
$format_heure = 'H:i';

$types_evenements = [
    'cours' => ['nom' => 'Cours', 'icone' => 'book', 'couleur' => '#00843d'],
    'devoirs' => ['nom' => 'Devoirs', 'icone' => 'pencil', 'couleur' => '#4285f4'],
    'reunion' => ['nom' => 'Réunion', 'icone' => 'users', 'couleur' => '#ff9800'],
    'examen' => ['nom' => 'Examen', 'icone' => 'file-text', 'couleur' => '#f44336'],
    'sortie' => ['nom' => 'Sortie scolaire', 'icone' => 'map-pin', 'couleur' => '#00c853'],
    'autre' => ['nom' => 'Autre', 'icone' => 'calendar', 'couleur' => '#9e9e9e']
];

$type_info = isset($types_evenements[$evenement['type_evenement']]) 
            ? $types_evenements[$evenement['type_evenement']] 
            : $types_evenements['autre'];

$message = '';
$erreur = '';
$deleted = false;

if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes') {
    try {
        $stmt = $pdo->prepare('DELETE FROM evenements WHERE id = ?');
        $result = $stmt->execute([$id]);
        
        if ($result) {
            $deleted = true;
            $message = "L'événement a été supprimé avec succès.";
            
            header("refresh:2;url=agenda.php");
        } else {
            $erreur = "Erreur lors de la suppression de l'événement.";
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la suppression de l'événement : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supprimer l'événement - Agenda Pronote</title>
  <link rel="stylesheet" href="assets/css/calendar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .event-delete-container {
      max-width: 600px;
      margin: 20px auto;
      background-color: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    
    .event-delete-header {
      padding: 20px 25px;
      background-color: #fce8e6;
      border-bottom: 1px solid #f9d1cd;
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    .event-delete-header h1 {
      font-size: 22px;
      font-weight: 500;
      color: #d93025;
      margin: 0;
    }
    
    .warning-icon {
      font-size: 24px;
      color: #d93025;
    }
    
    .event-delete-body {
      padding: 25px;
    }
    
    .event-summary {
      background-color: #f9f9f9;
      border-radius: 6px;
      padding: 20px;
      margin-bottom: 25px;
      border-left: 4px solid #ddd;
    }
    
    .event-summary-title {
      font-size: 18px;
      font-weight: 500;
      margin-bottom: 15px;
      color: #333;
    }
    
    .event-summary-detail {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 10px;
    }
    
    .event-summary-detail:last-child {
      margin-bottom: 0;
    }
    
    .detail-icon {
      color: #666;
      width: 16px;
      text-align: center;
    }
    
    .detail-content {
      flex: 1;
    }
    
    .event-type-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 14px;
      color: white;
      background-color: #777;
    }
    
    .warning-text {
      margin-bottom: 25px;
      color: #555;
      line-height: 1.5;
    }
    
    .warning-text strong {
      color: #d93025;
    }
    
    .message {
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 4px;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .message.success {
      background-color: #e0f2e9;
      color: #00843d;
      border-left: 4px solid #00843d;
    }
    
    .message.error {
      background-color: #fce8e6;
      color: #d93025;
      border-left: 4px solid #d93025;
    }
    
    .delete-actions {
      display: flex;
      gap: 15px;
      margin-top: 30px;
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 4px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      border: none;
      text-decoration: none;
      transition: all 0.2s;
    }
    
    .btn-primary {
      background-color: #00843d;
      color: white;
    }
    
    .btn-primary:hover {
      background-color: #006e32;
    }
    
    .btn-secondary {
      background-color: #f1f3f4;
      color: #444;
    }
    
    .btn-secondary:hover {
      background-color: #e0e0e0;
    }
    
    .btn-danger {
      background-color: #d93025;
      color: white;
    }
    
    .btn-danger:hover {
      background-color: #c5221f;
    }
    
    @media (max-width: 768px) {
      .delete-actions {
        flex-direction: column;
      }
      
      .btn {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
</head>
<body>
  <div class="app-container">
    <div class="sidebar">
      <a href="../accueil/accueil.php" class="logo-container">
        <div class="app-logo">P</div>
        <div class="app-title">Pronote Agenda</div>
      </a>
      
      <div class="mini-calendar">
      </div>
      
      <div class="sidebar-section">
        <a href="ajouter_evenement.php" class="create-button">
          <span>+</span> Créer un événement
        </a>
      </div>
    </div>
    
    <div class="main-content">
      <div class="top-header">
        <div class="calendar-navigation">
          <a href="details_evenement.php?id=<?= $id ?>" class="back-button">
            <span class="back-icon">
              <i class="fas fa-arrow-left"></i>
            </span>
            Retour aux détails
          </a>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <div class="calendar-container">
        <div class="event-delete-container">
          <div class="event-delete-header">
            <div class="warning-icon">
              <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h1>Supprimer l'événement</h1>
          </div>
          
          <div class="event-delete-body">
            <?php if ($message): ?>
              <div class="message success">
                <i class="fas fa-check-circle"></i>
                <?= $message ?>
              </div>
            <?php endif; ?>
            
            <?php if ($erreur): ?>
              <div class="message error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $erreur ?>
              </div>
            <?php endif; ?>
            
            <?php if (!$deleted): ?>
              <div class="event-summary">
                <h2 class="event-summary-title"><?= htmlspecialchars($evenement['titre']) ?></h2>
                
                <div class="event-summary-detail">
                  <div class="detail-icon">
                    <i class="fas fa-tag"></i>
                  </div>
                  <div class="detail-content">
                    <span class="event-type-badge" style="background-color: <?= $type_info['couleur'] ?>;">
                      <i class="fas fa-<?= $type_info['icone'] ?>"></i>
                      <?= $type_info['nom'] ?>
                    </span>
                  </div>
                </div>
                
                <div class="event-summary-detail">
                  <div class="detail-icon">
                    <i class="far fa-calendar-alt"></i>
                  </div>
                  <div class="detail-content">
                    <?php if ($date_debut->format('Y-m-d') === $date_fin->format('Y-m-d')): ?>
                      Le <?= $date_debut->format($format_date) ?> de <?= $date_debut->format($format_heure) ?> à <?= $date_fin->format($format_heure) ?>
                    <?php else: ?>
                      Du <?= $date_debut->format($format_date) ?> à <?= $date_debut->format($format_heure) ?> 
                      au <?= $date_fin->format($format_date) ?> à <?= $date_fin->format($format_heure) ?>
                    <?php endif; ?>
                  </div>
                </div>
                
                <?php if (!empty($evenement['lieu'])): ?>
                <div class="event-summary-detail">
                  <div class="detail-icon">
                    <i class="fas fa-map-marker-alt"></i>
                  </div>
                  <div class="detail-content">
                    <?= htmlspecialchars($evenement['lieu']) ?>
                  </div>
                </div>
                <?php endif; ?>
                
                <div class="event-summary-detail">
                  <div class="detail-icon">
                    <i class="fas fa-user"></i>
                  </div>
                  <div class="detail-content">
                    Créé par <?= htmlspecialchars($evenement['createur']) ?>
                  </div>
                </div>
              </div>
              
              <div class="warning-text">
                <p><strong>Attention :</strong> Vous êtes sur le point de supprimer définitivement cet événement.</p>
                <p>Cette action est irréversible et supprimera toutes les informations associées à cet événement.</p>
                <p>Veuillez confirmer votre choix en cliquant sur le bouton "Supprimer définitivement".</p>
              </div>
              
              <form method="post" class="delete-form">
                <input type="hidden" name="confirm_delete" value="yes">
                
                <div class="delete-actions">
                  <a href="details_evenement.php?id=<?= $id ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Annuler
                  </a>
                  <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i>
                    Supprimer définitivement
                  </button>
                </div>
              </form>
            <?php else: ?>
              <div class="warning-text">
                <p>L'événement a été supprimé avec succès. Vous allez être redirigé vers l'agenda...</p>
              </div>
              
              <div class="delete-actions">
                <a href="agenda.php" class="btn btn-primary">
                  <i class="fas fa-calendar-alt"></i>
                  Retourner à l'agenda
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

<?php
ob_end_flush();
?>