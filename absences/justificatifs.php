<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/functions.php';

// Vérifier que l'utilisateur est connecté et autorisé
if (!isLoggedIn() || !canManageAbsences()) {
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
$traite = isset($_GET['traite']) ? $_GET['traite'] : '';

// Récupérer la liste des justificatifs
$justificatifs = [];

if (isAdmin() || isVieScolaire()) {
    $sql = "SELECT j.*, e.nom, e.prenom, e.classe 
            FROM justificatifs j 
            JOIN eleves e ON j.id_eleve = e.id 
            WHERE j.date_depot BETWEEN ? AND ? ";
            
    $params = [$date_debut, $date_fin];
    
    if (!empty($classe)) {
        $sql .= "AND e.classe = ? ";
        $params[] = $classe;
    }
    
    if ($traite !== '') {
        $sql .= "AND j.traite = ? ";
        $params[] = $traite === 'oui';
    }
    
    $sql .= "ORDER BY j.date_depot DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Traitement du formulaire de justification
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'traiter') {
    $id_justificatif = intval($_POST['id_justificatif']);
    $approuve = isset($_POST['approuve']) ? true : false;
    $commentaire = $_POST['commentaire'] ?? '';
    
    // Mise à jour du justificatif
    $stmt = $pdo->prepare("UPDATE justificatifs SET traite = 1, approuve = ?, commentaire_admin = ?, date_traitement = NOW(), traite_par = ? WHERE id = ?");
    
    if ($stmt->execute([$approuve, $commentaire, $user_fullname, $id_justificatif])) {
        // Si approuvé, mettre à jour l'absence
        if ($approuve && isset($_POST['id_absence'])) {
            $id_absence = intval($_POST['id_absence']);
            $stmt = $pdo->prepare("UPDATE absences SET justifie = 1 WHERE id = ?");
            $stmt->execute([$id_absence]);
        }
        
        $message = "Le justificatif a été traité avec succès.";
        // Recharger la liste des justificatifs
        header('Location: justificatifs.php?success=1');
        exit;
    } else {
        $erreur = "Une erreur est survenue lors du traitement du justificatif.";
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

// Message de succès
if (isset($_GET['success'])) {
    $message = "Le justificatif a été traité avec succès.";
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestion des justificatifs - Pronote</title>
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
        <div class="app-title">Pronote Justificatifs</div>
      </a>
      
      <!-- Filtres -->
      <div class="sidebar-section">
        <form id="filters-form" method="get" action="justificatifs.php">
          <div class="form-group">
            <label for="date_debut">Du</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= $date_debut ?>" max="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="date_fin">Au</label>
            <input type="date" id="date_fin" name="date_fin" value="<?= $date_fin ?>" max="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="classe">Classe</label>
            <select id="classe" name="classe">
              <option value="">Toutes les classes</option>
              <?php foreach ($classes as $c): ?>
              <option value="<?= $c ?>" <?= $classe == $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label for="traite">Statut</label>
            <select id="traite" name="traite">
              <option value="">Tous</option>
              <option value="oui" <?= $traite == 'oui' ? 'selected' : '' ?>>Traités</option>
              <option value="non" <?= $traite == 'non' ? 'selected' : '' ?>>Non traités</option>
            </select>
          </div>
          
          <button type="submit" class="filter-button">Appliquer les filtres</button>
        </form>
      </div>
      
      <!-- Actions -->
      <div class="sidebar-section">
        <a href="absences.php" class="action-button secondary">
          <i class="fas fa-calendar"></i> Voir les absences
        </a>
        
        <a href="retards.php" class="action-button secondary">
          <i class="fas fa-clock"></i> Voir les retards
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Header -->
      <div class="top-header">
        <div class="page-title">
          <h1>Gestion des justificatifs</h1>
        </div>
        
        <div class="header-actions">
          <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
          <div class="user-avatar"><?= $user_initials ?></div>
        </div>
      </div>
      
      <!-- Content -->
      <div class="content-container">
        <?php if (!empty($message)): ?>
          <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= $message ?>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($erreur)): ?>
          <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= $erreur ?>
          </div>
        <?php endif; ?>
        
        <?php if (empty($justificatifs)): ?>
          <div class="no-data-message">
            <i class="fas fa-info-circle"></i>
            <p>Aucun justificatif ne correspond aux critères sélectionnés.</p>
          </div>
        <?php else: ?>
          <div class="absences-list">
            <div class="list-header">
              <div class="list-row header-row">
                <div class="list-cell header-cell">Élève</div>
                <div class="list-cell header-cell">Classe</div>
                <div class="list-cell header-cell">Date de dépôt</div>
                <div class="list-cell header-cell">Période justifiée</div>
                <div class="list-cell header-cell">Type</div>
                <div class="list-cell header-cell">Statut</div>
                <div class="list-cell header-cell">Actions</div>
              </div>
            </div>
            
            <div class="list-body">
              <?php foreach ($justificatifs as $justificatif): ?>
                <?php
                $date_depot = new DateTime($justificatif['date_depot']);
                $date_debut_absence = new DateTime($justificatif['date_debut_absence']);
                $date_fin_absence = new DateTime($justificatif['date_fin_absence']);
                ?>
                <div class="list-row">
                  <div class="list-cell">
                    <?= htmlspecialchars($justificatif['prenom'] . ' ' . $justificatif['nom']) ?>
                  </div>
                  <div class="list-cell">
                    <?= htmlspecialchars($justificatif['classe']) ?>
                  </div>
                  <div class="list-cell">
                    <?= $date_depot->format('d/m/Y') ?>
                  </div>
                  <div class="list-cell">
                    Du <?= $date_debut_absence->format('d/m/Y') ?> au <?= $date_fin_absence->format('d/m/Y') ?>
                  </div>
                  <div class="list-cell">
                    <?= ucfirst(htmlspecialchars($justificatif['type'])) ?>
                  </div>
                  <div class="list-cell">
                    <?php if ($justificatif['traite']): ?>
                      <?php if ($justificatif['approuve']): ?>
                        <span class="badge badge-success">Approuvé</span>
                      <?php else: ?>
                        <span class="badge badge-danger">Refusé</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge badge-cours">En attente</span>
                    <?php endif; ?>
                  </div>
                  <div class="list-cell">
                    <div class="action-buttons">
                      <a href="#" class="btn-icon view-justificatif" data-id="<?= $justificatif['id'] ?>" title="Voir les détails">
                        <i class="fas fa-eye"></i>
                      </a>
                      <?php if (!$justificatif['traite']): ?>
                        <a href="#" class="btn-icon process-justificatif" data-id="<?= $justificatif['id'] ?>" title="Traiter ce justificatif">
                          <i class="fas fa-check"></i>
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
  
  <!-- Modal pour traiter un justificatif -->
  <div id="modal-process" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Traiter le justificatif</h2>
        <span class="modal-close">&times;</span>
      </div>
      <div class="modal-body">
        <form id="form-process" method="post" action="justificatifs.php">
          <input type="hidden" name="action" value="traiter">
          <input type="hidden" name="id_justificatif" id="id_justificatif">
          <input type="hidden" name="id_absence" id="id_absence">
          
          <div class="form-group">
            <div class="checkbox-group">
              <input type="checkbox" name="approuve" id="approuve" checked>
              <label for="approuve">Approuver ce justificatif</label>
            </div>
          </div>
          
          <div class="form-group">
            <label for="commentaire">Commentaire (optionnel)</label>
            <textarea name="commentaire" id="commentaire" rows="3"></textarea>
          </div>
          
          <div class="form-actions">
            <button type="button" class="btn btn-secondary modal-close-btn">Annuler</button>
            <button type="submit" class="btn btn-primary">Valider</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  
  <!-- Modal pour voir un justificatif -->
  <div id="modal-view" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Détails du justificatif</h2>
        <span class="modal-close">&times;</span>
      </div>
      <div class="modal-body">
        <div id="justificatif-details"></div>
      </div>
    </div>
  </div>
  
  <style>
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.6);
    }
    
    .modal-content {
      background-color: white;
      margin: 10% auto;
      padding: 0;
      border-radius: 8px;
      width: 50%;
      max-width: 600px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    .modal-header {
      padding: 15px 20px;
      border-bottom: 1px solid #eee;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .modal-header h2 {
      margin: 0;
      font-size: 1.4rem;
    }
    
    .modal-close {
      font-size: 28px;
      font-weight: bold;
      color: #aaa;
      cursor: pointer;
    }
    
    .modal-close:hover {
      color: #666;
    }
    
    .modal-body {
      padding: 20px;
    }
    
    @media (max-width: 768px) {
      .modal-content {
        width: 90%;
        margin: 20% auto;
      }
    }
  </style>
  
  <script>
    // Ouvrir le modal pour traiter un justificatif
    document.querySelectorAll('.process-justificatif').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('id_justificatif').value = this.getAttribute('data-id');
        document.getElementById('id_absence').value = this.getAttribute('data-absence-id');
        document.getElementById('modal-process').style.display = 'block';
      });
    });
    
    // Ouvrir le modal pour voir un justificatif
    document.querySelectorAll('.view-justificatif').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');
        // Ici, vous pourriez charger les détails via AJAX
        document.getElementById('justificatif-details').innerHTML = 'Chargement...';
        document.getElementById('modal-view').style.display = 'block';
        
        // Simulation d'un chargement AJAX (à remplacer par un vrai appel AJAX)
        setTimeout(function() {
          document.getElementById('justificatif-details').innerHTML = `
            <div class="details-grid">
              <div class="details-row">
                <div class="details-label">Élève:</div>
                <div class="details-value">Nom de l'élève</div>
              </div>
              <div class="details-row">
                <div class="details-label">Motif:</div>
                <div class="details-value">Maladie</div>
              </div>
              <div class="details-row">
                <div class="details-label">Période:</div>
                <div class="details-value">Du 01/05/2025 au 03/05/2025</div>
              </div>
              <div class="details-row">
                <div class="details-label">Commentaire:</div>
                <div class="details-value">Certificat médical joint.</div>
              </div>
              <div class="details-row">
                <div class="details-label">Déposé le:</div>
                <div class="details-value">05/05/2025</div>
              </div>
              <div class="details-row">
                <div class="details-label">Statut:</div>
                <div class="details-value"><span class="badge badge-cours">En attente</span></div>
              </div>
            </div>
            
            <div class="document-preview">
              <h3>Aperçu du document</h3>
              <div class="document-container">
                <div class="document-placeholder">
                  <i class="fas fa-file-medical"></i>
                  <p>Certificat médical</p>
                </div>
              </div>
            </div>
          `;
        }, 500);
      });
    });
    
    // Fermer les modals
    document.querySelectorAll('.modal-close, .modal-close-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.modal').forEach(function(modal) {
          modal.style.display = 'none';
        });
      });
    });
    
    // Fermer les modals en cliquant en dehors
    window.addEventListener('click', function(e) {
      document.querySelectorAll('.modal').forEach(function(modal) {
        if (e.target === modal) {
          modal.style.display = 'none';
        }
      });
    });
  </script>
</body>
</html>
<?php ob_end_flush(); ?>