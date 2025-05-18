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

// Vérifier si la table justificatifs existe
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'justificatifs'");
    if ($tableCheck->rowCount() == 0) {
        // Créer la table si elle n'existe pas
        createJustificatifsTableIfNotExists($pdo);
    } else {
        // Vérifier si la colonne date_soumission existe
        $columnCheck = $pdo->query("SHOW COLUMNS FROM justificatifs LIKE 'date_soumission'");
        if ($columnCheck->rowCount() == 0) {
            // Essayer de trouver une colonne similaire (date_depot ou autre)
            $columns = $pdo->query("DESCRIBE justificatifs");
            $dateColumns = [];
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                if (strpos($col['Field'], 'date') !== false) {
                    $dateColumns[] = $col['Field'];
                }
            }
            
            // Si une colonne de date est trouvée, l'utiliser; sinon, créer la colonne
            if (!empty($dateColumns)) {
                $dateColumn = $dateColumns[0]; // Utiliser la première colonne de date trouvée
            } else {
                // Ajouter la colonne date_soumission
                $pdo->exec("ALTER TABLE justificatifs ADD COLUMN date_soumission DATE DEFAULT CURRENT_DATE");
                $dateColumn = 'date_soumission';
            }
            
            // Journaliser le changement
            error_log("Colonne date_soumission manquante dans la table justificatifs, utilisation de la colonne $dateColumn à la place");
        } else {
            $dateColumn = 'date_soumission';
        }
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification de la table justificatifs: " . $e->getMessage());
    // Par défaut, utiliser une colonne qui existe probablement
    $dateColumn = 'date_depot';
}

// Récupérer la liste des justificatifs
$justificatifs = [];

if (isAdmin() || isVieScolaire()) {
    try {
        // Construire la requête en utilisant la colonne de date déterminée
        $sql = "SELECT j.*, e.nom, e.prenom, e.classe 
                FROM justificatifs j 
                JOIN eleves e ON j.id_eleve = e.id 
                WHERE j.$dateColumn BETWEEN ? AND ? ";
                
        $params = [$date_debut, $date_fin];
        
        if (!empty($classe)) {
            $sql .= "AND e.classe = ? ";
            $params[] = $classe;
        }
        
        if ($traite !== '') {
            $sql .= "AND j.traite = ? ";
            $params[] = $traite === 'oui';
        }
        
        $sql .= "ORDER BY j.$dateColumn DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des justificatifs: " . $e->getMessage());
    }
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
try {
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
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des classes: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Justificatifs d'absence - Pronote</title>
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
        <form id="filters-form" method="get" action="">
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
          <i class="fas fa-arrow-left"></i> Retour aux absences
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
        <?php if (empty($justificatifs)): ?>
          <div class="no-data-message">
            <i class="fas fa-info-circle"></i>
            <p>Aucun justificatif ne correspond aux critères sélectionnés.</p>
          </div>
        <?php else: ?>
          <div class="justificatifs-list">
            <div class="list-header">
              <div class="list-row header-row">
                <div class="list-cell header-cell">Élève</div>
                <div class="list-cell header-cell">Classe</div>
                <div class="list-cell header-cell">Date de dépôt</div>
                <div class="list-cell header-cell">Période</div>
                <div class="list-cell header-cell">Motif</div>
                <div class="list-cell header-cell">Statut</div>
                <div class="list-cell header-cell">Actions</div>
              </div>
            </div>
            
            <div class="list-body">
              <?php foreach ($justificatifs as $justificatif): ?>
                <div class="list-row justificatif-row <?= $justificatif['traite'] ? 'traite' : 'non-traite' ?>">
                  <div class="list-cell"><?= htmlspecialchars($justificatif['prenom'] . ' ' . $justificatif['nom']) ?></div>
                  <div class="list-cell"><?= htmlspecialchars($justificatif['classe']) ?></div>
                  <div class="list-cell">
                    <?= isset($justificatif[$dateColumn]) ? date('d/m/Y', strtotime($justificatif[$dateColumn])) : 'N/A' ?>
                  </div>
                  <div class="list-cell">
                    Du <?= date('d/m/Y', strtotime($justificatif['date_debut_absence'])) ?>
                    <br>
                    au <?= date('d/m/Y', strtotime($justificatif['date_fin_absence'])) ?>
                  </div>
                  <div class="list-cell"><?= htmlspecialchars($justificatif['motif'] ?? 'Non spécifié') ?></div>
                  <div class="list-cell">
                    <?php if ($justificatif['traite']): ?>
                      <span class="badge badge-success">Traité</span>
                      <span class="badge <?= $justificatif['approuve'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $justificatif['approuve'] ? 'Approuvé' : 'Rejeté' ?>
                      </span>
                    <?php else: ?>
                      <span class="badge badge-warning">En attente</span>
                    <?php endif; ?>
                  </div>
                  <div class="list-cell">
                    <div class="action-buttons">
                      <a href="details_justificatif.php?id=<?= $justificatif['id'] ?>" class="btn-icon" title="Voir les détails">
                        <i class="fas fa-eye"></i>
                      </a>
                      <a href="traiter_justificatif.php?id=<?= $justificatif['id'] ?>" class="btn-icon" title="Traiter">
                        <i class="fas fa-check-circle"></i>
                      </a>
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