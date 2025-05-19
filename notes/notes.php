<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Charger le système d'authentification central
require_once __DIR__ . '/../API/auth_central.php';
require_once 'includes/db.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    // Rediriger vers la page de login, en utilisant la constante dynamique
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

$user_fullname = getUserFullName();
$user_role = getUserRole();
$user_initials = strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1));

// Journalisation des événements
error_log("Utilisateur {$user_fullname} a accédé au module des notes");

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
$trimestre_filtre = filter_input(INPUT_GET, 'trimestre', FILTER_VALIDATE_INT) ?: null;

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
    $sql_params[] = $user_fullname;
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
$sql .= " ORDER BY date_creation DESC";

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

// Fonction pour formater l'affichage des notes
function formatNoteDisplay($note) {
    global $note_affichage;
    
    // Assurer que la note est un nombre
    $note_value = floatval($note['note']);
    
    // Utiliser une valeur par défaut de 20 si note_sur n'est pas défini
    $note_sur = isset($note['note_sur']) ? intval($note['note_sur']) : 20;
    
    // Formater selon le paramètre global
    if (isset($note_affichage) && $note_affichage === 'sur_vingt') {
        // Convertir la note sur 20 si nécessaire
        if ($note_sur != 20 && $note_sur > 0) {
            $note_value = ($note_value / $note_sur) * 20;
            return number_format($note_value, 1) . '/20';
        } else {
            return number_format($note_value, 1) . '/20';
        }
    } else {
        // Afficher la note telle quelle
        return $note_value . '/' . $note_sur;
    }
}

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

// Définir la configuration de la page
$pageTitle = "Notes";
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
        /* Styles spécifiques au module Notes */
        .note-value {
            font-weight: 600;
            color: var(--accent-notes);
        }
        
        .filter-form {
            background-color: var(--white);
            padding: var(--space-md);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-light);
            margin-bottom: var(--space-lg);
        }
        
        .filter-form .form-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-md);
        }
        
        .matiere-section {
            border-bottom: 1px solid var(--border-color);
        }

        .matiere-header {
            padding: var(--space-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            background-color: rgba(255, 149, 0, 0.05);
        }

        .matiere-header:hover {
            background-color: rgba(255, 149, 0, 0.1);
        }

        .matiere-icon {
            margin-right: var(--space-sm);
            color: var(--accent-notes);
        }

        .matiere-title {
            font-weight: 600;
            color: var(--text-color);
        }

        .matiere-moyenne {
            font-weight: 700;
            color: var(--accent-notes);
        }

        .notes-list {
            padding: 0;
        }

        .note-item {
            display: flex;
            align-items: center;
            padding: var(--space-sm) var(--space-md);
            border-bottom: 1px solid var(--border-color);
        }

        .note-item:last-child {
            border-bottom: none;
        }

        .note-date {
            width: 100px;
            color: var(--text-muted);
            font-size: 14px;
        }

        .note-description {
            flex: 1;
            font-weight: 500;
        }

        .note-coefficient {
            width: 80px;
            text-align: center;
            color: var(--text-light);
        }

        .moyenne-generale {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-notes);
            background-color: rgba(255, 149, 0, 0.1);
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-md);
            display: inline-block;
        }

        .no-data-message {
            text-align: center;
            padding: var(--space-lg);
            background-color: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-light);
        }
        
        .no-data-message i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: var(--space-sm);
        }
        
        @media (max-width: 768px) {
            .filter-form .form-grid {
                grid-template-columns: 1fr;
            }
            
            .note-item {
                flex-wrap: wrap;
            }
            
            .note-date {
                width: 80px;
            }
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
                <a href="../notes/notes.php" class="sidebar-link active">
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
            
            <!-- Filtres -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Filtres</div>
                <form id="filter-form" method="get" action="">
                    <div class="form-group">
                        <label for="trimestre">Trimestre</label>
                        <select id="trimestre" name="trimestre" class="form-select" onchange="this.form.submit()">
                            <option value="">Tous les trimestres</option>
                            <option value="1" <?= $trimestre_filtre === 1 ? 'selected' : '' ?>>Trimestre 1</option>
                            <option value="2" <?= $trimestre_filtre === 2 ? 'selected' : '' ?>>Trimestre 2</option>
                            <option value="3" <?= $trimestre_filtre === 3 ? 'selected' : '' ?>>Trimestre 3</option>
                        </select>
                    </div>
                    
                    <?php if (isAdmin() || isTeacher() || isVieScolaire()): ?>
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select id="classe" name="classe" class="form-select" onchange="this.form.submit()">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?= htmlspecialchars($classe) ?>" <?= $classe_filtre === $classe ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($classe) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="matiere">Matière</label>
                        <select id="matiere" name="matiere" class="form-select" onchange="this.form.submit()">
                            <option value="">Toutes les matières</option>
                            <?php foreach ($matieres as $matiere): ?>
                                <option value="<?= htmlspecialchars($matiere) ?>" <?= $matiere_filtre === $matiere ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($matiere) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Actions -->
            <?php if (canManageNotes()): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-header">Actions</div>
                <a href="ajouter_note.php" class="create-button">
                    <i class="fas fa-plus"></i> Ajouter une note
                </a>
                <?php if (isAdmin()): ?>
                <a href="inserer_ou_modifier_structure.php" class="create-button">
                    <i class="fas fa-database"></i> Maintenance DB
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
                <!-- Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?= htmlspecialchars($success_message) ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= htmlspecialchars($error_message) ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($notes)): ?>
                    <div class="no-data-message">
                        <i class="fas fa-info-circle"></i>
                        <h3>Aucune note disponible</h3>
                        <p>Aucune note ne correspond aux critères sélectionnés.</p>
                    </div>
                <?php else: ?>
                    <!-- Affichage des notes par matière pour les élèves et parents -->
                    <?php if (isStudent() || isParent()): ?>
                        <div class="widget mb-4">
                            <div class="widget-header">
                                <h2 class="widget-title">Moyenne générale</h2>
                                <span class="moyenne-generale"><?= $moyenne_generale ?>/20</span>
                            </div>
                        </div>
                        
                        <div class="widget">
                            <div class="widget-header">
                                <h2 class="widget-title">Détail des notes par matière</h2>
                            </div>
                            <div class="widget-content p-0">
                                <?php foreach ($notes_par_matiere as $matiere => $notes_matiere): ?>
                                    <div class="matiere-section">
                                        <div class="matiere-header" onclick="toggleNotes(this)">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-chevron-right matiere-icon"></i>
                                                <span class="matiere-title"><?= htmlspecialchars($matiere) ?></span>
                                            </div>
                                            <div class="matiere-moyenne"><?= $moyennes_par_matiere[$matiere] ?>/20</div>
                                        </div>
                                        <div class="notes-list" style="display: none;">
                                            <?php foreach ($notes_matiere as $note): ?>
                                                <div class="note-item">
                                                    <div class="note-date">
                                                        <?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_creation'])) ?>
                                                    </div>
                                                    <div class="note-description">
                                                        <?= !empty($note['commentaire']) ? htmlspecialchars($note['commentaire']) : 'Évaluation' ?>
                                                    </div>
                                                    <div class="note-coefficient">
                                                        Coef. <?= htmlspecialchars($note['coefficient']) ?>
                                                    </div>
                                                    <div class="note-value">
                                                        <?= formatNoteDisplay($note) ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Tableau des notes pour les professeurs et administrateurs -->
                        <div class="widget">
                            <div class="widget-header">
                                <h2 class="widget-title">Liste des notes</h2>
                                <?php if (canManageNotes()): ?>
                                    <a href="ajouter_note.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus"></i> Ajouter
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="widget-content p-0">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Élève</th>
                                                <th>Classe</th>
                                                <th>Matière</th>
                                                <th>Note</th>
                                                <th>Coeff.</th>
                                                <th>Date</th>
                                                <th>Description</th>
                                                <?php if (canManageNotes()): ?>
                                                    <th>Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($notes as $note): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($note['nom_eleve']) ?></td>
                                                    <td><?= htmlspecialchars($note['classe']) ?></td>
                                                    <td><?= htmlspecialchars($note['matiere']) ?></td>
                                                    <td class="note-value"><?= formatNoteDisplay($note) ?></td>
                                                    <td><?= htmlspecialchars($note['coefficient']) ?></td>
                                                    <td><?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_creation'])) ?></td>
                                                    <td><?= htmlspecialchars($note['commentaire'] ?? '') ?></td>
                                                    <?php if (canManageNotes()): ?>
                                                        <td>
                                                            <div class="d-flex">
                                                                <a href="modifier_note.php?id=<?= $note['id'] ?>" class="btn-icon" title="Modifier">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="supprimer_note.php?id=<?= $note['id'] ?>" class="btn-icon btn-danger" title="Supprimer" onclick="return confirm('Voulez-vous vraiment supprimer cette note ?');">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function toggleNotes(header) {
            const notesList = header.nextElementSibling;
            const icon = header.querySelector('.matiere-icon');
            
            if (notesList.style.display === 'none') {
                notesList.style.display = 'block';
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
            } else {
                notesList.style.display = 'none';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
            }
        }
    </script>
</body>
</html>
<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>