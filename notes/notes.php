<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusions nécessaires (sans header.php)
include_once 'includes/db.php';
include_once 'includes/auth.php';

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

$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 

// Vérifier si la table notes existe
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'notes'");
    $table_exists = $check_table && $check_table->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
}

// Si la table n'existe pas, la créer avec la structure correcte
if (!$table_exists) {
    try {
        $create_table = "CREATE TABLE IF NOT EXISTS `notes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `eleve_id` INT NOT NULL,
            `nom_eleve` VARCHAR(100) NOT NULL,
            `classe` VARCHAR(50) NOT NULL,
            `matiere` VARCHAR(100) NOT NULL,
            `note` FLOAT NOT NULL,
            `note_sur` FLOAT NOT NULL DEFAULT 20,
            `commentaire` TEXT,
            `nom_professeur` VARCHAR(100) NOT NULL,
            `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_evaluation` DATE DEFAULT NULL
        )";
        $pdo->exec($create_table);
        $table_exists = true;
    } catch (PDOException $e) {
        error_log("Erreur lors de la création de la table notes: " . $e->getMessage());
    }
}

// Vérifier et ajouter les colonnes nécessaires si elles n'existent pas
if ($table_exists) {
    $required_columns = [
        'matiere' => 'VARCHAR(100) NOT NULL',
        'date_evaluation' => 'DATE DEFAULT NULL',
        'date_creation' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    ];
    
    foreach ($required_columns as $column => $definition) {
        try {
            $check_column = $pdo->query("SHOW COLUMNS FROM notes LIKE '$column'");
            $column_exists = $check_column && $check_column->rowCount() > 0;
            
            if (!$column_exists) {
                $pdo->exec("ALTER TABLE notes ADD COLUMN $column $definition");
                error_log("Colonne '$column' ajoutée à la table notes");
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de la vérification ou ajout de la colonne $column: " . $e->getMessage());
        }
    }
}

// Récupérer toutes les classes disponibles après s'être assuré que la table existe
$classes = [];
try {
    if ($table_exists) {
        $stmt_classes = $pdo->query('SELECT DISTINCT classe FROM notes ORDER BY classe');
        $classes = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des classes: " . $e->getMessage());
}

// Définir la classe sélectionnée (si présente dans l'URL ou par défaut la première)
$selected_class = isset($_GET['classe']) ? $_GET['classe'] : ($classes[0] ?? '');
$classe_selectionnee = $selected_class; // Ajouter cette variable pour corriger l'erreur

// Définir le trimestre sélectionné (si présent dans l'URL ou par défaut le premier)
$trimestre_selectionne = isset($_GET['trimestre']) ? $_GET['trimestre'] : 1;

// Définir la matière sélectionnée (si présente dans l'URL ou vide par défaut)
$selected_subject = isset($_GET['matiere']) ? $_GET['matiere'] : '';

// Définir le filtre de date - vérifier d'abord si les colonnes existent
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Déterminer les colonnes à utiliser pour le tri
try {
    $check_date_evaluation = $pdo->query("SHOW COLUMNS FROM notes LIKE 'date_evaluation'");
    $date_evaluation_exists = $check_date_evaluation && $check_date_evaluation->rowCount() > 0;
    
    $check_date_creation = $pdo->query("SHOW COLUMNS FROM notes LIKE 'date_creation'");
    $date_creation_exists = $check_date_creation && $check_date_creation->rowCount() > 0;
    
    $check_matiere = $pdo->query("SHOW COLUMNS FROM notes LIKE 'matiere'");
    $matiere_exists = $check_matiere && $check_matiere->rowCount() > 0;
} catch (PDOException $e) {
    $date_evaluation_exists = false;
    $date_creation_exists = false;
    $matiere_exists = false;
    error_log("Erreur lors de la vérification des colonnes: " . $e->getMessage());
}

// Déterminer la colonne de date à utiliser pour le tri
$date_column = $date_evaluation_exists ? 'date_evaluation' : ($date_creation_exists ? 'date_creation' : 'id');

// Récupérer toutes les matières disponibles
$matieres = [];
if ($matiere_exists) {
    try {
        $stmt_matieres = $pdo->query('SELECT DISTINCT matiere FROM notes ORDER BY matiere');
        $matieres = $stmt_matieres->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des matières: " . $e->getMessage());
    }
}

// Construire la requête de base
$query = 'SELECT * FROM notes WHERE 1=1';
$params = [];

// Ajouter des filtres à la requête
if (!empty($selected_class)) {
    $query .= ' AND classe = ?';
    $params[] = $selected_class;
}

if (!empty($selected_subject) && $matiere_exists) {
    $query .= ' AND matiere = ?';
    $params[] = $selected_subject;
}

if (!empty($date_filter)) {
    if ($date_evaluation_exists) {
        $query .= " AND date_evaluation = ?";
        $params[] = $date_filter;
    } else if ($date_creation_exists) {
        $query .= " AND DATE(date_creation) = ?";
        $params[] = $date_filter;
    }
}

// Si l'utilisateur est un professeur (et pas un admin), limiter aux notes qu'il a créées
if (isTeacher() && !isAdmin() && !isVieScolaire()) {
    $query .= ' AND nom_professeur = ?';
    $params[] = $user_fullname;
}

// Si l'utilisateur est un élève, limiter aux notes le concernant
if (isStudent()) {
    $query .= ' AND eleve_id = ?';
    $params[] = $user['id'];
}

// Si l'utilisateur est un parent, limiter aux notes concernant son/ses enfant(s)
if (isParent()) {
    // Récupérer les IDs des enfants
    try {
        $stmt_enfants = $pdo->prepare('SELECT eleve_id FROM parents_eleves WHERE parent_id = ?');
        $stmt_enfants->execute([$user['id']]);
        $enfants = $stmt_enfants->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($enfants)) {
            $placeholders = implode(',', array_fill(0, count($enfants), '?'));
            $query .= ' AND eleve_id IN (' . $placeholders . ')';
            $params = array_merge($params, $enfants);
        } else {
            // Si le parent n'a pas d'enfant enregistré, ne rien afficher
            $query .= ' AND 1=0';
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des enfants: " . $e->getMessage());
        // En cas d'erreur, ne rien afficher par défaut
        $query .= ' AND 1=0';
    }
}

// Ajouter l'ordre en s'assurant que les colonnes existent
if ($matiere_exists && $date_column !== 'id') {
    $query .= " ORDER BY $date_column DESC, matiere ASC";
} else if ($date_column !== 'id') {
    $query .= " ORDER BY $date_column DESC";
} else {
    $query .= " ORDER BY id DESC";
}

// Préparer et exécuter la requête
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur lors de l'exécution de la requête: " . $e->getMessage());
    $notes = [];
}

// Récupérer les dates d'évaluation disponibles
$dates = [];
try {
    if ($date_evaluation_exists) {
        $sql_dates = "SELECT DISTINCT date_evaluation FROM notes WHERE date_evaluation IS NOT NULL ORDER BY date_evaluation DESC";
        $stmt_dates = $pdo->query($sql_dates);
        $dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);
    } else if ($date_creation_exists) {
        $sql_dates = "SELECT DISTINCT DATE(date_creation) as date FROM notes ORDER BY date DESC";
        $stmt_dates = $pdo->query($sql_dates);
        $dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des dates: " . $e->getMessage());
}
?>

<!-- Structure HTML de la page -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes - Pronote</title>
    <link rel="stylesheet" href="../agenda/assets/css/calendar.css">
    <link rel="stylesheet" href="assets/css/notes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <div class="sidebar">
            <a href="../accueil/accueil.php" class="logo-container">
                <div class="app-logo">P</div>
                <div class="app-title">Pronote Notes</div>
            </a>
            
            <div class="sidebar-section">
                <div class="section-title">Filtres</div>
                <form method="get" action="notes.php">
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select id="classe" name="classe" onchange="this.form.submit()">
                            <?php foreach ($classes as $classe): ?>
                            <option value="<?= htmlspecialchars($classe) ?>" <?= $classe_selectionnee === $classe ? 'selected' : '' ?>>
                                <?= htmlspecialchars($classe) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="trimestre">Trimestre</label>
                        <select id="trimestre" name="trimestre" onchange="this.form.submit()">
                            <option value="1" <?= $trimestre_selectionne == 1 ? 'selected' : '' ?>>Trimestre 1</option>
                            <option value="2" <?= $trimestre_selectionne == 2 ? 'selected' : '' ?>>Trimestre 2</option>
                            <option value="3" <?= $trimestre_selectionne == 3 ? 'selected' : '' ?>>Trimestre 3</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="matiere">Matière</label>
                        <select id="matiere" name="matiere" onchange="this.form.submit()">
                            <option value="">Toutes les matières</option>
                            <?php foreach ($matieres as $matiere): ?>
                            <option value="<?= htmlspecialchars($matiere) ?>" <?= $selected_subject === $matiere ? 'selected' : '' ?>>
                                <?= htmlspecialchars($matiere) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if (!empty($dates)): ?>
                    <div class="form-group">
                        <label for="date">Date d'évaluation</label>
                        <select id="date" name="date" onchange="this.form.submit()">
                            <option value="">Toutes les dates</option>
                            <?php foreach ($dates as $date): ?>
                            <option value="<?= htmlspecialchars($date) ?>" <?= $date_filter === $date ? 'selected' : '' ?>>
                                <?= htmlspecialchars(date('d/m/Y', strtotime($date))) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <?php if (canManageNotes()): ?>
            <div class="sidebar-section">
                <div class="section-title">Actions</div>
                <a href="ajouter_note.php" class="sidebar-button">
                    <i class="fas fa-plus"></i>
                    Ajouter une note
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="main-content">
            <div class="top-header">
                <div class="page-title">
                    <h1>Notes et évaluations</h1>
                    <p class="subtitle">
                        <?php if ($classe_selectionnee): ?>
                            Classe <?= htmlspecialchars($classe_selectionnee) ?> -
                        <?php endif; ?>
                        
                        <?php if ($selected_subject): ?>
                            <?= htmlspecialchars($selected_subject) ?> -
                        <?php endif; ?>
                        
                        Trimestre <?= htmlspecialchars($trimestre_selectionne) ?>
                    </p>
                </div>
                
                <div class="user-profile">
                    <div class="logout-button" title="Déconnexion">
                        <a href="../login/public/logout.php">
                            <i class="fas fa-power-off"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Reste du contenu -->

        </div>
    </div>
</body>
</html>
<?php
// Vider la mémoire tampon
ob_end_flush();
?>