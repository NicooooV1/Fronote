<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Charger le système d'authentification central
require_once __DIR__ . '/../API/auth_central.php';
require_once 'includes/db.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    // Rediriger vers la page de login, en utilisant la constante dynamique
    header('Location: ' . LOGIN_URL);
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = getCurrentUser();
if (!$user) {
    // Double vérification, ne devrait pas arriver
    header('Location: ' . LOGIN_URL);
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
// Pour les parents, afficher uniquement les notes de leurs enfants (à implémenter)
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
            <a href="<?= HOME_URL ?>" class="logo-container">
                <div class="app-logo">P</div>
                <div class="app-title">Pronote Notes</div>
            </a>
            
            <!-- Filtres -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Filtres</div>
                <form id="filter-form" method="get" action="">
                    <div class="form-group">
                        <label for="classe">Classe</label>
                        <select id="classe" name="classe" onchange="this.form.submit()">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?= htmlspecialchars($classe) ?>" <?= $classe_filtre === $classe ? 'selected' : '' ?>><?= htmlspecialchars($classe) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="matiere">Matière</label>
                        <select id="matiere" name="matiere" onchange="this.form.submit()">
                            <option value="">Toutes les matières</option>
                            <?php foreach ($matieres as $matiere): ?>
                                <option value="<?= htmlspecialchars($matiere) ?>" <?= $matiere_filtre === $matiere ? 'selected' : '' ?>><?= htmlspecialchars($matiere) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="trimestre">Trimestre</label>
                        <select id="trimestre" name="trimestre" onchange="this.form.submit()">
                            <option value="">Tous les trimestres</option>
                            <option value="1" <?= $trimestre_filtre === 1 ? 'selected' : '' ?>>Trimestre 1</option>
                            <option value="2" <?= $trimestre_filtre === 2 ? 'selected' : '' ?>>Trimestre 2</option>
                            <option value="3" <?= $trimestre_filtre === 3 ? 'selected' : '' ?>>Trimestre 3</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Actions -->
            <?php if (canManageNotes()): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-header">Actions</div>
                <a href="ajouter_note.php" class="action-button">
                    <i class="fas fa-plus"></i> Ajouter une note
                </a>
                <?php if (isAdmin()): ?>
                <a href="inserer_ou_modifier_structure.php" class="action-button secondary">
                    <i class="fas fa-database"></i> Maintenance DB
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Autres modules -->
            <div class="sidebar-section">
                <div class="sidebar-section-header">Autres modules</div>
                <a href="../cahierdetextes/cahierdetextes.php" class="module-link">
                    <i class="fas fa-book"></i> Cahier de textes
                </a>
                <a href="../absences/absences.php" class="module-link">
                    <i class="fas fa-calendar-check"></i> Absences
                </a>
                <a href="../agenda/agenda.php" class="module-link">
                    <i class="fas fa-calendar"></i> Agenda
                </a>
                <a href="../messagerie/index.php" class="module-link">
                    <i class="fas fa-envelope"></i> Messagerie
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="top-header">
                <div class="page-title">
                    <h1>Notes</h1>
                </div>
                
                <div class="header-actions">
                    <a href="<?= LOGOUT_URL ?>" class="logout-button" title="Déconnexion">⏻</a>
                    <div class="user-avatar"><?= $user_initials ?></div>
                </div>
            </div>
            
            <!-- Content -->
            <div class="content-container">
                <!-- Messages de feedback -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($notes)): ?>
                    <div class="no-data-message">
                        <i class="fas fa-info-circle"></i>
                        <p>Aucune note ne correspond aux critères sélectionnés.</p>
                    </div>
                <?php else: ?>
                    <!-- Tableau des notes -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                                        <th>Élève</th>
                                        <th>Classe</th>
                                    <?php endif; ?>
                                    <th>Matière</th>
                                    <th>Note</th>
                                    <th>Sur</th>
                                    <th>Coef.</th>
                                    <th>Date</th>
                                    <th>Professeur</th>
                                    <?php if (canManageNotes()): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notes as $note): ?>
                                    <tr>
                                        <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
                                            <td><?= htmlspecialchars($note['nom_eleve']) ?></td>
                                            <td><?= htmlspecialchars($note['classe']) ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($note['matiere']) ?></td>
                                        <td><?= htmlspecialchars($note['note']) ?></td>
                                        <td><?= htmlspecialchars($note['note_sur']) ?></td>
                                        <td><?= htmlspecialchars($note['coefficient']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($note['date_evaluation'] ?? $note['date_creation'])) ?></td>
                                        <td><?= htmlspecialchars($note['nom_professeur']) ?></td>
                                        <?php if (canManageNotes()): ?>
                                            <td class="actions-cell">
                                                <a href="modifier_note.php?id=<?= $note['id'] ?>" class="btn-icon" title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="supprimer_note.php?id=<?= $note['id'] ?>" class="btn-icon btn-danger" title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        // Script pour ajouter de l'interactivité aux filtres (facultatif)
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit du formulaire lors du changement des sélecteurs
            document.querySelectorAll('#filter-form select').forEach(function(select) {
                select.addEventListener('change', function() {
                    document.getElementById('filter-form').submit();
                });
            });
        });
    </script>
</body>
</html>
<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>