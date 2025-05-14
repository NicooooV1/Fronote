<?php
// cahier_texte.php - Page principale du cahier de texte
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
$pageTitle = 'Cahier de texte';
$isTeacher = isTeacher();  // Assurez-vous que cette fonction est définie dans config.php
$userProfile = getUserProfile();  // Assurez-vous que cette fonction est définie dans config.php

// Constante pour les includes
define('INCLUDED', true);
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Contenu principal -->
<main class="pronote-main">
    <div class="pronote-page-title">
        <span>Cahier de texte - Contenu des séances</span>
        <div class="pronote-view-toggle">
            <button class="pronote-view-btn active" id="list-view-btn">
                <i class="fas fa-list"></i>
            </button>
            <button class="pronote-view-btn" id="week-view-btn">
                <i class="fas fa-calendar-week"></i>
            </button>
        </div>
    </div>

    <!-- Onglets -->
    <div class="pronote-tabs">
        <a href="cahier_texte.php" class="pronote-tab active">Contenu des séances</a>
        <a href="devoirs.php" class="pronote-tab">Travail à faire</a>
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
            <label class="pronote-filter-label">Période</label>
            <select class="pronote-filter-select" id="filtre-periode">
                <option value="semaine">Cette semaine</option>
                <option value="mois">Ce mois</option>
                <option value="trimestre">Ce trimestre</option>
                <option value="custom">Personnalisée</option>
            </select>
        </div>

        <div class="pronote-filter-group" id="date-filter-container" style="display: none;">
            <label class="pronote-filter-label">Date</label>
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
    <div id="list-view">
        <!-- Séances chargées dynamiquement -->
        <div class="loading">Chargement des séances...</div>
    </div>

    <!-- Vue semaine (cachée par défaut) -->
    <div id="week-view" style="display: none;">
        <div class="pronote-week-view">
            <!-- Colonne des horaires -->
            <div class="pronote-time-slots">
                <div style="height: 50px;"></div> <!-- Espace pour l'en-tête des jours -->
                <div class="pronote-time-slot">8h-9h</div>
                <div class="pronote-time-slot">9h-10h</div>
                <div class="pronote-time-slot">10h-11h</div>
                <div class="pronote-time-slot">11h-12h</div>
                <div class="pronote-time-slot">13h-14h</div>
                <div class="pronote-time-slot">14h-15h</div>
                <div class="pronote-time-slot">15h-16h</div>
                <div class="pronote-time-slot">16h-17h</div>
                <div class="pronote-time-slot">17h-18h</div>
            </div>

            <!-- Jours de la semaine (remplis dynamiquement) -->
            <div id="week-container">
                <!-- Colonnes des jours chargées dynamiquement -->
            </div>
        </div>
    </div>

    <?php if ($isTeacher): ?>
    <!-- Bouton d'ajout de séance (pour enseignants) -->
    <div style="position: fixed; bottom: 30px; right: 30px;">
        <button class="pronote-btn pronote-btn-primary" id="btn-ajouter-seance" style="border-radius: 50%; width: 56px; height: 56px; box-shadow: 0 3px 10px rgba(0,0,0,0.2);">
            <i class="fas fa-plus" style="font-size: 20px;"></i>
        </button>
    </div>
    <?php endif; ?>
</main>

<?php if ($isTeacher): ?>
<!-- Modal d'ajout/modification de séance -->
<div class="pronote-modal-backdrop" id="modal-seance" style="display: none;">
    <div class="pronote-modal">
        <div class="pronote-modal-header">
            <div class="pronote-modal-title" id="modal-title">Ajouter une séance</div>
            <button class="pronote-modal-close" id="modal-close">&times;</button>
        </div>
        <div class="pronote-modal-body">
            <form class="pronote-form" id="form-seance">
                <input type="hidden" name="id" id="seance-id">

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
                    <label class="pronote-form-label pronote-form-required" for="date_cours">Date du cours</label>
                    <input type="date" class="pronote-form-input" id="date_cours" name="date_cours" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="horaire">Horaire</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="time" class="pronote-form-input" id="heure_debut" name="heure_debut" placeholder="Début">
                        <input type="time" class="pronote-form-input" id="heure_fin" name="heure_fin" placeholder="Fin">
                    </div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="titre">Titre de la séance</label>
                    <input type="text" class="pronote-form-input" id="titre" name="titre" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="contenu">Contenu de la séance</label>
                    <textarea class="pronote-form-textarea" id="contenu" name="contenu" required></textarea>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="documents">Documents joints</label>
                    <input type="file" class="pronote-form-input" id="documents" name="documents[]" multiple>
                    <div class="pronote-form-hint">Formats acceptés: PDF, Word, PowerPoint, images. Taille max: 10 Mo</div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label">Devoirs associés</label>
                    <div id="devoirs-container">
                        <!-- Liste des devoirs chargée dynamiquement -->
                        <div class="loading">Sélectionnez une classe et une matière pour voir les devoirs disponibles</div>
                    </div>
                    <div class="pronote-form-hint">Sélectionnez les devoirs associés à cette séance.</div>
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
    const apiCahierTexte = 'api/cahier_texte'; // Chemin modifié pour être relatif
    const apiDevoirs = 'api/devoirs'; // Chemin modifié pour être relatif
    const listView = document.getElementById('list-view');
    const weekView = document.getElementById('week-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const weekViewBtn = document.getElementById('week-view-btn');
    const weekContainer = document.getElementById('week-container');
    
    // Filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtrePeriode = document.getElementById('filtre-periode');
    const filtreDate = document.getElementById('filtre-date');
    const dateFilterContainer = document.getElementById('date-filter-container');
    const btnFiltrer = document.getElementById('btn-filtrer');
    const btnReinitialiser = document.getElementById('btn-reinitialiser');
    
    // Variables d'état
    const userProfile = '<?php echo $userProfile; ?>';
    const isTeacher = <?php echo $isTeacher ? 'true' : 'false'; ?>;
    let currentView = 'list';
    
    // Style du bouton d'ajout de séance (harmonisation)
    const btnAjouterSeance = document.getElementById('btn-ajouter-seance');
    if (btnAjouterSeance) {
        btnAjouterSeance.style.backgroundColor = 'var(--pronote-primary)';
        btnAjouterSeance.style.color = 'white';
        btnAjouterSeance.style.borderRadius = '50%';
        btnAjouterSeance.style.width = '56px';
        btnAjouterSeance.style.height = '56px';
        btnAjouterSeance.style.boxShadow = '0 3px 10px rgba(0,0,0,0.2)';
        btnAjouterSeance.style.display = 'flex';
        btnAjouterSeance.style.alignItems = 'center';
        btnAjouterSeance.style.justifyContent = 'center';
        btnAjouterSeance.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'var(--pronote-hover)';
        });
        btnAjouterSeance.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'var(--pronote-primary)';
        });
    }
    
    // Fonction pour gérer les erreurs de fetch
    async function handleResponse(response) {
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({
                error: `Erreur ${response.status}: ${response.statusText}`
            }));
            throw new Error(errorData.error || `Erreur ${response.status}: ${response.statusText}`);
        }
        return await response.json();
    }
    
    // Chargement du cahier de texte
    async function loadCahierTexte(params = '') {
        try {
            listView.innerHTML = '<div class="loading">Chargement...</div>';
            
            const response = await fetch(apiCahierTexte + params);
            let data;
            
            try {
                data = await handleResponse(response);
            } catch (error) {
                console.error('Erreur API:', error);
                listView.innerHTML = `<p>Erreur lors du chargement du cahier de texte: ${error.message}</p>`;
                showNotification(`Erreur lors du chargement du cahier de texte: ${error.message}`, 'error');
                return;
            }
            
            if (data.length === 0) {
                listView.innerHTML = '<p>Aucune séance trouvée dans le cahier de texte.</p>';
                return;
            }
            
            // Réinitialiser le contenu avant d'ajouter les nouvelles séances
            listView.innerHTML = '';
            
            // Trier les séances par date (plus récentes en premier)
            data.sort((a, b) => new Date(b.date_cours) - new Date(a.date_cours));
            
            // Afficher chaque séance
            data.forEach(async seance => {
                // Création de la carte pour la séance
                const div = document.createElement('div');
                div.className = 'pronote-lesson';
                
                try {
                    // Formater la date
                    const dateCours = new Date(seance.date_cours);
                    const options = { 
                        weekday: 'long', 
                        day: 'numeric', 
                        month: 'long', 
                        year: 'numeric'
                    };
                    const formattedDate = dateCours.toLocaleDateString('fr-FR', options);
                    
                    // Horaire (si disponible)
                    let horaireText = '';
                    if (seance.heure_debut && seance.heure_fin) {
                        horaireText = ` (${seance.heure_debut}-${seance.heure_fin})`;
                    }
                    
                    // Boutons d'action pour les enseignants
                    let actionButtons = '';
                    if (isTeacher) {
                        actionButtons = `
                            <button class="pronote-btn pronote-btn-small pronote-btn-secondary btn-edit" data-id="${seance.id}">
                                <i class="fas fa-edit"></i>
                                <span>Modifier</span>
                            </button>
                            <button class="pronote-btn pronote-btn-small btn-delete" style="background-color: #f44336; color: white;" data-id="${seance.id}">
                                <i class="fas fa-trash"></i>
                                <span>Supprimer</span>
                            </button>
                        `;
                    }
                    
                    // Documents joints
                    let documentsHTML = '';
                    if (seance.documents) {
                        documentsHTML = '<div class="pronote-lesson-files"><h4>Documents joints :</h4>';
                        
                        let docs = [];
                        if (typeof seance.documents === 'string') {
                            try {
                                // Essayer de parser le JSON si c'est une chaîne
                                docs = JSON.parse(seance.documents);
                            } catch (e) {
                                // Si ce n'est pas du JSON valide, considérer comme une liste séparée par des virgules
                                docs = seance.documents.split(',').map(doc => doc.trim());
                            }
                        } else if (Array.isArray(seance.documents)) {
                            docs = seance.documents;
                        }
                        
                        docs.forEach(doc => {
                            // Déterminer le type d'icône en fonction de l'extension
                            let iconClass = 'fa-file';
                            const ext = (typeof doc === 'string' ? doc.split('.').pop().toLowerCase() : '');
                            
                            if (['pdf'].includes(ext)) iconClass = 'fa-file-pdf';
                            else if (['doc', 'docx'].includes(ext)) iconClass = 'fa-file-word';
                            else if (['ppt', 'pptx'].includes(ext)) iconClass = 'fa-file-powerpoint';
                            else if (['xls', 'xlsx'].includes(ext)) iconClass = 'fa-file-excel';
                            else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) iconClass = 'fa-file-image';
                            
                            documentsHTML += `
                                <a href="${doc}" class="pronote-file-link" target="_blank">
                                    <i class="fas ${iconClass}"></i>
                                    <span>${doc.split('/').pop()}</span>
                                </a>
                            `;
                        });
                        
                        documentsHTML += '</div>';
                    }
                    
                    // Devoirs associés
                    let devoirsHTML = '';
                    
                    // Récupérer les devoirs associés à cette séance
                    if (seance.id) {
                        try {
                            const devoirsResponse = await fetch(`${apiDevoirs}?id_cahier_texte=${seance.id}`);
                            const devoirs = await handleResponse(devoirsResponse);
                            
                            if (devoirs.length > 0) {
                                devoirsHTML = '<div class="pronote-homework-section"><h4 class="pronote-homework-header">Travail à faire pour la prochaine séance :</h4>';
                                
                                devoirs.forEach(devoir => {
                                    const dateRemise = new Date(devoir.date_remise);
                                    const dateOptions = { day: 'numeric', month: 'long', year: 'numeric' };
                                    const formattedRemiseDate = dateRemise.toLocaleDateString('fr-FR', dateOptions);
                                    
                                    devoirsHTML += `
                                        <div class="pronote-homework-item">
                                            <input type="checkbox" class="pronote-checkbox" id="hw-${devoir.id}">
                                            <label for="hw-${devoir.id}">${devoir.titre} (à rendre le ${formattedRemiseDate})</label>
                                        </div>
                                    `;
                                });
                                
                                devoirsHTML += '</div>';
                            }
                        } catch (error) {
                            console.error('Erreur récupération devoirs:', error);
                        }
                    }
                    
                    // Assembler la carte
                    div.innerHTML = `
                        <div class="pronote-lesson-header">
                            <div>
                                <div class="pronote-lesson-subject">${seance.matiere}</div>
                                <div class="pronote-lesson-meta">
                                    <span>${seance.classe}</span>
                                    <span>${formattedDate}${horaireText}</span>
                                </div>
                            </div>
                            <div>
                                ${actionButtons}
                            </div>
                        </div>
                        <div class="pronote-lesson-content">
                            ${seance.contenu}
                        </div>
                        ${documentsHTML}
                        ${devoirsHTML}
                    `;
                    
                    listView.appendChild(div);
                } catch (error) {
                    console.error('Erreur lors du rendu de la séance:', error);
                    div.innerHTML = `<div class="pronote-lesson-header">
                        <div>Erreur d'affichage de la séance</div>
                    </div>`;
                    listView.appendChild(div);
                }
            });
            
            // Mettre à jour la vue semaine
            updateWeekView(data);
            
        } catch (error) {
            console.error('Erreur générale:', error);
            listView.innerHTML = `<p>Erreur lors du chargement du cahier de texte: ${error.message}</p>`;
            showNotification(`Erreur lors du chargement du cahier de texte: ${error.message}`, 'error');
        }
    }
    
    // Fonction de mise à jour de la vue semaine
    function updateWeekView(data) {
        // Implémentation de la mise à jour de vue semaine
        // Code à compléter selon vos besoins
    }
    
    // Changer de vue liste/semaine
    listViewBtn.addEventListener('click', () => {
        listViewBtn.classList.add('active');
        weekViewBtn.classList.remove('active');
        listView.style.display = 'block';
        weekView.style.display = 'none';
        currentView = 'list';
    });
    
    weekViewBtn.addEventListener('click', () => {
        weekViewBtn.classList.add('active');
        listViewBtn.classList.remove('active');
        weekView.style.display = 'block';
        listView.style.display = 'none';
        currentView = 'week';
    });
    
    // Filtres
    filtrePeriode.addEventListener('change', function() {
        if (this.value === 'custom') {
            dateFilterContainer.style.display = 'flex';
        } else {
            dateFilterContainer.style.display = 'none';
        }
    });
    
    btnFiltrer.addEventListener('click', function() {
        const params = [];
        
        if (filtreMatiere.value) {
            params.push(`matiere=${encodeURIComponent(filtreMatiere.value)}`);
        }
        
        if (filtreClasse.value) {
            params.push(`classe=${encodeURIComponent(filtreClasse.value)}`);
        }
        
        // Gestion de la période
        const now = new Date();
        let dateDebut, dateFin;
        
        switch (filtrePeriode.value) {
            case 'semaine':
                // Début de la semaine (lundi)
                dateDebut = new Date(now);
                dateDebut.setDate(now.getDate() - now.getDay() + 1);
                params.push(`date_debut=${dateDebut.toISOString().split('T')[0]}`);
                
                // Fin de la semaine (dimanche)
                dateFin = new Date(dateDebut);
                dateFin.setDate(dateDebut.getDate() + 6);
                params.push(`date_fin=${dateFin.toISOString().split('T')[0]}`);
                break;
                
            case 'mois':
                // Début du mois
                dateDebut = new Date(now.getFullYear(), now.getMonth(), 1);
                params.push(`date_debut=${dateDebut.toISOString().split('T')[0]}`);
                
                // Fin du mois
                dateFin = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                params.push(`date_fin=${dateFin.toISOString().split('T')[0]}`);
                break;
                
            case 'trimestre':
                // Début du trimestre
                const trimestre = Math.floor(now.getMonth() / 3);
                dateDebut = new Date(now.getFullYear(), trimestre * 3, 1);
                params.push(`date_debut=${dateDebut.toISOString().split('T')[0]}`);
                
                // Fin du trimestre
                dateFin = new Date(now.getFullYear(), (trimestre + 1) * 3, 0);
                params.push(`date_fin=${dateFin.toISOString().split('T')[0]}`);
                break;
                
            case 'custom':
                if (filtreDate.value) {
                    params.push(`date_cours=${filtreDate.value}`);
                }
                break;
        }
        
        loadCahierTexte(params.length ? `?${params.join('&')}` : '');
    });
    
    btnReinitialiser.addEventListener('click', function() {
        filtreMatiere.value = '';
        filtreClasse.value = '';
        filtrePeriode.value = 'semaine';
        filtreDate.value = '';
        dateFilterContainer.style.display = 'none';
        
        loadCahierTexte();
    });
    
    // Initialisation
    loadCahierTexte();
    
    // Autres fonctions et événements...
    // ... (le code complet)
    
});
</script>

<?php include 'includes/footer.php'; ?>