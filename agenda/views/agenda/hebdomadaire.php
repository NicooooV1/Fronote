<?php 
// Vérifications de sécurité
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$userType = $_SESSION['user_type'];
$canModify = isset($canModify) ? $canModify : false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Pronote Web</title>
    <link rel="stylesheet" href="/public/css/agenda.css">
    <link rel="stylesheet" href="/public/css/responsive.css">
</head>
<body data-can-modify="<?= $canModify ? 'true' : 'false' ?>">
    <?php include __DIR__ . '/../shared/header.php'; ?>
    
    <main class="container">
        <div class="toolbar">
            <div class="navigation">
                <button id="previous-period" class="nav-button">
                    <span class="icon">←</span> Précédent
                </button>
                <button id="today-button" class="nav-button">
                    Aujourd'hui
                </button>
                <button id="next-period" class="nav-button">
                    Suivant <span class="icon">→</span>
                </button>
                <div id="current-period" class="period-display">
                    <!-- Période actuelle (rempli par JS) -->
                </div>
            </div>
            <div class="view-selector">
                <button id="view-day" class="view-button">Jour</button>
                <button id="view-wee" class="view-button active">Semaine</button>
                <button id="view-mon" class="view-button">Mois</button>
            </div>
        </div>
        
        <div class="options">
            <label class="option">
                <input type="checkbox" id="toggle-replacements" checked>
                Afficher les remplacements
            </label>
            
            <div class="export-import">
                <button id="export-ics" class="action-button">
                    <span class="icon">↓</span> Exporter (.ics)
                </button>
                
                <?php if ($canModify): ?>
                <form id="import-form" action="/import_ics.php" method="post" enctype="multipart/form-data">
                    <input type="file" name="icsfile" id="ics-file" accept=".ics">
                    <button type="submit" class="action-button">
                        <span class="icon">↑</span> Importer
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="calendar"></div>
        
        <!-- Modal de détails d'événement -->
        <div id="modal-overlay" class="modal-overlay"></div>
        <div id="event-details-modal" class="modal">
            <div class="modal-header">
                <h3 id="modal-title"></h3>
                <span id="close-modal" class="close-button">&times;</span>
            </div>
            <div class="modal-content">
                <p id="modal-date" class="date"></p>
                <p id="modal-time" class="time"></p>
                <div id="modal-info" class="info"></div>
                
                <?php if ($canModify): ?>
                <div class="modal-actions">
                    <button id="edit-event" class="action-button">Modifier</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($canModify): ?>
        <!-- Modal d'édition d'événement -->
        <div id="event-edit-modal" class="modal">
            <div class="modal-header">
                <h3>Modifier l'événement</h3>
                <span id="close-edit-modal" class="close-button">&times;</span>
            </div>
            <div class="modal-content">
                <form id="edit-event-form">
                    <input type="hidden" id="edit-event-id">
                    <div class="form-group">
                        <label for="edit-event-sala">Salle:</label>
                        <input type="text" id="edit-event-sala" name="salle">
                    </div>
                    <div class="form-group">
                        <label for="edit-event-start">Heure de début:</label>
                        <input type="time" id="edit-event-start" name="heure_debut">
                    </div>
                    <div class="form-group">
                        <label for="edit-event-end">Heure de fin:</label>
                        <input type="time" id="edit-event-end" name="heure_fin">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="action-button">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <?php include __DIR__ . '/../shared/footer.php'; ?>
    
    <script src="/public/js/agenda.js"></script>
</body>
</html>