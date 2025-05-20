<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Charger le système d'authentification
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    // Rediriger vers la page de login
    $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : '../login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
if (!$user) {
    // Double vérification, ne devrait pas arriver
    $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : '../login/public/index.php';
    header('Location: ' . $loginUrl);
    exit;
}

// Informations utilisateur
$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Vérifier si la table notes existe
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'notes'");
    $table_exists = $check_table && $check_table->rowCount() > 0;
} catch (PDOException $e) {
    error_log("Erreur lors de la vérification de la table notes: " . $e->getMessage());
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
            `date_evaluation` DATE DEFAULT NULL,
            `coefficient` INT DEFAULT 1,
            `trimestre` INT DEFAULT 1
        )";
        $pdo->exec($create_table);
        $table_exists = true;
    } catch (PDOException $e) {
        error_log("Erreur lors de la création de la table notes: " . $e->getMessage());
    }
}

// Récupérer les paramètres de filtrage
$classe_filtre = filter_input(INPUT_GET, 'classe', FILTER_SANITIZE_STRING);
$matiere_filtre = filter_input(INPUT_GET, 'matiere', FILTER_SANITIZE_STRING);
$trimestre_filtre = filter_input(INPUT_GET, 'trimestre', FILTER_VALIDATE_INT) ?: getCurrentTrimester();

// Fonction pour déterminer le trimestre actuel
function getCurrentTrimester() {
    $current_month = (int)date('n'); // 1-12
    if ($current_month >= 9 && $current_month <= 12) {
        return 1; // Septembre-Décembre
    } elseif ($current_month >= 1 && $current_month <= 3) {
        return 2; // Janvier-Mars
    } else {
        return 3; // Avril-Août
    }
}

// Préparer la requête SQL en fonction du rôle et des filtres
$sql_params = [];
$sql = "SELECT * FROM notes WHERE 1=1";

// Filtrage par classe si spécifié
if (!empty($classe_filtre)) {
    $sql .= " AND classe = ?";
    $sql_params[] = $classe_filtre;
}

// Filtrage par matière si spécifié
if (!empty($matiere_filtre)) {
    $sql .= " AND matiere = ?";
    $sql_params[] = $matiere_filtre;
}

// Filtrage par trimestre si spécifié
if (!empty($trimestre_filtre)) {
    $sql .= " AND trimestre = ?";
    $sql_params[] = $trimestre_filtre;
}

// Pour les élèves, afficher uniquement leurs propres notes
if (isStudent()) {
    $sql .= " AND nom_eleve = ?";
    $sql_params[] = $user['prenom'];
} 
// Pour les professeurs (non admin), afficher uniquement leurs notes
elseif (isTeacher() && !isAdmin()) {
    $sql .= " AND nom_professeur = ?";
    $sql_params[] = $user_fullname;
}
// Pour les parents, afficher uniquement les notes de leurs enfants
elseif (isParent()) {
    // Récupérer la liste des enfants du parent
    $stmt_enfants = $pdo->prepare("
        SELECT e.nom, e.prenom 
        FROM eleves e 
        JOIN parents_eleves pe ON e.id = pe.id_eleve 
        WHERE pe.id_parent = ?
    ");
    $stmt_enfants->execute([$user['id']]);
    $enfants = $stmt_enfants->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($enfants)) {
        $sql .= " AND 1=0"; // Aucun enfant, donc aucune note à afficher
    } else {
        $conditions = [];
        foreach ($enfants as $enfant) {
            $nom_complet = $enfant['prenom'] . ' ' . $enfant['nom'];
            $conditions[] = "nom_eleve = ?";
            $sql_params[] = $nom_complet;
        }
        $sql .= " AND (" . implode(" OR ", $conditions) . ")";
    }
}

// Ordre de tri
$sql .= " ORDER BY matiere ASC, date_creation DESC";

// Exécuter la requête
$notes = [];
if ($table_exists) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sql_params);
        $notes = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des notes: " . $e->getMessage());
    }
}

// Récupérer la liste des classes et matières pour les filtres
$classes = [];
$matieres = [];
if ($table_exists) {
    try {
        $stmt_classes = $pdo->query('SELECT DISTINCT classe FROM notes ORDER BY classe');
        $classes = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt_matieres = $pdo->query('SELECT DISTINCT matiere FROM notes ORDER BY matiere');
        $matieres = $stmt_matieres->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des filtres: " . $e->getMessage());
    }
}

// Récupérer les messages de succès ou d'erreur
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';

// Effacer les messages après les avoir récupérés
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Calculer la moyenne par matière
$notes_par_matiere = [];
$moyennes_par_matiere = [];

foreach ($notes as $note) {
    $matiere = $note['matiere'];
    if (!isset($notes_par_matiere[$matiere])) {
        $notes_par_matiere[$matiere] = [];
    }
    $notes_par_matiere[$matiere][] = $note;
}

foreach ($notes_par_matiere as $matiere => $notes_matiere) {
    $total_pondere = 0;
    $total_coefficients = 0;
    
    foreach ($notes_matiere as $note) {
        $coef = isset($note['coefficient']) ? $note['coefficient'] : 1;
        $total_pondere += $note['note'] * $coef;
        $total_coefficients += $coef;
    }
    
    if ($total_coefficients > 0) {
        $moyennes_par_matiere[$matiere] = round($total_pondere / $total_coefficients, 1);
    } else {
        $moyennes_par_matiere[$matiere] = 'N/A';
    }
}

// Calculer la moyenne générale
$moyenne_generale = 0;
$total_coef_general = 0;

foreach ($moyennes_par_matiere as $matiere => $moyenne) {
    if ($moyenne !== 'N/A') {
        $moyenne_generale += $moyenne;
        $total_coef_general += 1; // Chaque matière a un poids égal dans la moyenne générale
    }
}

if ($total_coef_general > 0) {
    $moyenne_generale = round($moyenne_generale / $total_coef_general, 1);
} else {
    $moyenne_generale = 'N/A';
}

// Récupérer le trimestre actuel pour l'affichage
$trimestre_actuel = getCurrentTrimester();

// Fonction pour déterminer la classe CSS en fonction de la valeur de la note
function getNoteClass($note) {
    $note_value = floatval($note);
    if ($note_value >= 14) {
        return 'good';
    } elseif ($note_value >= 10) {
        return 'average';
    } else {
        return 'bad';
    }
}

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
    <title>Notes - PRONOTE</title>
    <link rel="stylesheet" href="assets/css/notes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- Menu mobile -->
        <div class="mobile-menu-toggle" id="mobile-menu-toggle">
            <i class="fas fa-bars"></i>
        </div>
        <div class="page-overlay" id="page-overlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="logo-container">
                <div class="app-logo">P</div>
                <div class="app-title">PRONOTE</div>
            </div>
            
            <!-- Navigation -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Navigation</div>
                <div class="sidebar-nav">
                    <a href="../accueil/accueil.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
                        <span>Accueil</span>
                    </a>
                    <a href="notes.php" class="sidebar-nav-item active">
                        <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
                        <span>Notes</span>
                    </a>
                    <a href="../agenda/agenda.php" class="sidebar-nav-item">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
                        <span>Agenda</span>
                    </a>
                    <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-nav-item">
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
            
            <!-- Périodes -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Périodes</div>
                <div class="sidebar-nav">
                    <a href="?trimestre=1<?= !empty($classe_filtre) ? '&classe=' . urlencode($classe_filtre) : '' ?><?= !empty($matiere_filtre) ? '&matiere=' . urlencode($matiere_filtre) : '' ?>" class="sidebar-nav-item <?= $trimestre_filtre == 1 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 1</span>
                    </a>
                    <a href="?trimestre=2<?= !empty($classe_filtre) ? '&classe=' . urlencode($classe_filtre) : '' ?><?= !empty($matiere_filtre) ? '&matiere=' . urlencode($matiere_filtre) : '' ?>" class="sidebar-nav-item <?= $trimestre_filtre == 2 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 2</span>
                    </a>
                    <a href="?trimestre=3<?= !empty($classe_filtre) ? '&classe=' . urlencode($classe_filtre) : '' ?><?= !empty($matiere_filtre) ? '&matiere=' . urlencode($matiere_filtre) : '' ?>" class="sidebar-nav-item <?= $trimestre_filtre == 3 ? 'active' : '' ?>">
                        <span class="sidebar-nav-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Trimestre 3</span>
                    </a>
                </div>
            </div>

            <?php if (canManageNotes()): ?>
            <!-- Actions spécifiques -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Actions</div>
                <div class="sidebar-nav">
                    <a href="ajouter_note.php" class="create-button">
                        <i class="fas fa-plus"></i> Ajouter une note
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Informations -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Informations</div>
                <div class="info-item">
                    <div class="info-label">Date</div>
                    <div class="info-value"><?= date('d/m/Y') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Période</div>
                    <div class="info-value"><?= $trimestre_filtre ?>ème trimestre</div>
                </div>
                <?php if (isset($moyenne_generale)): ?>
                <div class="info-item">
                    <div class="info-label">Moyenne générale</div>
                    <div class="info-value"><?= number_format($moyenne_generale, 2) ?>/20</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="top-header">
                <div class="page-title">
                    <h1>Notes</h1>
                    <?php if (!empty($classe_filtre)): ?>
                    <div class="subtitle">Classe: <?= htmlspecialchars($classe_filtre) ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="header-actions">
                    <a href="../login/public/logout.php" class="logout-button" title="Déconnexion">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                    <div class="user-avatar" title="<?= htmlspecialchars($nom_complet) ?>"><?= $user_initials ?></div>
                </div>
            </div>

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <h2>Notes et évaluations</h2>
                    <p>Consultez vos notes et moyennes pour le <?= $trimestre_filtre ?>ème trimestre</p>
                    <?php if (isset($moyenne_generale)): ?>
                    <p>Moyenne générale: <span class="moyenne-generale"><?= number_format($moyenne_generale, 2) ?>/20</span></p>
                    <?php endif; ?>
                </div>
                <div class="welcome-logo">
                    <i class="fas fa-chart-bar"></i>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="filter-toolbar">
                <div class="filter-buttons">
                    <?php if (!empty($classes)): ?>
                    <select class="btn btn-secondary" id="classe-select" onchange="window.location.href='?trimestre=<?= $trimestre_filtre ?>&classe='+this.value<?= !empty($matiere_filtre) ? '&matiere='.urlencode($matiere_filtre) : '' ?>">
                        <option value="">Toutes les classes</option>
                        <?php foreach ($classes as $classe): ?>
                        <option value="<?= htmlspecialchars($classe) ?>" <?= $classe_filtre === $classe ? 'selected' : '' ?>><?= htmlspecialchars($classe) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    
                    <?php if (!empty($matieres)): ?>
                    <select class="btn btn-secondary" id="matiere-select" onchange="window.location.href='?trimestre=<?= $trimestre_filtre ?><?= !empty($classe_filtre) ? '&classe='.urlencode($classe_filtre) : '' ?>&matiere='+this.value">
                        <option value="">Toutes les matières</option>
                        <?php foreach ($matieres as $matiere): ?>
                        <option value="<?= htmlspecialchars($matiere) ?>" <?= $matiere_filtre === $matiere ? 'selected' : '' ?>><?= htmlspecialchars($matiere) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                
                <?php if (canManageNotes()): ?>
                <div>
                    <a href="ajouter_note.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter une note
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Messages de succès ou d'erreur -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert-banner alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['success_message']) ?></div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert-banner alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['error_message']) ?></div>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Contenu principal -->
            <div class="content-container">
                <!-- Distribution des notes -->
                <?php if (isset($moyenne_generale)): ?>
                <div class="grade-distribution">
                    <div class="grade-section low">
                        <div class="grade-section-value"><?= $count_under_10 ?></div>
                        <div class="grade-section-label">Notes < 10/20</div>
                    </div>
                    <div class="grade-section mid">
                        <div class="grade-section-value"><?= $count_10_to_15 ?></div>
                        <div class="grade-section-label">Notes entre 10 et 15</div>
                    </div>
                    <div class="grade-section high">
                        <div class="grade-section-value"><?= $count_over_15 ?></div>
                        <div class="grade-section-label">Notes > 15/20</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Notes par matière -->
                <div class="notes-container">
                    <?php if (empty($notes_par_matiere)): ?>
                        <div class="alert-banner alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>Aucune note n'a été ajoutée pour le moment.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notes_par_matiere as $matiere => $notes): ?>
                            <?php 
                            $classe_couleur = isset($couleurs_matieres[$matiere]) ? $couleurs_matieres[$matiere] : 'default';
                            $moyenne_matiere = calculerMoyenne($notes);
                            ?>
                            <div class="matiere-card" data-matiere="<?= htmlspecialchars($matiere) ?>">
                                <div class="matiere-header">
                                    <div class="matiere-title">
                                        <span class="matiere-indicator color-<?= $classe_couleur ?>"></span>
                                        <?= htmlspecialchars($matiere) ?>
                                    </div>
                                    <div class="matiere-moyenne"><?= number_format($moyenne_matiere, 2) ?>/20</div>
                                </div>
                                <div class="notes-list">
                                    <?php foreach ($notes as $note): ?>
                                        <?php 
                                        $note_class = '';
                                        if ($note['note'] < 10) $note_class = 'bad';
                                        elseif ($note['note'] >= 10 && $note['note'] < 15) $note_class = 'average';
                                        else $note_class = 'good';
                                        ?>
                                        <div class="note-item">
                                            <div class="note-date"><?= date('d/m/Y', strtotime($note['date_ajout'])) ?></div>
                                            <div class="note-description"><?= htmlspecialchars($note['commentaire']) ?></div>
                                            <div class="note-coefficient">Coef. <?= $note['coefficient'] ?></div>
                                            <div class="note-value <?= $note_class ?>"><?= number_format($note['note'], 1) ?>/20</div>
                                            
                                            <?php if (canManageNotes() && 
                                                    (isAdmin() || 
                                                     isSchoolLife() || 
                                                     (isTeacher() && $note['nom_professeur'] == $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']))): ?>
                                            <div class="note-actions">
                                                <a href="modifier_note.php?id=<?= $note['id'] ?>" class="btn-icon" title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="supprimer_note.php?id=<?= $note['id'] ?>" class="btn-icon btn-danger" title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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

    <script>
        // Navigation mobile
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const pageOverlay = document.getElementById('page-overlay');
            
            if (mobileMenuToggle && sidebar && pageOverlay) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-visible');
                    pageOverlay.classList.toggle('visible');
                });
                
                pageOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-visible');
                    pageOverlay.classList.remove('visible');
                });
            }
            
            // Auto-masquer les messages d'alerte après 5 secondes
            document.querySelectorAll('.alert-banner').forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });
    </script>

</body>
</html>
<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>