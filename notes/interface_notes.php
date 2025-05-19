<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusions
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
    header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
    exit;
}

$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Vérifier si la table notes existe
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'notes'");
    $table_exists = $check_table && $check_table->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
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
$classe_selectionnee = $selected_class;

// Définir le trimestre sélectionné (si présent dans l'URL ou par défaut le premier)
$trimestre_selectionne = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;

// Récupérer toutes les matières disponibles
$matieres = [];
try {
    if ($table_exists) {
        $stmt_matieres = $pdo->query('SELECT DISTINCT matiere FROM notes ORDER BY matiere');
        $matieres = $stmt_matieres->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des matières: " . $e->getMessage());
}

// Vérifier si la colonne 'trimestre' existe
$trimestre_exists = false;
try {
    if ($table_exists) {
        $check_trimestre = $pdo->query("SHOW COLUMNS FROM notes LIKE 'trimestre'");
        $trimestre_exists = $check_trimestre && $check_trimestre->rowCount() > 0;
        
        // Si la colonne n'existe pas, l'ajouter
        if (!$trimestre_exists) {
            $pdo->exec("ALTER TABLE notes ADD COLUMN trimestre INT DEFAULT 1");
            $trimestre_exists = true;
        }
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification de la colonne trimestre: " . $e->getMessage());
}

// Récupérer les notes en fonction des filtres
$notes = [];

if ($table_exists) {
    $query = 'SELECT * FROM notes WHERE 1=1';
    $params = [];
    
    if (!empty($classe_selectionnee)) {
        $query .= ' AND classe = ?';
        $params[] = $classe_selectionnee;
    }
    
    // Filtre par trimestre (uniquement si la colonne existe)
    if ($trimestre_exists) {
        $query .= ' AND (trimestre = ? OR trimestre IS NULL)';
        $params[] = $trimestre_selectionne;
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
            $query .= ' AND 1=0';
        }
    }
    
    // Ajouter l'ordre 
    $query .= ' ORDER BY matiere ASC, date_evaluation DESC';
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des notes: " . $e->getMessage());
    }
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

// Déterminer les couleurs pour les matières
$couleurs_matieres = [
    'Français' => 'francais',
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
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes - Pronote</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <a href="../accueil/accueil.php" class="logo-container">
                <div class="app-logo">P</div>
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
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <?php if (canManageNotes()): ?>
            <div class="sidebar-section">
                <a href="ajouter_note.php" class="action-button">
                    <i class="fas fa-plus"></i> Ajouter une note
                </a>
                <a href="statistiques.php" class="action-button secondary">
                    <i class="fas fa-chart-bar"></i> Statistiques
                </a>
            </div>
            <?php endif; ?>
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
            
            <!-- Content -->
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
                                                <span class="matiere-indicator <?= 'color-' . $color_class ?>"></span>
                                                <?= htmlspecialchars($matiere) ?>
                                            </h4>
                                            <div class="matiere-moyenne"><?= $moyennes_par_matiere[$matiere] ?>/20</div>
                                        </div>
                                        <div class="matiere-notes" style="display: none;">
                                            <?php foreach ($notes_matiere as $note): ?>
                                                <div class="note-item">
                                                    <div class="note-date"><?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_ajout'])) ?></div>
                                                    <div class="note-desc"><?= htmlspecialchars($note['description'] ?? 'Évaluation') ?></div>
                                                    <div class="note-prof"><?= htmlspecialchars($note['nom_professeur']) ?></div>
                                                    <div class="note-coef">coef. <?= htmlspecialchars($note['coefficient'] ?? '1') ?></div>
                                                    <div class="note-val"><?= htmlspecialchars($note['note']) ?>/20</div>
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
                                            <td class="notes-cell"><?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_ajout'])) ?></td>
                                            <td class="notes-cell"><?= htmlspecialchars($note['coefficient'] ?? '1') ?></td>
                                            <td class="notes-cell"><span class="note-value"><?= htmlspecialchars($note['note']) ?>/20</span></td>
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