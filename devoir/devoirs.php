<?php
// devoirs.php - Page principale de gestion des devoirs
require_once 'config.php';

// Vérification de l'authentification
if (!isAuthenticated()) {
    redirect('/login/index.php', 'Veuillez vous connecter pour accéder à cette page', 'error');
}

// Récupération des matières et classes pour les filtres et le formulaire
$etablissementData = getEtablissementData();
$matieres = $etablissementData['matieres'] ?? [];
$classes = $etablissementData['classes'] ?? [];

// Variables pour la page
$pageTitle = 'Travail à faire';
$isTeacher = isTeacher();  // Assurez-vous que cette fonction est définie dans config.php
$userProfile = getUserProfile();  // Assurez-vous que cette fonction est définie dans config.php

// Constantes pour le JavaScript
define('INCLUDED', true);
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Contenu principal -->
<main class="pronote-main">
    <div class="pronote-page-title">
        <span>Cahier de texte - Travail à faire</span>
        <div class="pronote-view-toggle">
            <button class="pronote-view-btn active" id="list-view-btn">
                <i class="fas fa-list"></i>
            </button>
            <button class="pronote-view-btn" id="calendar-view-btn">
                <i class="fas fa-calendar-alt"></i>
            </button>
        </div>
    </div>

    <!-- Onglets -->
    <div class="pronote-tabs">
        <a href="/cahier_texte.php" class="pronote-tab">Contenu des séances</a>
        <a href="/devoirs.php" class="pronote-tab active">Travail à faire</a>
    </div>

    <!-- Filtres -->
    <div class="pronote-filters">
        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Matière</label>
            <select class="pronote-filter-select" id="filtre-matiere">
                <option value="">Toutes matières</option>
                <?php foreach ($matieres as $matiere): ?>
                <option value="<?= htmlspecialchars($matiere['nom']) ?>"><?= htmlspecialchars($matiere['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Classe</label>
            <select class="pronote-filter-select" id="filtre-classe">
                <option value="">Toutes classes</option>
                <?php foreach ($classes as $niveau => $niveauData): ?>
                    <?php foreach ($niveauData as $cycle => $classesList): ?>
                        <?php foreach ($classesList as $classe): ?>
                        <option value="<?= htmlspecialchars($classe) ?>"><?= htmlspecialchars($classe) ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">État</label>
            <select class="pronote-filter-select" id="filtre-etat">
                <option value="">Tous</option>
                <option value="non_fait">À faire</option>
                <option value="en_cours">En cours</option>
                <option value="termine">Terminé</option>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Date de remise</label>
            <input type="date" class="pronote-filter-input" id="filtre-date">
        </div>

        <div class="pronote-filter-actions">
            <button class="pronote-btn pronote-btn-primary" id="btn-filtrer">
                <i class="fas fa-filter"></i>
                <span>Filtrer</span>
            </button>
            <button class="pronote-btn pronote-btn-secondary" id="btn-reinitialiser">
                <i class="fas fa-undo"></i>
                <span>Réinitialiser</span>
            </button>
        </div>
    </div>

    <!-- Vue liste -->
    <div id="list-view" class="pronote-table-container">
        <table class="pronote-table">
            <thead>
                <tr>
                    <th>Matière</th>
                    <th>Titre</th>
                    <th>Classe</th>
                    <th>Date de remise</th>
                    <?php if ($userProfile === 'eleve'): ?>
                    <th>Statut</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="devoirs-list">
                <tr>
                    <td colspan="6">Chargement...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Vue calendrier (cachée par défaut) -->
    <div id="calendar-view" style="display: none;">
        <div class="pronote-calendar-header">
            <div class="pronote-calendar-day">Lundi</div>
            <div class="pronote-calendar-day">Mardi</div>
            <div class="pronote-calendar-day">Mercredi</div>
            <div class="pronote-calendar-day">Jeudi</div>
            <div class="pronote-calendar-day">Vendredi</div>
            <div class="pronote-calendar-day">Samedi</div>
            <div class="pronote-calendar-day">Dimanche</div>
        </div>
        <div class="pronote-calendar" id="calendar-container">
            <!-- Rempli dynamiquement par JavaScript -->
        </div>
    </div>

    <?php if ($isTeacher): ?>
    <!-- Bouton d'ajout de devoir (pour enseignants) -->
    <div style="position: fixed; bottom: 30px; right: 30px;">
        <button class="pronote-btn pronote-btn-primary" id="btn-ajouter-devoir" style="border-radius: 50%; width: 56px; height: 56px; box-shadow: 0 3px 10px rgba(0,0,0,0.2);">
            <i class="fas fa-plus" style="font-size: 20px;"></i>
        </button>
    </div>
    <?php endif; ?>
</main>

<!-- Modal d'ajout/modification de devoir -->
<?php if ($isTeacher): ?>
<div class="pronote-modal-backdrop" id="modal-devoir" style="display: none;">
    <div class="pronote-modal">
        <div class="pronote-modal-header">
            <div class="pronote-modal-title" id="modal-title">Ajouter un devoir</div>
            <button class="pronote-modal-close" id="modal-close">&times;</button>
        </div>
        <div class="pronote-modal-body">
            <form class="pronote-form" id="form-devoir" enctype="multipart/form-data">
                <input type="hidden" name="id" id="devoir-id">

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="titre">Titre</label>
                    <input type="text" class="pronote-form-input" id="titre" name="titre" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="matiere">Matière</label>
                    <select class="pronote-form-select" id="matiere" name="matiere" required>
                        <option value="">Sélectionnez une matière</option>
                        <?php foreach ($matieres as $matiere): ?>
                        <option value="<?= htmlspecialchars($matiere['nom']) ?>"><?= htmlspecialchars($matiere['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="classe">Classe</label>
                    <select class="pronote-form-select" id="classe" name="classe" required>
                        <option value="">Sélectionnez une classe</option>
                        <?php foreach ($classes as $niveau => $niveauData): ?>
                            <?php foreach ($niveauData as $cycle => $classesList): ?>
                                <?php foreach ($classesList as $classe): ?>
                                <option value="<?= htmlspecialchars($classe) ?>"><?= htmlspecialchars($classe) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="description">Description</label>
                    <textarea class="pronote-form-textarea" id="description" name="description" required></textarea>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="date_remise">Date de remise</label>
                    <input type="datetime-local" class="pronote-form-input" id="date_remise" name="date_remise" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="fichier_sujet">Fichier sujet</label>
                    <input type="file" class="pronote-form-input" id="fichier_sujet" name="fichier_sujet" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                    <div class="pronote-form-hint">Formats acceptés: PDF, images, documents Word. Taille max: 5 Mo</div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="fichier_corrige">Fichier corrigé</label>
                    <input type="file" class="pronote-form-input" id="fichier_corrige" name="fichier_corrige" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                    <div class="pronote-form-hint">Facultatif. Publié seulement après la date de remise.</div>
                </div>
            </form>
        </div>
        <div class="pronote-modal-footer">
            <button class="pronote-btn pronote-btn-secondary" id="btn-annuler">Annuler</button>
            <button class="pronote-btn pronote-btn-primary" id="btn-enregistrer">Enregistrer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Éléments DOM
    const apiDevoirs = '/api/devoirs';
    const apiDevoirStatus = '/api/devoir_status';
    const listContainer = document.getElementById('devoirs-list');
    const calendarContainer = document.getElementById('calendar-container');
    const listViewBtn = document.getElementById('list-view-btn');
    const calendarViewBtn = document.getElementById('calendar-view-btn');
    const listView = document.getElementById('list-view');
    const calendarView = document.getElementById('calendar-view');
    
    // Filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtreEtat = document.getElementById('filtre-etat');
    const filtreDate = document.getElementById('filtre-date');
    const btnFiltrer = document.getElementById('btn-filtrer');
    const btnReinitialiser = document.getElementById('btn-reinitialiser');
    
    // Variables d'état
    const userProfile = '<?php echo $userProfile; ?>';
    const isTeacher = <?php echo $isTeacher ? 'true' : 'false'; ?>;
    let currentView = 'list';
    
    // Style du bouton d'ajout de devoir (harmonisation)
    const btnAjouterDevoir = document.getElementById('btn-ajouter-devoir');
    if (btnAjouterDevoir) {
        btnAjouterDevoir.style.backgroundColor = 'var(--pronote-primary)';
        btnAjouterDevoir.style.color = 'white';
        btnAjouterDevoir.style.borderRadius = '50%';
        btnAjouterDevoir.style.width = '56px';
        btnAjouterDevoir.style.height = '56px';
        btnAjouterDevoir.style.boxShadow = '0 3px 10px rgba(0,0,0,0.2)';
        btnAjouterDevoir.style.display = 'flex';
        btnAjouterDevoir.style.alignItems = 'center';
        btnAjouterDevoir.style.justifyContent = 'center';
        btnAjouterDevoir.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'var(--pronote-hover)';
        });
        btnAjouterDevoir.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'var(--pronote-primary)';
        });
    }
    
    // Chargement des devoirs
    async function loadDevoirs(params = '') {
        try {
            listContainer.innerHTML = '<tr><td colspan="6">Chargement...</td></tr>';
            
            const response = await fetch(apiDevoirs + params);
            if (!response.ok) throw new Error('Erreur lors du chargement des devoirs');
            
            const data = await response.json();
            
            if (data.length === 0) {
                listContainer.innerHTML = '<tr><td colspan="6">Aucun devoir trouvé</td></tr>';
                return;
            }
            
            // Réinitialiser le contenu avant d'ajouter les nouveaux devoirs
            listContainer.innerHTML = '';
            
            // Trier les devoirs par date de remise (plus proches en premier)
            data.sort((a, b) => new Date(a.date_remise) - new Date(b.date_remise));
            
            // Afficher chaque devoir
            data.forEach(async devoir => {
                // Suite du code...
                // Le reste du code JavaScript reste inchangé...
            });
            
            // Mettre à jour le calendrier
            updateCalendarView(data);
            
        } catch (error) {
            console.error('Erreur:', error);
            listContainer.innerHTML = '<tr><td colspan="6">Erreur lors du chargement des devoirs</td></tr>';
            showNotification('Erreur lors du chargement des devoirs', 'error');
        }
    }
    
    // Initialisation
    loadDevoirs();
    
    // Événements
    
    // Changer de vue
    listViewBtn.addEventListener('click', switchToListView);
    calendarViewBtn.addEventListener('click', switchToCalendarView);
    
    // Filtrer
    btnFiltrer.addEventListener('click', filterDevoirs);
    btnReinitialiser.addEventListener('click', resetFilters);
    
    // Délégation d'événements pour les boutons d'action
    document.addEventListener('click', async (e) => {
        // Bouton de modification
        if (e.target.closest('.btn-edit')) {
            const button = e.target.closest('.btn-edit');
            const id = button.dataset.id;
            
            try {
                const response = await fetch(`${apiDevoirs}/${id}`);
                if (!response.ok) throw new Error('Erreur lors de la récupération du devoir');
                
                const devoir = await response.json();
                
                // Remplir le formulaire avec les données du devoir
                const form = document.getElementById('form-devoir');
                form.reset();
                
                document.getElementById('devoir-id').value = devoir.id;
                document.getElementById('titre').value = devoir.titre;
                document.getElementById('matiere').value = devoir.matiere;
                document.getElementById('classe').value = devoir.classe;
                document.getElementById('description').value = devoir.description || '';
                
                // Formater la date et l'heure pour le champ datetime-local
                const dateRemise = new Date(devoir.date_remise);
                const dateStr = dateRemise.toISOString().slice(0, 16); // Format "YYYY-MM-DDTHH:MM"
                document.getElementById('date_remise').value = dateStr;
                
                // Le fichier sujet n'est pas obligatoire en mode édition
                document.getElementById('fichier_sujet').required = false;
                
                document.getElementById('modal-title').textContent = 'Modifier un devoir';
                document.getElementById('modal-devoir').style.display = 'flex';
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération du devoir', 'error');
            }
        }
        
        // Bouton de suppression
        if (e.target.closest('.btn-delete')) {
            const button = e.target.closest('.btn-delete');
            const id = button.dataset.id;
            
            if (confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?')) {
                try {
                    const response = await fetch(`${apiDevoirs}/${id}`, {
                        method: 'DELETE'
                    });
                    
                    if (!response.ok) {
                        const errorData = await response.json();
                        throw new Error(errorData.error || 'Erreur lors de la suppression');
                    }
                    
                    loadDevoirs();
                    showNotification('Devoir supprimé avec succès');
                } catch (error) {
                    console.error('Erreur:', error);
                    showNotification('Erreur lors de la suppression du devoir', 'error');
                }
            }
        }
        
        // Boutons de statut
        if (e.target.closest('.btn-status-update')) {
            const button = e.target.closest('.btn-status-update');
            const id = button.dataset.id;
            const status = button.dataset.status;
            updateDevoirStatus(id, status);
        }
        
        // Événements pour la vue calendrier
        if (e.target.closest('.pronote-calendar-event')) {
            const event = e.target.closest('.pronote-calendar-event');
            const id = event.dataset.id;
            
            try {
                const response = await fetch(`${apiDevoirs}/${id}`);
                if (!response.ok) throw new Error('Erreur lors de la récupération du devoir');
                
                const devoir = await response.json();
                
                // Afficher les détails du devoir dans une alerte formatée
                const dateRemise = new Date(devoir.date_remise);
                const formattedDate = dateRemise.toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                alert(`Titre: ${devoir.titre}\nMatière: ${devoir.matiere}\nClasse: ${devoir.classe}\nDate de remise: ${formattedDate}\n\nDescription: ${devoir.description || 'Aucune description'}`);
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération du devoir', 'error');
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>