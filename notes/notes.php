<?php
// Démarrer la session
session_start();

// Inclusions et configuration
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Vérification de la connexion
if (!isUserLoggedIn()) {
    header('Location: /login/public/index.php');
    exit;
}

// Configuration de la page
$pageTitle = 'Notes';
$moduleClass = 'notes';
$moduleName = 'Notes et évaluations';
$moduleIcon = 'fa-chart-bar';

// Récupération du trimestre sélectionné ou utilisation du trimestre actuel par défaut
$currentMonth = (int)date('n');
if ($currentMonth >= 9 && $currentMonth <= 12) {
    $defaultTrimester = 1;
} else if ($currentMonth >= 1 && $currentMonth <= 3) {
    $defaultTrimester = 2;
} else {
    $defaultTrimester = 3;
}
$selectedTrimester = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : $defaultTrimester;

// Récupération de la classe sélectionnée (si applicable)
$selectedClass = isset($_GET['classe']) ? $_GET['classe'] : '';

// Requête pour obtenir les notes
$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['profil'];

// Déterminer la requête en fonction du rôle
$params = [];
$sql = "SELECT n.*, m.nom AS matiere_nom, m.couleur AS matiere_couleur
        FROM notes n
        LEFT JOIN matieres m ON n.matiere_id = m.id
        WHERE n.trimestre = ?";
$params[] = $selectedTrimester;

// Filtrage par user selon son rôle
if ($userRole === 'eleve') {
    $sql .= " AND n.eleve_id = ?";
    $params[] = $userId;
} else if ($userRole === 'parent') {
    $sql .= " AND n.eleve_id IN (SELECT e.id FROM eleves e 
                                JOIN parents_eleves pe ON e.id = pe.eleve_id 
                                WHERE pe.parent_id = ?)";
    $params[] = $userId;
} else if ($userRole === 'professeur') {
    $sql .= " AND n.professeur_id = ?";
    $params[] = $userId;
}

// Filtrage par classe si applicable
if (!empty($selectedClass)) {
    $sql .= " AND n.classe = ?";
    $params[] = $selectedClass;
}

// Exécution de la requête
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organisation des notes par matière
$notesBySubject = [];
$subjectAverages = [];

foreach ($notes as $note) {
    $subject = $note['matiere_nom'];
    if (!isset($notesBySubject[$subject])) {
        $notesBySubject[$subject] = [];
    }
    $notesBySubject[$subject][] = $note;
}

// Calcul des moyennes par matière
foreach ($notesBySubject as $subject => $subjectNotes) {
    $totalWeightedPoints = 0;
    $totalWeight = 0;
    
    foreach ($subjectNotes as $note) {
        $weight = $note['coefficient'] ?? 1;
        $totalWeightedPoints += $note['valeur'] * $weight;
        $totalWeight += $weight;
    }
    
    $subjectAverages[$subject] = $totalWeight > 0 ? round($totalWeightedPoints / $totalWeight, 2) : 'N/A';
}

// Calcul de la moyenne générale
$overallTotal = 0;
$overallCount = 0;

foreach ($subjectAverages as $avg) {
    if ($avg !== 'N/A') {
        $overallTotal += $avg;
        $overallCount++;
    }
}

$overallAverage = $overallCount > 0 ? round($overallTotal / $overallCount, 2) : 'N/A';

// Message de bienvenue personnalisé
$welcomeMessage = 'Consultez vos notes et moyennes pour le ' . $selectedTrimester . 'ème trimestre';

// Informations supplémentaires pour la sidebar
if ($overallAverage !== 'N/A') {
    $additionalInfoContent = '
    <div class="info-item">
        <div class="info-label">Moyenne générale</div>
        <div class="info-value">' . $overallAverage . '/20</div>
    </div>';
}

// Contenu supplémentaire pour la bannière
if ($overallAverage !== 'N/A') {
    $additionalBannerContent = '
    <p>Moyenne générale: <span class="moyenne-generale">' . $overallAverage . '/20</span></p>';
}

// Inclure l'en-tête
include '../templates/header.php';
?>

<!-- Filtres de classe (pour les professeurs et admins) -->
<?php if ($userRole === 'professeur' || $userRole === 'administrateur' || $userRole === 'vie_scolaire'): ?>
<div class="filter-toolbar">
    <div class="filter-buttons">
        <select class="form-select" onchange="window.location.href='?trimestre=<?= $selectedTrimester ?>&classe='+this.value">
            <option value="">Toutes les classes</option>
            <?php
            // Récupération des classes
            $classStmt = $pdo->prepare("SELECT DISTINCT classe FROM notes ORDER BY classe");
            $classStmt->execute();
            $classes = $classStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($classes as $class):
            ?>
            <option value="<?= htmlspecialchars($class) ?>" <?= $selectedClass === $class ? 'selected' : '' ?>>
                <?= htmlspecialchars($class) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <?php if ($userRole === 'professeur' || $userRole === 'administrateur' || $userRole === 'vie_scolaire'): ?>
    <div>
        <a href="ajouter_note.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Ajouter une note
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Contenu principal -->
<div class="content-container">
    <?php if (count($notes) === 0): ?>
        <div class="widget">
            <div class="widget-content widget-empty">
                <i class="fas fa-info-circle"></i>
                <p>Aucune note n'est disponible pour le moment.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- Distribution des notes -->
        <div class="grade-distribution">
            <div class="grade-section low">
                <div class="grade-section-value">
                    <?php 
                    $countUnder10 = 0;
                    foreach ($notes as $note) {
                        if ($note['valeur'] < 10) $countUnder10++;
                    }
                    echo $countUnder10;
                    ?>
                </div>
                <div class="grade-section-label">Notes < 10/20</div>
            </div>
            <div class="grade-section mid">
                <div class="grade-section-value">
                    <?php 
                    $count10to15 = 0;
                    foreach ($notes as $note) {
                        if ($note['valeur'] >= 10 && $note['valeur'] < 15) $count10to15++;
                    }
                    echo $count10to15;
                    ?>
                </div>
                <div class="grade-section-label">Notes entre 10 et 15</div>
            </div>
            <div class="grade-section high">
                <div class="grade-section-value">
                    <?php 
                    $countOver15 = 0;
                    foreach ($notes as $note) {
                        if ($note['valeur'] >= 15) $countOver15++;
                    }
                    echo $countOver15;
                    ?>
                </div>
                <div class="grade-section-label">Notes > 15/20</div>
            </div>
        </div>
        
        <!-- Notes par matière -->
        <?php foreach ($notesBySubject as $subject => $subjectNotes): 
            // Déterminer la couleur de la matière
            $matiere_couleur = $subjectNotes[0]['matiere_couleur'] ?? 'default';
            $moyenne_matiere = $subjectAverages[$subject];
        ?>
        <div class="matiere-card">
            <div class="matiere-header" onclick="toggleNotesList(this)">
                <div class="matiere-title">
                    <span class="matiere-indicator color-<?= $matiere_couleur ?>"></span>
                    <?= htmlspecialchars($subject) ?>
                </div>
                <div class="matiere-moyenne"><?= $moyenne_matiere ?>/20</div>
            </div>
            <div class="notes-list" style="display: none;">
                <?php foreach ($subjectNotes as $note): 
                    // Déterminer la classe de la note
                    if ($note['valeur'] < 10) {
                        $noteClass = 'bad';
                    } else if ($note['valeur'] >= 10 && $note['valeur'] < 15) {
                        $noteClass = 'average';
                    } else {
                        $noteClass = 'good';
                    }
                ?>
                <div class="note-item">
                    <div class="note-date"><?= date('d/m/Y', strtotime($note['date'])) ?></div>
                    <div class="note-description"><?= htmlspecialchars($note['description']) ?></div>
                    <div class="note-coefficient">Coef. <?= $note['coefficient'] ?></div>
                    <div class="note-value <?= $noteClass ?>"><?= number_format($note['valeur'], 1) ?>/20</div>
                    
                    <?php if ($userRole === 'professeur' || $userRole === 'administrateur' || $userRole === 'vie_scolaire'): ?>
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

<?php
// Script personnalisé pour les fonctionnalités spécifiques à cette page
$customScripts = "
function toggleNotesList(header) {
    const notesList = header.nextElementSibling;
    if (notesList.style.display === 'none') {
        notesList.style.display = 'block';
    } else {
        notesList.style.display = 'none';
    }
}";
// Inclure le pied de page
include '../templates/footer.php';
?>