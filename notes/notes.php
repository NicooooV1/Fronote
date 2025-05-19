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

// Vérifier si la colonne 'trimestre' existe et l'ajouter si nécessaire
try {
    if ($table_exists) {
        $check_trimestre = $pdo->query("SHOW COLUMNS FROM notes LIKE 'trimestre'");
        $trimestre_exists = $check_trimestre && $check_trimestre->rowCount() > 0;
        
        if (!$trimestre_exists) {
            // La colonne n'existe pas, on la crée
            $pdo->exec("ALTER TABLE notes ADD COLUMN trimestre INT DEFAULT 1");
            error_log("Colonne 'trimestre' ajoutée à la table notes");
            $trimestre_exists = true;
        }
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification/création de la colonne trimestre: " . $e->getMessage());
    $trimestre_exists = false;
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
$trimestre_selectionne = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;

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

// Filtre par trimestre si la colonne existe
if ($trimestre_exists) {
    $query .= ' AND trimestre = ?';
    $params[] = $trimestre_selectionne;
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

// Grouper les notes par matière pour l'affichage
$notes_par_matiere = [];
foreach ($notes as $note) {
    $matiere = $note['matiere'];
    if (!isset($notes_par_matiere[$matiere])) {
        $notes_par_matiere[$matiere] = [];
    }
    $notes_par_matiere[$matiere][] = $note;
}

// Calculer les moyennes par matière
$moyennes_par_matiere = [];
foreach ($notes_par_matiere as $matiere => $notes_matiere) {
    $total = 0;
    $total_coef = 0;
    foreach ($notes_matiere as $note) {
        $coef = isset($note['coefficient']) ? $note['coefficient'] : 1;
        $total += $note['note'] * $coef;
        $total_coef += $coef;
    }
    $moyennes_par_matiere[$matiere] = $total_coef > 0 ? round($total / $total_coef, 2) : 'N/A';
}

// Calculer la moyenne générale
$moyenne_generale = 0;
$total_coef_global = 0;
foreach ($notes_par_matiere as $matiere => $notes_matiere) {
    $total_matiere = 0;
    $total_coef_matiere = 0;
    foreach ($notes_matiere as $note) {
        $coef = isset($note['coefficient']) ? $note['coefficient'] : 1;
        $total_matiere += $note['note'] * $coef;
        $total_coef_matiere += $coef;
    }
    if ($total_coef_matiere > 0) {
        $moyenne_generale += $total_matiere;
        $total_coef_global += $total_coef_matiere;
    }
}
$moyenne_generale = $total_coef_global > 0 ? round($moyenne_generale / $total_coef_global, 2) : 'N/A';

// Créer un tableau de couleurs pour les matières
$couleurs_matieres = [
    'Mathématiques' => 'mathematiques',
    'Histoire-Géographie' => 'histoire-geo',
    'Anglais' => 'anglais',
    'Espagnol' => 'espagnol',
    'Allemand' => 'allemand',
    'Physique-Chimie' => 'physique-chimie',
    'SVT' => 'svt',
    'Technologie' => 'technologie',
    'Arts Plastiques' => 'arts',
    'Musique' => 'musique',
    'EPS' => 'eps'
};
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
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo-container">
                <div class="app-logo">P</div>
<<<<<<< HEAD
                <div class="app-title">Pronote Notes</div>
            </a>
            
            <!-- Périodes -->
            <div class="sidebar-section">
                <h3 class="sidebar-section-header">Périodes</h3>
                <div class="sidebar-nav">
                    <a href="?trimestre=1<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="sidebar-nav-item <?= $trimestre_selectionne == 1 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 1</span>
                    </a>
                    <a href="?trimestre=2<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="sidebar-nav-item <?= $trimestre_selectionne == 2 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 2</span>
                    </a>
                    <a href="?trimestre=3<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="sidebar-nav-item <?= $trimestre_selectionne == 3 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 3</span>
                    </a>
                </div>
            </div>
            
            <!-- Classes -->
            <?php if (isAdmin() || isTeacher() || isVieScolaire()): ?>
            <div class="sidebar-section">
                <h3 class="sidebar-section-header">Classes</h3>
                <div class="sidebar-nav">
                    <?php foreach ($classes as $classe): ?>
                    <a href="?classe=<?= urlencode($classe) ?>&trimestre=<?= $trimestre_selectionne ?>" class="sidebar-nav-item <?= $classe_selectionnee === $classe ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-users"></i></span>
                        <span><?= htmlspecialchars($classe) ?></span>
=======
                <div class="app-title">Notes</div>
            </div>
            
            <!-- Périodes -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Périodes</div>
                <div class="folder-menu">
                    <a href="?trimestre=1<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="<?= $trimestre_selectionne == 1 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-alt"></i> Trimestre 1
                    </a>
                    <a href="?trimestre=2<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="<?= $trimestre_selectionne == 2 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-alt"></i> Trimestre 2
                    </a>
                    <a href="?trimestre=3<?= !empty($classe_selectionnee) ? '&classe=' . urlencode($classe_selectionnee) : '' ?>" class="<?= $trimestre_selectionne == 3 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-alt"></i> Trimestre 3
                    </a>
                </div>
            </div>
            
            <!-- Classes -->
            <?php if (isAdmin() || isTeacher() || isVieScolaire()): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-header">Classes</div>
                <div class="folder-menu">
                    <?php foreach ($classes as $classe): ?>
                    <a href="?classe=<?= urlencode($classe) ?>&trimestre=<?= $trimestre_selectionne ?>" class="<?= $classe_selectionnee === $classe ? 'active' : '' ?>">
                        <i class="fas fa-users"></i> <?= htmlspecialchars($classe) ?>
>>>>>>> design
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <?php if (canManageNotes()): ?>
            <div class="sidebar-section">
<<<<<<< HEAD
                <h3 class="sidebar-section-header">Actions</h3>
                <a href="ajouter_note.php" class="action-button">
                    <i class="fas fa-plus"></i> Ajouter une note
                </a>
                <a href="statistiques.php" class="action-button secondary">
=======
                <div class="sidebar-section-header">Actions</div>
                <a href="ajouter_note.php" class="create-button">
                    <i class="fas fa-plus"></i> Ajouter une note
                </a>
                <a href="statistiques.php" class="button button-secondary">
>>>>>>> design
                    <i class="fas fa-chart-bar"></i> Statistiques
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Autres modules -->
            <div class="sidebar-section">
<<<<<<< HEAD
                <h3 class="sidebar-section-header">Autres modules</h3>
                <div class="sidebar-nav">
                    <a href="../messagerie/index.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
                        <span>Messagerie</span>
                    </a>
                    <a href="../absences/absences.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
                        <span>Absences</span>
                    </a>
                    <a href="../agenda/agenda.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
                        <span>Agenda</span>
                    </a>
                    <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-book"></i></span>
                        <span>Cahier de textes</span>
                    </a>
                    <a href="../accueil/accueil.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
                        <span>Accueil</span>
=======
                <div class="sidebar-section-header">Autres modules</div>
                <div class="folder-menu">
                    <a href="../messagerie/index.php" class="module-link">
                        <i class="fas fa-envelope"></i> Messagerie
                    </a>
                    <a href="../absences/absences.php" class="module-link">
                        <i class="fas fa-calendar-times"></i> Absences
                    </a>
                    <a href="../agenda/agenda.php" class="module-link">
                        <i class="fas fa-calendar"></i> Agenda
                    </a>
                    <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
                        <i class="fas fa-book"></i> Cahier de textes
                    </a>
                    <a href="../accueil/accueil.php" class="module-link">
                        <i class="fas fa-home"></i> Accueil
>>>>>>> design
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="top-header">
                <div class="page-title">
                    <h1>Gestion des notes</h1>
                    <p class="subtitle">
                        <?php if (!empty($classe_selectionnee)): ?>
                            Classe <?= htmlspecialchars($classe_selectionnee) ?> - 
                        <?php endif; ?>
                        Trimestre <?= $trimestre_selectionne ?>
                    </p>
                </div>
                <div class="header-actions">
                    <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
                    <div class="user-avatar"><?= $user_initials ?></div>
                </div>
            </div>
            
            <!-- Contenu principal -->
            <div class="content-container">
                <?php if (empty($notes)): ?>
                    <div class="notes-container">
                        <div class="notes-header">
                            <h2>Aucune note disponible pour cette période</h2>
                        </div>
                        <div style="padding: 20px; text-align: center;">
                            <p>Il n'y a pas encore de notes enregistrées pour le trimestre <?= $trimestre_selectionne ?>.</p>
                            <?php if (canManageNotes()): ?>
                                <a href="ajouter_note.php" class="btn btn-primary" style="margin-top: 15px; display: inline-block;">
                                    <i class="fas fa-plus"></i> Ajouter une note
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (isStudent() || isParent()): ?>
                        <!-- Affichage pour élèves et parents: carte avec moyenne et notes par matière -->
                        <div class="eleve-card">
                            <div class="eleve-card-header">
                                <h3>
                                    <?php if (isParent() && isset($notes[0])): ?>
                                        Notes de <?= htmlspecialchars($notes[0]['nom_eleve']) ?>
                                    <?php else: ?>
                                        Mes notes
                                    <?php endif; ?>
                                </h3>
                                <div class="eleve-moyenne"><?= $moyenne_generale ?>/20</div>
                            </div>
                            <div class="eleve-card-body">
                                <?php foreach ($notes_par_matiere as $matiere => $notes_matiere): ?>
                                    <div class="matiere-section">
                                        <div class="matiere-header" onclick="toggleMatiereNotes(this)">
                                            <h4>
                                                <?php
                                                $color_class = $couleurs_matieres[str_replace(' ', '-', $matiere)] ?? 'default';
                                                ?>
                                                <span class="matiere-indicator color-<?= $color_class ?>"></span>
                                                <?= htmlspecialchars($matiere) ?>
                                            </h4>
                                            <div class="matiere-moyenne"><?= $moyennes_par_matiere[$matiere] ?>/20</div>
                                        </div>
                                        <div class="matiere-notes" style="display: none;">
                                            <?php foreach ($notes_matiere as $note): ?>
                                                <div class="note-item">
                                                    <div class="note-date">
                                                        <?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_ajout'] ?? $note['date_creation'] ?? date('Y-m-d'))) ?>
                                                    </div>
                                                    <div class="note-desc">
                                                        <?= htmlspecialchars($note['description'] ?? 'Évaluation') ?>
                                                    </div>
                                                    <div class="note-prof">
                                                        <?= htmlspecialchars($note['nom_professeur']) ?>
                                                    </div>
                                                    <div class="note-coef">
                                                        coef. <?= htmlspecialchars($note['coefficient'] ?? '1') ?>
                                                    </div>
                                                    <div class="note-val">
                                                        <?= htmlspecialchars($note['note']) ?>/<?= htmlspecialchars($note['note_sur'] ?? '20') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Affichage pour professeurs et administration: tableau des notes -->
                        <div class="notes-container">
                            <table class="notes-list">
                                <thead>
                                    <tr class="notes-header-row">
                                        <th class="notes-header-cell">Élève</th>
                                        <th class="notes-header-cell">Classe</th>
                                        <th class="notes-header-cell">Matière</th>
                                        <th class="notes-header-cell">Évaluation</th>
                                        <th class="notes-header-cell">Date</th>
                                        <th class="notes-header-cell">Coefficient</th>
                                        <th class="notes-header-cell">Note</th>
                                        <th class="notes-header-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notes as $note): ?>
                                        <tr class="notes-row">
                                            <td class="notes-cell"><?= htmlspecialchars($note['nom_eleve']) ?></td>
                                            <td class="notes-cell"><?= htmlspecialchars($note['classe']) ?></td>
                                            <td class="notes-cell"><?= htmlspecialchars($note['matiere']) ?></td>
                                            <td class="notes-cell"><?= htmlspecialchars($note['description'] ?? 'Évaluation') ?></td>
                                            <td class="notes-cell">
                                                <?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_ajout'] ?? $note['date_creation'] ?? date('Y-m-d'))) ?>
                                            </td>
                                            <td class="notes-cell"><?= htmlspecialchars($note['coefficient'] ?? '1') ?></td>
                                            <td class="notes-cell">
                                                <span class="note-value"><?= htmlspecialchars($note['note']) ?>/<?= htmlspecialchars($note['note_sur'] ?? '20') ?></span>
                                            </td>
                                            <td class="notes-cell">
                                                <div class="note-actions">
                                                    <a href="modifier_note.php?id=<?= $note['id'] ?>" class="btn-icon" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="supprimer_note.php?id=<?= $note['id'] ?>" class="btn-icon btn-danger" title="Supprimer">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    // Fonction pour afficher/masquer les notes par matière
    function toggleMatiereNotes(element) {
        const notesContainer = element.nextElementSibling;
        if (notesContainer.style.display === 'none') {
            notesContainer.style.display = 'block';
        } else {
            notesContainer.style.display = 'none';
        }
    }
    </script>
</body>
</html>
<?php
// Vider la mémoire tampon
ob_end_flush();
?>